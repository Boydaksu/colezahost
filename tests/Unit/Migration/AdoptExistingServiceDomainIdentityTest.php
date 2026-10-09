<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Migration;

use Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository;
use Coleza\Domain\Migration\Adoption\AdoptedIdentityType;
use Coleza\Domain\Migration\Adoption\DomainAdoptionService;
use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Adoption\RemoteIdentityVerifierInterface;
use Coleza\Domain\Migration\Adoption\ServiceAdoptionService;
use Coleza\Domain\Migration\Canonical\CanonicalDomainDto;
use Coleza\Domain\Migration\Canonical\CanonicalServiceDto;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdoptExistingServiceDomainIdentityTest extends TestCase
{
    private Connection $db;
    private AdoptedIdentityRepository $identityRepo;
    private ProviderIdentityResolver $resolver;
    private ServiceAdoptionService $serviceAdopter;
    private DomainAdoptionService $domainAdopter;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->identityRepo = new AdoptedIdentityRepository($this->db);
        $this->resolver = new ProviderIdentityResolver();

        // Setup mappings: WHMCS Client 10 -> Coleza User 50, Package 2 -> Product 101, Server cpanel1 -> 1
        $this->resolver->registerClientMapping('10', 50);
        $this->resolver->registerProductMapping('2', 101);
        $this->resolver->registerServerMapping('cpanel1', 1);
        $this->resolver->registerRegistrarMapping('enom', 'enom');

        $this->serviceAdopter = new ServiceAdoptionService($this->db, $this->identityRepo, $this->resolver);
        $this->domainAdopter = new DomainAdoptionService($this->db, $this->identityRepo, $this->resolver);
    }

    public function testServiceAdoptionSuppressesRemoteProvisioningAndCreatesIdentityLink(): void
    {
        $dto = new CanonicalServiceDto(
            sourceId: '901',
            sourceSystem: 'whmcs',
            clientSourceId: '10',
            productSourceId: '2',
            domain: 'mytestsite.com',
            username: 'mycpaneluser',
            dedicatedIp: '192.168.1.50',
            status: 'active',
            billingCycle: 'monthly',
            recurringAmount: 19.99,
            currency: 'USD',
            registrationDate: '2025-01-01',
            nextDueDate: '2026-11-01'
        );

        $result = $this->serviceAdopter->adoptService($dto, 'BATCH-001', 'cpanel1');

        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($result->getAdoptedServiceId());

        $identity = $result->getProviderIdentity();
        $this->assertNotNull($identity);
        $this->assertSame(AdoptedIdentityType::HOSTING_SERVICE, $identity->getIdentityType());
        $this->assertSame('server', $identity->getProviderType());
        $this->assertSame('1', $identity->getProviderIdentifier());
        $this->assertSame('mycpaneluser', $identity->getExternalReferenceId());
        $this->assertTrue($identity->isProvisioningSuppressed());

        // Verify services table state
        $rows = $this->db->select("SELECT * FROM services WHERE id = :id", ['id' => $result->getAdoptedServiceId()]);
        $this->assertCount(1, $rows);
        $this->assertSame(50, (int) $rows[0]['user_id']);
        $this->assertSame(101, (int) $rows[0]['product_id']);
        $this->assertSame('mycpaneluser', $rows[0]['username']);
        $this->assertSame(1, (int) $rows[0]['is_adopted']);

        $meta = json_encode($rows[0]['metadata_json']);
        $this->assertStringContainsString('provisioning_suppressed', $meta);
    }

    public function testServiceAdoptionIsIdempotentOnReRun(): void
    {
        $dto = new CanonicalServiceDto(
            sourceId: '901',
            sourceSystem: 'whmcs',
            clientSourceId: '10',
            productSourceId: '2',
            domain: 'mytestsite.com',
            username: 'mycpaneluser'
        );

        $res1 = $this->serviceAdopter->adoptService($dto, 'BATCH-001', 'cpanel1');
        $this->assertTrue($res1->isSuccess());

        // Re-run adoption of exact same service
        $res2 = $this->serviceAdopter->adoptService($dto, 'BATCH-001', 'cpanel1');
        $this->assertTrue($res2->isSuccess());
        $this->assertSame($res1->getAdoptedServiceId(), $res2->getAdoptedServiceId());
        $this->assertStringContainsString('already adopted previously', $res2->getWarnings()[0]);
    }

    public function testServiceAdoptionConflictDetectionOnDifferentSourceEntity(): void
    {
        $dto1 = new CanonicalServiceDto(
            sourceId: '901',
            sourceSystem: 'whmcs',
            clientSourceId: '10',
            productSourceId: '2',
            username: 'shareduser'
        );
        $this->serviceAdopter->adoptService($dto1, 'BATCH-001', 'cpanel1');

        // Different source service (ID 902) attempting to claim same username on same server
        $dto2 = new CanonicalServiceDto(
            sourceId: '902',
            sourceSystem: 'whmcs',
            clientSourceId: '10',
            productSourceId: '2',
            username: 'shareduser'
        );
        $res2 = $this->serviceAdopter->adoptService($dto2, 'BATCH-001', 'cpanel1');

        $this->assertFalse($res2->isSuccess());
        $this->assertStringContainsString('Conflict: Server account username [shareduser]', $res2->getErrors()[0]);
    }

    public function testServiceAdoptionFailsIfClientUnresolved(): void
    {
        $dto = new CanonicalServiceDto(
            sourceId: '903',
            sourceSystem: 'whmcs',
            clientSourceId: '999999', // Unknown client
            productSourceId: '2',
            username: 'unknownuser'
        );
        $result = $this->serviceAdopter->adoptService($dto, 'BATCH-001', 'cpanel1');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Client source ID [999999] has not been resolved', $result->getErrors()[0]);
    }

    public function testDomainAdoptionSuppressesRegistrarApiAndCreatesIdentityLink(): void
    {
        $dto = new CanonicalDomainDto(
            sourceId: '401',
            sourceSystem: 'whmcs',
            clientSourceId: '10',
            domainName: 'existingdomain.org',
            registrar: 'enom',
            status: 'active',
            recurringAmount: 14.50,
            currency: 'USD',
            registrationPeriodYears: 1,
            registrationDate: '2024-05-01',
            expiryDate: '2027-05-01',
            nextDueDate: '2027-04-15',
            autoRenew: true,
            idProtection: true,
            nameServers: ['ns1.example.com', 'ns2.example.com']
        );

        $result = $this->domainAdopter->adoptDomain($dto, 'BATCH-001', 'enom');

        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($result->getAdoptedDomainId());

        $identity = $result->getProviderIdentity();
        $this->assertNotNull($identity);
        $this->assertSame(AdoptedIdentityType::DOMAIN_REGISTRATION, $identity->getIdentityType());
        $this->assertSame('registrar', $identity->getProviderType());
        $this->assertSame('enom', $identity->getProviderIdentifier());
        $this->assertSame('existingdomain.org', $identity->getExternalReferenceId());
        $this->assertTrue($identity->isProvisioningSuppressed());

        // Verify domains table state
        $rows = $this->db->select("SELECT * FROM domains WHERE id = :id", ['id' => $result->getAdoptedDomainId()]);
        $this->assertCount(1, $rows);
        $this->assertSame(50, (int) $rows[0]['user_id']);
        $this->assertSame('existingdomain.org', $rows[0]['domain_name']);
        $this->assertSame('enom', $rows[0]['registrar']);
        $this->assertSame(1, (int) $rows[0]['is_adopted']);
    }

    public function testDomainAdoptionConflictDetection(): void
    {
        $dto1 = new CanonicalDomainDto(
            sourceId: '401',
            sourceSystem: 'whmcs',
            clientSourceId: '10',
            domainName: 'uniqueportal.com',
            registrar: 'enom'
        );
        $this->domainAdopter->adoptDomain($dto1, 'BATCH-001');

        $dto2 = new CanonicalDomainDto(
            sourceId: '402', // Different source entity
            sourceSystem: 'whmcs',
            clientSourceId: '10',
            domainName: 'uniqueportal.com',
            registrar: 'enom'
        );
        $res2 = $this->domainAdopter->adoptDomain($dto2, 'BATCH-001');

        $this->assertFalse($res2->isSuccess());
        $this->assertStringContainsString('Conflict: Domain name [uniqueportal.com] is already adopted', $res2->getErrors()[0]);
    }

    public function testRemoteReadonlyVerificationFlagging(): void
    {
        $mockVerifier = new class implements RemoteIdentityVerifierInterface {
            public function verifyServerAccountExists(string|int $serverIdentifier, string $username, ?string $domain = null): bool
            {
                return $username === 'verifieduser';
            }

            public function verifyDomainRegistration(string $registrar, string $domain): bool
            {
                return $domain === 'verifieddomain.com';
            }
        };

        $verifiedAdopter = new ServiceAdoptionService($this->db, $this->identityRepo, $this->resolver, $mockVerifier);

        $dtoVerified = new CanonicalServiceDto(
            sourceId: '950',
            sourceSystem: 'whmcs',
            clientSourceId: '10',
            productSourceId: '2',
            username: 'verifieduser'
        );
        $resVer = $verifiedAdopter->adoptService($dtoVerified, 'BATCH-001', 'cpanel1', verifyRemote: true);
        $this->assertTrue($resVer->isSuccess());
        $this->assertTrue($resVer->getProviderIdentity()->isRemoteVerificationPassed());

        $dtoUnverified = new CanonicalServiceDto(
            sourceId: '951',
            sourceSystem: 'whmcs',
            clientSourceId: '10',
            productSourceId: '2',
            username: 'ghostuser'
        );
        $resUnver = $verifiedAdopter->adoptService($dtoUnverified, 'BATCH-001', 'cpanel1', verifyRemote: true);
        $this->assertTrue($resUnver->isSuccess());
        $this->assertFalse($resUnver->getProviderIdentity()->isRemoteVerificationPassed());
        $this->assertStringContainsString('could not be verified on remote server', $resUnver->getWarnings()[0]);
    }
}
