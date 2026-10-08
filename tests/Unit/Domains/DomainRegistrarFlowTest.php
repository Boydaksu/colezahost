<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domains;

use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Domain\Domains\Catalog\Tld;
use Coleza\Domain\Domains\Catalog\TldPolicy;
use Coleza\Domain\Domains\Catalog\TldPricing;
use Coleza\Domain\Domains\Domain;
use Coleza\Domain\Domains\DomainContact;
use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Domains\DomainStateMachine;
use Coleza\Domain\Domains\Registrar\Adapters\NameSilo\NameSiloRegistrarAdapter;
use Coleza\Domain\Domains\Registrar\DomainAvailabilityResult;
use Coleza\Domain\Domains\Registrar\DomainRegistrationCommand;
use Coleza\Domain\Domains\Registrar\DomainRenewalCommand;
use Coleza\Domain\Domains\Registrar\DomainRegistrarService;
use Coleza\Domain\Domains\Registrar\DomainTransferCommand;
use Coleza\Domain\Domains\Registrar\RegistrarCapability;
use Coleza\Domain\Domains\Registrar\RegistrarOperationResult;
use Coleza\Domain\Domains\Registrar\RegistrarProviderInterface;
use Coleza\Domain\Domains\Registrar\RegistrarRegistry;
use Coleza\Domain\Domains\Registrar\Vault\RegistrarConfiguration;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class DomainRegistrarFlowTest extends TestCase
{
    private Connection $db;
    private DomainService $domainService;
    private DomainCatalogService $catalogService;
    private RegistrarRegistry $registry;
    private DomainRegistrarService $flowService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->catalogService = new DomainCatalogService($this->db);
        $this->catalogService->ensureTables();

        $this->domainService = new DomainService($this->db, $this->catalogService);
        $this->domainService->ensureTables();

        $this->registry = new RegistrarRegistry();

        $tld = $this->catalogService->registerTld([
            'extension' => '.com',
            'is_active' => 1,
            'registrar_id' => 'mock_reg',
            'min_years' => 1,
            'max_years' => 10,
        ]);

        $this->catalogService->setTldPricing(
            tldId: $tld->getId(),
            operation: TldPricing::OPERATION_REGISTER,
            years: 1,
            priceMinor: 1299,
            currencyCode: 'USD'
        );

        $this->flowService = new DomainRegistrarService(
            $this->domainService,
            $this->registry,
            $this->catalogService
        );
    }

    public function testCheckAvailabilityWithCatalogAndPricing(): void
    {
        $mockRegistrar = $this->createMockRegistrar('mock_reg', [
            'available.com' => true,
            'taken.com' => false,
        ]);
        $this->registry->register($mockRegistrar);

        // 1. Available domain: checks catalog + registrar + prices from catalog
        $res = $this->flowService->checkAvailability('available.com');
        $this->assertTrue($res->isAvailable());
        $this->assertSame('available.com', $res->getDomain());
        $this->assertSame(12.99, $res->getPrice()); // 1299 minor -> 12.99
        $this->assertSame('USD', $res->getCurrency());

        // 2. Taken domain
        $resTaken = $this->flowService->checkAvailability('taken.com');
        $this->assertFalse($resTaken->isAvailable());

        // 3. Invalid syntax rejected by catalog without querying registrar
        $resInvalid = $this->flowService->checkAvailability('bad..domain.com');
        $this->assertFalse($resInvalid->isAvailable());
        $this->assertStringContainsStringIgnoringCase('invalid', (string) $resInvalid->getReason());

        // 4. Unsupported TLD rejected
        $resUnsupported = $this->flowService->checkAvailability('unsupported.xyz');
        $this->assertFalse($resUnsupported->isAvailable());
        $this->assertStringContainsStringIgnoringCase('unsupported', (string) $resUnsupported->getReason());

        // 5. Bulk check
        $bulk = $this->flowService->bulkCheckAvailability(['available.com', 'taken.com']);
        $this->assertCount(2, $bulk);
        $this->assertTrue($bulk['available.com']->isAvailable());
        $this->assertFalse($bulk['taken.com']->isAvailable());
    }

    public function testRegisterDomainFlowSuccess(): void
    {
        $capturedCommand = null;
        $mockRegistrar = $this->createMockRegistrar('mock_reg', onRegister: function ($cmd) use (&$capturedCommand) {
            $capturedCommand = $cmd;
            return RegistrarOperationResult::success(
                operation: 'register',
                domain: $cmd->getDomain(),
                remoteTransactionId: 'REG-TXN-12345',
                expirationDate: date('Y-m-d', strtotime('+2 years'))
            );
        });
        $this->registry->register($mockRegistrar);

        // 1. Create domain in pending_registration status
        $domain = $this->domainService->createDomain([
            'user_id' => 10,
            'domain' => 'colezacloud.com',
            'registration_period_years' => 2,
            'whois_privacy' => true,
            'nameservers' => ['ns1.colezacloud.com', 'ns2.colezacloud.com'],
            'registrar_id' => 'mock_reg',
        ]);

        $this->assertSame(DomainStateMachine::STATUS_PENDING_REGISTRATION, $domain->getStatus());

        // Add registrant contact
        $this->domainService->setContact(
            domainId: $domain->getId(),
            contactType: DomainContact::TYPE_REGISTRANT,
            data: [
                'first_name' => 'Sarah',
                'last_name' => 'Connor',
                'company_name' => 'Cyberdyne Resistance',
                'email' => 'sarah@cyberdyne.org',
                'phone' => '+1.5559998877',
                'address_line_1' => '123 Sky Way',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postal_code' => '90001',
                'country_code' => 'US',
            ]
        );

        // 2. Execute registration flow
        $result = $this->flowService->registerDomain($domain->getId(), actorType: 'user', actorId: 10);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('REG-TXN-12345', $result->getRemoteTransactionId());

        // 3. Verify domain is now active with updated expiration date
        $freshDomain = $this->domainService->findDomainById($domain->getId());
        $this->assertNotNull($freshDomain);
        $this->assertSame(DomainStateMachine::STATUS_ACTIVE, $freshDomain->getStatus());
        $this->assertSame(date('Y-m-d'), $freshDomain->getRegistrationDate());
        $this->assertSame(date('Y-m-d', strtotime('+2 years')), $freshDomain->getExpiryDate());

        // 4. Verify command parameters sent to registrar
        $this->assertSame('colezacloud.com', $capturedCommand->getDomain());
        $this->assertSame(2, $capturedCommand->getYears());
        $this->assertTrue($capturedCommand->hasWhoisPrivacy());
        $this->assertSame(['ns1.colezacloud.com', 'ns2.colezacloud.com'], $capturedCommand->getNameservers());
        $this->assertArrayHasKey(DomainContact::TYPE_REGISTRANT, $capturedCommand->getContacts());

        // 5. Verify timeline audit events
        $timeline = $this->domainService->getTimeline($domain->getId());
        $eventTypes = array_map(fn($e) => $e->getEventType(), $timeline);
        $this->assertContains('created', $eventTypes);
        $this->assertContains('activated', $eventTypes);
        $this->assertContains('registrar_register_success', $eventTypes);
    }

    public function testRegisterDomainFlowFailureHandling(): void
    {
        $mockRegistrar = $this->createMockRegistrar('mock_reg', onRegister: function ($cmd) {
            return RegistrarOperationResult::failure(
                operation: 'register',
                domain: $cmd->getDomain(),
                errorCode: 'INSUFFICIENT_FUNDS',
                errorMessage: 'Reseller account balance too low.'
            );
        });
        $this->registry->register($mockRegistrar);

        $domain = $this->domainService->createDomain([
            'user_id' => 10,
            'domain' => 'insufficient-funds.com',
            'registration_period_years' => 1,
            'registrar_id' => 'mock_reg',
        ]);

        $result = $this->flowService->registerDomain($domain->getId());
        $this->assertFalse($result->isSuccessful());
        $this->assertSame('INSUFFICIENT_FUNDS', $result->getErrorCode());

        // Domain must still be pending_registration
        $freshDomain = $this->domainService->findDomainById($domain->getId());
        $this->assertSame(DomainStateMachine::STATUS_PENDING_REGISTRATION, $freshDomain->getStatus());

        // Verify timeline contains registrar_register_failed
        $timeline = $this->domainService->getTimeline($domain->getId());
        $eventTypes = array_map(fn($e) => $e->getEventType(), $timeline);
        $this->assertContains('registrar_register_failed', $eventTypes);
    }

    public function testRegisterDomainRejectsNonPendingStatus(): void
    {
        $mockRegistrar = $this->createMockRegistrar('mock_reg');
        $this->registry->register($mockRegistrar);

        $domain = $this->domainService->createDomain([
            'user_id' => 10,
            'domain' => 'already-active.com',
            'status' => DomainStateMachine::STATUS_ACTIVE,
            'registrar_id' => 'mock_reg',
        ]);

        $this->expectException(ValidationException::class);
        $this->flowService->registerDomain($domain->getId());
    }

    public function testRenewDomainFlow(): void
    {
        $renewedYears = null;
        $mockRegistrar = $this->createMockRegistrar('mock_reg', onRenew: function ($cmd) use (&$renewedYears) {
            $renewedYears = $cmd->getYears();
            return RegistrarOperationResult::success(
                operation: 'renew',
                domain: $cmd->getDomain(),
                remoteTransactionId: 'RENEW-9988'
            );
        });
        $this->registry->register($mockRegistrar);

        // Create domain and activate
        $domain = $this->domainService->createDomain([
            'user_id' => 10,
            'domain' => 'extend-me.com',
            'status' => DomainStateMachine::STATUS_PENDING_REGISTRATION,
            'registrar_id' => 'mock_reg',
        ]);
        $this->domainService->activateDomain($domain->getId(), expiryDate: '2027-01-01');

        // Renew for 3 years
        $res = $this->flowService->renewDomain($domain->getId(), years: 3, actorType: 'automation');
        $this->assertTrue($res->isSuccessful());
        $this->assertSame(3, $renewedYears);

        // Verify new expiry date extended by 3 years from 2027-01-01 -> 2030-01-01
        $updatedDomain = $this->domainService->findDomainById($domain->getId());
        $this->assertSame('2030-01-01', $updatedDomain->getExpiryDate());

        // Verify timeline
        $timeline = $this->domainService->getTimeline($domain->getId());
        $eventTypes = array_map(fn($e) => $e->getEventType(), $timeline);
        $this->assertContains('registrar_renew_success', $eventTypes);
    }

    public function testTransferDomainFlow(): void
    {
        $capturedCmd = null;
        $mockRegistrar = $this->createMockRegistrar('mock_reg', onTransfer: function ($cmd) use (&$capturedCmd) {
            $capturedCmd = $cmd;
            return RegistrarOperationResult::success(
                operation: 'transfer',
                domain: $cmd->getDomain(),
                remoteTransactionId: 'TRF-REC-777'
            );
        });
        $this->registry->register($mockRegistrar);

        $domain = $this->domainService->createDomain([
            'user_id' => 10,
            'domain' => 'transferin.com',
            'status' => DomainStateMachine::STATUS_ACTIVE,
            'registrar_id' => 'mock_reg',
        ]);

        $result = $this->flowService->transferDomain($domain->getId(), 'MyAuthCodeSecret!', actorType: 'user', actorId: 10);
        $this->assertTrue($result->isSuccessful());

        // Verify state is pending_transfer
        $fresh = $this->domainService->findDomainById($domain->getId());
        $this->assertSame(DomainStateMachine::STATUS_PENDING_TRANSFER, $fresh->getStatus());
        $this->assertSame('MyAuthCodeSecret!', $fresh->getEppCode());

        // Verify command
        $this->assertSame('MyAuthCodeSecret!', $capturedCmd->getAuthCode());

        // Verify timeline
        $timeline = $this->domainService->getTimeline($domain->getId());
        $eventTypes = array_map(fn($e) => $e->getEventType(), $timeline);
        $this->assertContains('registrar_transfer_initiated', $eventTypes);
    }

    public function testNameserversAndLockAndEppFlows(): void
    {
        $mockRegistrar = $this->createMockRegistrar('mock_reg');
        $this->registry->register($mockRegistrar);

        $domain = $this->domainService->createDomain([
            'user_id' => 10,
            'domain' => 'controls-test.com',
            'status' => DomainStateMachine::STATUS_ACTIVE,
            'registrar_id' => 'mock_reg',
        ]);

        // 1. Nameservers update
        $nsRes = $this->flowService->updateNameservers($domain->getId(), ['ns10.cloudflare.com', 'ns11.cloudflare.com']);
        $this->assertTrue($nsRes->isSuccessful(), 'Error: ' . $nsRes->getErrorMessage() . ' | ' . $nsRes->getErrorCode());
        $this->assertSame(['ns10.cloudflare.com', 'ns11.cloudflare.com'], $this->domainService->findDomainById($domain->getId())->getNameservers());

        // 2. Lock toggle
        $lockRes = $this->flowService->setRegistrarLock($domain->getId(), false);
        $this->assertTrue($lockRes->isSuccessful());
        $this->assertFalse($this->domainService->findDomainById($domain->getId())->isLocked());

        // 3. EPP retrieve
        $epp = $this->flowService->retrieveEppCode($domain->getId());
        $this->assertSame('AUTH-EPP-MOCK-999', $epp);
        $this->assertSame('AUTH-EPP-MOCK-999', $this->domainService->findDomainById($domain->getId())->getEppCode());

        // 4. Contact sync
        $contactRes = $this->flowService->syncContactsToRegistrar($domain->getId());
        $this->assertTrue($contactRes->isSuccessful());

        // 5. Sync from registrar
        $syncedDomain = $this->flowService->syncDomainFromRegistrar($domain->getId());
        $this->assertSame(['ns10.cloudflare.com', 'ns11.cloudflare.com'], $syncedDomain->getNameservers());
    }

    private function createMockRegistrar(
        string $id,
        array $availabilityMap = [],
        ?callable $onRegister = null,
        ?callable $onRenew = null,
        ?callable $onTransfer = null
    ): RegistrarProviderInterface {
        return new class($id, $availabilityMap, $onRegister, $onRenew, $onTransfer) implements RegistrarProviderInterface {
            private array $nameservers = [];
            private bool $isLocked = true;

            public function __construct(
                private string $id,
                private array $availMap,
                private $onRegister,
                private $onRenew,
                private $onTransfer
            ) {
            }

            public function getRegistrarId(): string
            {
                return $this->id;
            }

            public function getName(): string
            {
                return 'Mock Flow Registrar';
            }

            public function supportsCapability(string $capability): bool
            {
                return in_array($capability, $this->getSupportedCapabilities(), true);
            }

            public function getSupportedCapabilities(): array
            {
                return RegistrarCapability::all();
            }

            public function checkAvailability(string $domain): DomainAvailabilityResult
            {
                if (isset($this->availMap[$domain])) {
                    return $this->availMap[$domain]
                        ? DomainAvailabilityResult::available($domain)
                        : DomainAvailabilityResult::unavailable($domain, 'Domain taken');
                }
                return DomainAvailabilityResult::available($domain);
            }

            public function registerDomain(DomainRegistrationCommand $command): RegistrarOperationResult
            {
                if ($this->onRegister !== null) {
                    return ($this->onRegister)($command);
                }
                return RegistrarOperationResult::success('register', $command->getDomain(), 'TXN-1', '2027-01-01');
            }

            public function renewDomain(DomainRenewalCommand $command): RegistrarOperationResult
            {
                if ($this->onRenew !== null) {
                    return ($this->onRenew)($command);
                }
                return RegistrarOperationResult::success('renew', $command->getDomain(), 'TXN-REN-1');
            }

            public function transferDomain(DomainTransferCommand $command): RegistrarOperationResult
            {
                if ($this->onTransfer !== null) {
                    return ($this->onTransfer)($command);
                }
                return RegistrarOperationResult::success('transfer', $command->getDomain(), 'TXN-TRF-1');
            }

            public function getNameservers(string $domain): array
            {
                return $this->nameservers;
            }

            public function updateNameservers(string $domain, array $nameservers): RegistrarOperationResult
            {
                $this->nameservers = $nameservers;
                return RegistrarOperationResult::success('update_nameservers', $domain);
            }

            public function getRegistrarLock(string $domain): bool
            {
                return $this->isLocked;
            }

            public function setRegistrarLock(string $domain, bool $locked): RegistrarOperationResult
            {
                $this->isLocked = $locked;
                return RegistrarOperationResult::success('set_lock', $domain);
            }

            public function getEppCode(string $domain): ?string
            {
                return 'AUTH-EPP-MOCK-999';
            }

            public function getContacts(string $domain): array
            {
                return [];
            }

            public function updateContacts(string $domain, array $contacts): RegistrarOperationResult
            {
                return RegistrarOperationResult::success('update_contacts', $domain);
            }
        };
    }
}
