<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domains;

use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Domain\Domains\DomainContact;
use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Domains\DomainStateMachine;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class DomainLifecycleAndContactTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private DomainCatalogService $catalogService;
    private DomainService $domainService;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->catalogService = new DomainCatalogService($this->db);
        $this->catalogService->ensureTables();

        $this->domainService = new DomainService($this->db, $this->catalogService);
        $this->domainService->ensureTables();

        // Seed basic TLDs for testing
        $this->catalogService->registerTld([
            'extension' => '.com',
            'min_years' => 1,
            'max_years' => 10,
        ]);
        $this->catalogService->registerTld([
            'extension' => '.net',
            'min_years' => 1,
            'max_years' => 10,
        ]);
    }

    public function testDomainStateMachineTransitions(): void
    {
        // Valid transitions
        $this->assertTrue(DomainStateMachine::canTransition(DomainStateMachine::STATUS_PENDING_REGISTRATION, DomainStateMachine::STATUS_ACTIVE));
        $this->assertTrue(DomainStateMachine::canTransition(DomainStateMachine::STATUS_ACTIVE, DomainStateMachine::STATUS_EXPIRED));
        $this->assertTrue(DomainStateMachine::canTransition(DomainStateMachine::STATUS_EXPIRED, DomainStateMachine::STATUS_GRACE));
        $this->assertTrue(DomainStateMachine::canTransition(DomainStateMachine::STATUS_GRACE, DomainStateMachine::STATUS_ACTIVE)); // Renewed in grace
        $this->assertTrue(DomainStateMachine::canTransition(DomainStateMachine::STATUS_GRACE, DomainStateMachine::STATUS_REDEMPTION));
        $this->assertTrue(DomainStateMachine::canTransition(DomainStateMachine::STATUS_REDEMPTION, DomainStateMachine::STATUS_ACTIVE)); // Restored in redemption

        // Invalid transitions
        $this->assertFalse(DomainStateMachine::canTransition(DomainStateMachine::STATUS_CANCELLED, DomainStateMachine::STATUS_ACTIVE));
        $this->assertFalse(DomainStateMachine::canTransition(DomainStateMachine::STATUS_TRANSFERRED_OUT, DomainStateMachine::STATUS_ACTIVE));
        $this->assertFalse(DomainStateMachine::canTransition(DomainStateMachine::STATUS_PENDING_REGISTRATION, DomainStateMachine::STATUS_REDEMPTION));

        // State machine classification helpers
        $this->assertTrue(DomainStateMachine::isOperable(DomainStateMachine::STATUS_ACTIVE));
        $this->assertTrue(DomainStateMachine::isOperable(DomainStateMachine::STATUS_GRACE));
        $this->assertFalse(DomainStateMachine::isOperable(DomainStateMachine::STATUS_EXPIRED));

        $this->assertTrue(DomainStateMachine::isPending(DomainStateMachine::STATUS_PENDING_REGISTRATION));
        $this->assertTrue(DomainStateMachine::isPending(DomainStateMachine::STATUS_PENDING_TRANSFER));

        $this->assertTrue(DomainStateMachine::isTerminal(DomainStateMachine::STATUS_CANCELLED));
        $this->assertTrue(DomainStateMachine::isTerminal(DomainStateMachine::STATUS_TRANSFERRED_OUT));

        // AssertCanTransition throws on invalid
        $this->expectException(ValidationException::class);
        DomainStateMachine::assertCanTransition(DomainStateMachine::STATUS_CANCELLED, DomainStateMachine::STATUS_ACTIVE);
    }

    public function testDomainCreationWithValidationAndDuplicatePrevention(): void
    {
        $domain = $this->domainService->createDomain([
            'user_id' => 10,
            'organization_id' => 1,
            'domain' => 'mycompany.com',
            'registration_period_years' => 2,
            'nameservers' => ['ns1.mycompany.com', 'ns2.mycompany.com'],
            'contact' => [
                'first_name' => 'Alice',
                'last_name' => 'Smith',
                'email' => 'alice@mycompany.com',
                'phone' => '+1.5551234567',
                'address_line_1' => '123 Tech Blvd',
                'city' => 'Austin',
                'postal_code' => '78701',
                'country_code' => 'US',
            ],
        ]);

        $this->assertSame('mycompany.com', $domain->getDomain());
        $this->assertSame('mycompany', $domain->getSld());
        $this->assertSame('.com', $domain->getTld());
        $this->assertSame(DomainStateMachine::STATUS_PENDING_REGISTRATION, $domain->getStatus());
        $this->assertSame(2, $domain->getRegistrationPeriodYears());
        $this->assertCount(2, $domain->getNameservers());

        // Assert registrant contact was created automatically
        $contact = $this->domainService->getContact($domain->getId(), DomainContact::TYPE_REGISTRANT);
        $this->assertNotNull($contact);
        $this->assertSame('Alice Smith', $contact->getFullName());
        $this->assertSame('alice@mycompany.com', $contact->getEmail());

        // Assert timeline creation event was recorded
        $timeline = $this->domainService->getTimeline($domain->getId());
        $this->assertNotEmpty($timeline);

        // Duplicate domain prevention
        $this->expectException(ValidationException::class);
        $this->domainService->createDomain([
            'user_id' => 11,
            'domain' => 'mycompany.com',
        ]);
    }

    public function testDomainActivationAndRenewalLifecycle(): void
    {
        $domain = $this->domainService->createDomain([
            'user_id' => 20,
            'domain' => 'startup-hub.com',
            'registration_period_years' => 1,
        ]);
        $domainId = $domain->getId();

        // 1. Activate domain
        $activated = $this->domainService->activateDomain(
            id: $domainId,
            registrationDate: '2026-10-01',
            expiryDate: '2027-10-01'
        );

        $this->assertSame(DomainStateMachine::STATUS_ACTIVE, $activated->getStatus());
        $this->assertSame('2026-10-01', $activated->getRegistrationDate());
        $this->assertSame('2027-10-01', $activated->getExpiryDate());
        $this->assertSame('2027-10-01', $activated->getNextDueDate());
        $this->assertFalse($activated->isExpired('2026-10-05'));
        $this->assertSame(361, $activated->daysUntilExpiry('2026-10-05'));

        // 2. Renew domain for 2 additional years
        $renewed = $this->domainService->renewDomain($domainId, 2);
        $this->assertSame('2029-10-01', $renewed->getExpiryDate());
        $this->assertSame('2029-10-01', $renewed->getNextDueDate());
        $this->assertSame(DomainStateMachine::STATUS_ACTIVE, $renewed->getStatus());

        // 3. Verify timeline contains activation and renewal events
        $timeline = $this->domainService->getTimeline($domainId);
        $eventTypes = array_map(fn ($e) => $e->getEventType(), $timeline);
        $this->assertContains('activated', $eventTypes);
        $this->assertContains('renewed', $eventTypes);
    }

    public function testDomainExpirationGraceAndRedemptionTransitions(): void
    {
        $domain = $this->domainService->createDomain([
            'user_id' => 30,
            'domain' => 'expiring-site.net',
        ]);
        $domainId = $domain->getId();

        $this->domainService->activateDomain($domainId, '2025-10-01', '2026-10-01');

        // Transition: active -> expired
        $expired = $this->domainService->expireDomain($domainId);
        $this->assertSame(DomainStateMachine::STATUS_EXPIRED, $expired->getStatus());

        // Transition: expired -> grace
        $grace = $this->domainService->transitionStatus($domainId, DomainStateMachine::STATUS_GRACE, 'Entered 30-day grace period');
        $this->assertSame(DomainStateMachine::STATUS_GRACE, $grace->getStatus());
        $this->assertTrue($grace->isOperable());

        // Transition: grace -> redemption
        $redemption = $this->domainService->transitionStatus($domainId, DomainStateMachine::STATUS_REDEMPTION, 'Grace expired, redemption period');
        $this->assertSame(DomainStateMachine::STATUS_REDEMPTION, $redemption->getStatus());
        $this->assertFalse($redemption->isOperable());

        // Transition: redemption -> cancelled
        $cancelled = $this->domainService->transitionStatus($domainId, DomainStateMachine::STATUS_CANCELLED, 'Redemption lapsed without restore');
        $this->assertSame(DomainStateMachine::STATUS_CANCELLED, $cancelled->getStatus());

        $timeline = $this->domainService->getTimeline($domainId);
        $this->assertCount(6, $timeline); // created, activated, expired, grace, redemption, cancelled
    }

    public function testContactProfilesUpsertAndRetrieval(): void
    {
        $domain = $this->domainService->createDomain([
            'user_id' => 40,
            'domain' => 'contacts-test.com',
        ]);
        $domainId = $domain->getId();

        // 1. Set Registrant Contact
        $this->domainService->setContact($domainId, DomainContact::TYPE_REGISTRANT, [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'company_name' => 'Doe Enterprises',
            'email' => 'john@doe.com',
            'phone' => '+1.5559876543',
            'address_line_1' => '456 Main St',
            'city' => 'Chicago',
            'state' => 'IL',
            'postal_code' => '60601',
            'country_code' => 'US',
        ]);

        // 2. Set Admin Contact
        $this->domainService->setContact($domainId, DomainContact::TYPE_ADMIN, [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@doe.com',
            'phone' => '+1.5559876544',
            'address_line_1' => '456 Main St',
            'city' => 'Chicago',
            'postal_code' => '60601',
            'country_code' => 'US',
        ]);

        // 3. Set Tech Contact
        $this->domainService->setContact($domainId, DomainContact::TYPE_TECH, [
            'first_name' => 'Tech',
            'last_name' => 'Ops',
            'email' => 'tech@doe.com',
            'phone' => '+1.5559876545',
            'address_line_1' => '456 Main St',
            'city' => 'Chicago',
            'postal_code' => '60601',
            'country_code' => 'US',
        ]);

        // 4. Update (upsert) Registrant Contact with new address
        $updatedReg = $this->domainService->setContact($domainId, DomainContact::TYPE_REGISTRANT, [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'company_name' => 'Doe Global Corp',
            'email' => 'john.new@doe.com',
            'phone' => '+1.5559876543',
            'address_line_1' => '789 New Michigan Ave',
            'city' => 'Chicago',
            'state' => 'IL',
            'postal_code' => '60602',
            'country_code' => 'US',
        ]);

        $this->assertSame('Doe Global Corp', $updatedReg->getCompanyName());
        $this->assertSame('john.new@doe.com', $updatedReg->getEmail());
        $this->assertSame('789 New Michigan Ave', $updatedReg->getAddressLine1());

        // 5. Retrieve all contacts
        $allContacts = $this->domainService->getAllContacts($domainId);
        $this->assertCount(3, $allContacts);
        $this->assertArrayHasKey(DomainContact::TYPE_REGISTRANT, $allContacts);
        $this->assertArrayHasKey(DomainContact::TYPE_ADMIN, $allContacts);
        $this->assertArrayHasKey(DomainContact::TYPE_TECH, $allContacts);

        // Invalid contact type throws
        $this->expectException(ValidationException::class);
        $this->domainService->setContact($domainId, 'invalid_type', [
            'first_name' => 'Bad',
            'last_name' => 'Type',
            'email' => 'bad@type.com',
            'phone' => '+1.1111111111',
            'address_line_1' => 'No where',
            'city' => 'City',
            'postal_code' => '00000',
            'country_code' => 'US',
        ]);
    }

    public function testDomainAdministrativeOperations(): void
    {
        $domain = $this->domainService->createDomain([
            'user_id' => 50,
            'domain' => 'admin-operations.com',
        ]);
        $domainId = $domain->getId();

        // 1. Update Nameservers
        $withNs = $this->domainService->updateNameservers($domainId, [
            'ns1.cloudflare.com',
            'ns2.cloudflare.com',
        ]);
        $this->assertSame(['ns1.cloudflare.com', 'ns2.cloudflare.com'], $withNs->getNameservers());

        // 2. Lock / Unlock
        $unlocked = $this->domainService->setRegistrarLock($domainId, false);
        $this->assertFalse($unlocked->isLocked());

        $locked = $this->domainService->setRegistrarLock($domainId, true);
        $this->assertTrue($locked->isLocked());

        // 3. WHOIS Privacy
        $withPrivacy = $this->domainService->setWhoisPrivacy($domainId, true);
        $this->assertTrue($withPrivacy->isWhoisPrivacy());

        // 4. Auto Renew
        $noAutoRenew = $this->domainService->setAutoRenew($domainId, false);
        $this->assertFalse($noAutoRenew->isAutoRenew());

        // 5. EPP Code
        $withEpp = $this->domainService->setEppCode($domainId, 'SecretAuthCode123!');
        $this->assertSame('SecretAuthCode123!', $withEpp->getEppCode());

        // 6. Query Timeline
        $timeline = $this->domainService->getTimeline($domainId);
        $types = array_map(fn ($e) => $e->getEventType(), $timeline);

        $this->assertContains('nameservers_updated', $types);
        $this->assertContains('unlocked', $types);
        $this->assertContains('locked', $types);
        $this->assertContains('whois_privacy_toggled', $types);
        $this->assertContains('auto_renew_toggled', $types);
        $this->assertContains('epp_code_updated', $types);
    }
}
