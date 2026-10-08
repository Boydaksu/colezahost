<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domains;

use Coleza\Domain\Domains\DomainContact;
use Coleza\Domain\Domains\Registrar\DomainAvailabilityResult;
use Coleza\Domain\Domains\Registrar\DomainRegistrationCommand;
use Coleza\Domain\Domains\Registrar\DomainRenewalCommand;
use Coleza\Domain\Domains\Registrar\DomainTransferCommand;
use Coleza\Domain\Domains\Registrar\RegistrarCapability;
use Coleza\Domain\Domains\Registrar\RegistrarOperationResult;
use Coleza\Domain\Domains\Registrar\RegistrarProviderInterface;
use Coleza\Domain\Domains\Registrar\RegistrarRegistry;
use Coleza\Domain\Domains\Registrar\Vault\RegistrarConfiguration;
use Coleza\Domain\Domains\Registrar\Vault\VaultRegistrarSettingsManager;
use Coleza\Domain\Vault\Encryptor;
use Coleza\Domain\Vault\VaultService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class RegistrarCapabilityAndVaultTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private VaultService $vaultService;
    private VaultRegistrarSettingsManager $settingsManager;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        // Setup 32-byte encryption key for Vault Encryptor
        $key = '0123456789abcdef0123456789abcdef';
        $encryptor = new Encryptor($key);
        $this->vaultService = new VaultService($this->db, $encryptor);
        $this->vaultService->ensureTable();

        $this->settingsManager = new VaultRegistrarSettingsManager($this->db, $this->vaultService);
        $this->settingsManager->ensureTables();
    }

    public function testRegistrarCapabilitiesList(): void
    {
        $all = RegistrarCapability::all();
        $this->assertContains(RegistrarCapability::AVAILABILITY_CHECK, $all);
        $this->assertContains(RegistrarCapability::REGISTER, $all);
        $this->assertContains(RegistrarCapability::RENEW, $all);
        $this->assertContains(RegistrarCapability::TRANSFER, $all);
        $this->assertContains(RegistrarCapability::UPDATE_NAMESERVERS, $all);
        $this->assertContains(RegistrarCapability::SET_LOCK, $all);
        $this->assertContains(RegistrarCapability::GET_EPP_CODE, $all);
        $this->assertContains(RegistrarCapability::UPDATE_CONTACTS, $all);
    }

    public function testMockRegistrarContractImplementation(): void
    {
        $mock = $this->createMockRegistrar('mock_registrar');

        $this->assertSame('mock_registrar', $mock->getRegistrarId());
        $this->assertSame('Mock Registrar Adapter', $mock->getName());
        $this->assertTrue($mock->supportsCapability(RegistrarCapability::REGISTER));
        $this->assertTrue($mock->supportsCapability(RegistrarCapability::UPDATE_NAMESERVERS));

        // 1. Availability check
        $avail = $mock->checkAvailability('available-domain.com');
        $this->assertTrue($avail->isAvailable());

        $unavail = $mock->checkAvailability('taken-domain.com');
        $this->assertFalse($unavail->isAvailable());
        $this->assertNotNull($unavail->getReason());

        // 2. Register domain
        $regCmd = new DomainRegistrationCommand(
            domain: 'my-new-domain.com',
            years: 2,
            nameservers: ['ns1.example.com', 'ns2.example.com'],
            contacts: [
                DomainContact::TYPE_REGISTRANT => new DomainContact(
                    id: 1,
                    domainId: 10,
                    contactType: DomainContact::TYPE_REGISTRANT,
                    firstName: 'Alice',
                    lastName: 'Smith',
                    email: 'alice@example.com',
                    phone: '+1.5551234567'
                ),
            ],
            whoisPrivacy: true
        );

        $regResult = $mock->registerDomain($regCmd);
        $this->assertTrue($regResult->isSuccessful());
        $this->assertSame('my-new-domain.com', $regResult->getDomain());
        $this->assertNotNull($regResult->getRemoteTransactionId());
        $this->assertNotNull($regResult->getExpirationDate());

        // 3. Renew domain
        $renewCmd = new DomainRenewalCommand('my-new-domain.com', 1);
        $renewResult = $mock->renewDomain($renewCmd);
        $this->assertTrue($renewResult->isSuccessful());

        // 4. Transfer domain
        $transferCmd = new DomainTransferCommand('transfer-me.com', 'AuthCode!123');
        $transferResult = $mock->transferDomain($transferCmd);
        $this->assertTrue($transferResult->isSuccessful());

        // 5. Nameservers
        $nsResult = $mock->updateNameservers('my-new-domain.com', ['ns3.custom.com', 'ns4.custom.com']);
        $this->assertTrue($nsResult->isSuccessful());
        $this->assertSame(['ns3.custom.com', 'ns4.custom.com'], $mock->getNameservers('my-new-domain.com'));

        // 6. Lock
        $mock->setRegistrarLock('my-new-domain.com', true);
        $this->assertTrue($mock->getRegistrarLock('my-new-domain.com'));
        $mock->setRegistrarLock('my-new-domain.com', false);
        $this->assertFalse($mock->getRegistrarLock('my-new-domain.com'));

        // 7. EPP Code
        $this->assertSame('EPP-AUTH-12345', $mock->getEppCode('my-new-domain.com'));

        // 8. Contacts
        $contacts = $mock->getContacts('my-new-domain.com');
        $this->assertArrayHasKey(DomainContact::TYPE_REGISTRANT, $contacts);
    }

    public function testRegistrarRegistry(): void
    {
        $mock1 = $this->createMockRegistrar('mock_reg1');
        $mock2 = $this->createMockRegistrar('namesilo');

        $registry = new RegistrarRegistry();
        $this->assertFalse($registry->has('mock_reg1'));

        $registry->register($mock1);
        $this->assertTrue($registry->has('mock_reg1'));
        $this->assertSame($mock1, $registry->get('mock_reg1'));
        $this->assertSame($mock1, $registry->getDefault()); // First registered is default

        $registry->register($mock2);
        $this->assertTrue($registry->has('namesilo'));
        $this->assertSame($mock2, $registry->get('namesilo'));

        // Change default
        $registry->setDefault('namesilo');
        $this->assertSame($mock2, $registry->getDefault());

        // Unknown throws
        $this->expectException(ValidationException::class);
        $registry->get('unknown_provider');
    }

    public function testRegistrarConfigurationSecretMasking(): void
    {
        $config = new RegistrarConfiguration(
            registrarId: 'resellerclub',
            apiKey: 'super_secret_api_key_123',
            apiSecret: 'ultra_secret_hash_456',
            resellerId: 'RC-9988',
            endpoint: 'https://httpapi.com/api',
            isSandbox: false
        );

        $this->assertSame('resellerclub', $config->getRegistrarId());
        $this->assertSame('super_secret_api_key_123', $config->getApiKey());
        $this->assertSame('ultra_secret_hash_456', $config->getApiSecret());

        // Masked export
        $masked = $config->toArray(maskSecrets: true);
        $this->assertSame('••••••••', $masked['api_key']);
        $this->assertSame('••••••••', $masked['api_secret']);
        $this->assertSame('RC-9988', $masked['reseller_id']);

        // Plain export
        $plain = $config->toArray(maskSecrets: false);
        $this->assertSame('super_secret_api_key_123', $plain['api_key']);
        $this->assertSame('ultra_secret_hash_456', $plain['api_secret']);

        // __debugInfo() masking
        $debug = $config->__debugInfo();
        $this->assertSame('••••••••', $debug['api_key']);
        $this->assertSame('••••••••', $debug['api_secret']);
    }

    public function testVaultRegistrarSettingsManagerPersistenceAndDecryption(): void
    {
        $config = new RegistrarConfiguration(
            registrarId: 'namesilo',
            apiKey: 'NS-SECRET-KEY-ABCDEF',
            apiSecret: 'NS-SECRET-PASS-XYZ',
            resellerId: '12345',
            endpoint: 'https://www.namesilo.com/api',
            isSandbox: true,
            customSettings: ['auto_ssl' => true, 'batch_size' => 50]
        );

        // 1. Save configuration (credentials saved encrypted into Vault)
        $this->settingsManager->saveConfiguration($config, actorUserId: 1);

        // 2. Retrieve decrypted configuration
        $retrieved = $this->settingsManager->getConfiguration('namesilo', actorUserId: 1);
        $this->assertNotNull($retrieved);
        $this->assertSame('namesilo', $retrieved->getRegistrarId());
        $this->assertSame('NS-SECRET-KEY-ABCDEF', $retrieved->getApiKey());
        $this->assertSame('NS-SECRET-PASS-XYZ', $retrieved->getApiSecret());
        $this->assertSame('12345', $retrieved->getResellerId());
        $this->assertTrue($retrieved->isSandbox());
        $this->assertTrue($retrieved->getCustomSettings()['auto_ssl']);

        // 3. Retrieve masked configuration for admin panel
        $masked = $this->settingsManager->getMaskedConfiguration('namesilo');
        $this->assertNotNull($masked);
        $this->assertStringContainsString('••', (string) $masked['api_key']);
        $this->assertStringContainsString('••', (string) $masked['api_secret']);
        $this->assertSame('12345', $masked['reseller_id']);

        // 4. List configured registrars
        $registrars = $this->settingsManager->listConfiguredRegistrars();
        $this->assertContains('namesilo', $registrars);

        // 5. Delete and verify purged from Vault
        $this->assertTrue($this->settingsManager->deleteConfiguration('namesilo', actorUserId: 1));
        $this->assertNull($this->settingsManager->getConfiguration('namesilo'));
        $this->assertNull($this->settingsManager->getMaskedConfiguration('namesilo'));
    }

    private function createMockRegistrar(string $id): RegistrarProviderInterface
    {
        return new class($id) implements RegistrarProviderInterface {
            private array $nameservers = ['ns1.default.com', 'ns2.default.com'];
            private bool $isLocked = true;
            private array $contacts = [];

            public function __construct(private string $id)
            {
            }

            public function getRegistrarId(): string
            {
                return $this->id;
            }

            public function getName(): string
            {
                return 'Mock Registrar Adapter';
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
                if (str_starts_with($domain, 'taken')) {
                    return DomainAvailabilityResult::unavailable($domain, 'Domain already taken');
                }
                return DomainAvailabilityResult::available($domain);
            }

            public function registerDomain(DomainRegistrationCommand $command): RegistrarOperationResult
            {
                $this->nameservers = $command->getNameservers();
                $this->contacts = $command->getContacts();
                $expiry = date('Y-m-d', strtotime("+{$command->getYears()} years"));

                return RegistrarOperationResult::success(
                    operation: 'register',
                    domain: $command->getDomain(),
                    remoteTransactionId: 'REG-' . bin2hex(random_bytes(6)),
                    expirationDate: $expiry
                );
            }

            public function renewDomain(DomainRenewalCommand $command): RegistrarOperationResult
            {
                $expiry = date('Y-m-d', strtotime("+{$command->getYears()} years"));
                return RegistrarOperationResult::success(
                    operation: 'renew',
                    domain: $command->getDomain(),
                    remoteTransactionId: 'REN-' . bin2hex(random_bytes(6)),
                    expirationDate: $expiry
                );
            }

            public function transferDomain(DomainTransferCommand $command): RegistrarOperationResult
            {
                return RegistrarOperationResult::success(
                    operation: 'transfer',
                    domain: $command->getDomain(),
                    remoteTransactionId: 'TRF-' . bin2hex(random_bytes(6))
                );
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
                return 'EPP-AUTH-12345';
            }

            public function getContacts(string $domain): array
            {
                if (empty($this->contacts)) {
                    return [
                        DomainContact::TYPE_REGISTRANT => new DomainContact(
                            id: 1,
                            domainId: 1,
                            contactType: DomainContact::TYPE_REGISTRANT,
                            firstName: 'Default',
                            lastName: 'Registrant',
                            email: 'default@registrant.com',
                            phone: '+1.5550000000'
                        ),
                    ];
                }
                return $this->contacts;
            }

            public function updateContacts(string $domain, array $contacts): RegistrarOperationResult
            {
                $this->contacts = $contacts;
                return RegistrarOperationResult::success('update_contacts', $domain);
            }
        };
    }
}
