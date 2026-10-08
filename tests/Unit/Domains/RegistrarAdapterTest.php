<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domains;

use Coleza\Domain\Domains\DomainContact;
use Coleza\Domain\Domains\Registrar\Adapters\NameSilo\NameSiloRegistrarAdapter;
use Coleza\Domain\Domains\Registrar\DomainRegistrationCommand;
use Coleza\Domain\Domains\Registrar\DomainRenewalCommand;
use Coleza\Domain\Domains\Registrar\DomainTransferCommand;
use Coleza\Domain\Domains\Registrar\RegistrarAdapterFactory;
use Coleza\Domain\Domains\Registrar\RegistrarCapability;
use Coleza\Domain\Domains\Registrar\Vault\RegistrarConfiguration;
use Coleza\Foundation\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class RegistrarAdapterTest extends TestCase
{
    public function testMetadataAndCapabilities(): void
    {
        $config = new RegistrarConfiguration(
            registrarId: 'namesilo',
            apiKey: 'test-api-key-12345',
            isSandbox: true
        );

        $adapter = new NameSiloRegistrarAdapter($config);

        $this->assertSame('namesilo', $adapter->getRegistrarId());
        $this->assertSame('NameSilo', $adapter->getName());
        $this->assertTrue($adapter->supportsCapability(RegistrarCapability::AVAILABILITY_CHECK));
        $this->assertTrue($adapter->supportsCapability(RegistrarCapability::REGISTER));
        $this->assertTrue($adapter->supportsCapability(RegistrarCapability::RENEW));
        $this->assertTrue($adapter->supportsCapability(RegistrarCapability::TRANSFER));
        $this->assertTrue($adapter->supportsCapability(RegistrarCapability::UPDATE_NAMESERVERS));
        $this->assertTrue($adapter->supportsCapability(RegistrarCapability::SET_LOCK));
        $this->assertTrue($adapter->supportsCapability(RegistrarCapability::GET_EPP_CODE));
        $this->assertTrue($adapter->supportsCapability(RegistrarCapability::UPDATE_CONTACTS));
        $this->assertTrue($adapter->supportsCapability(RegistrarCapability::ID_PROTECTION));
    }

    public function testEndpointResolution(): void
    {
        // 1. Sandbox default
        $sandboxConfig = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'key', isSandbox: true);
        $recordedUrl = null;
        $sandboxAdapter = new NameSiloRegistrarAdapter($sandboxConfig, function ($op, $params, $url) use (&$recordedUrl) {
            $recordedUrl = $url;
            return ['reply' => ['code' => 300]];
        });
        $sandboxAdapter->checkAvailability('test.com');
        $this->assertStringStartsWith('https://sandbox.namesilo.com/api/checkRegisterAvailability', $recordedUrl);

        // 2. Production default
        $prodConfig = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'key', isSandbox: false);
        $prodAdapter = new NameSiloRegistrarAdapter($prodConfig, function ($op, $params, $url) use (&$recordedUrl) {
            $recordedUrl = $url;
            return ['reply' => ['code' => 300]];
        });
        $prodAdapter->checkAvailability('test.com');
        $this->assertStringStartsWith('https://www.namesilo.com/api/checkRegisterAvailability', $recordedUrl);

        // 3. Custom endpoint
        $customConfig = new RegistrarConfiguration(
            registrarId: 'namesilo',
            apiKey: 'key',
            endpoint: 'https://proxy.example.internal/namesilo'
        );
        $customAdapter = new NameSiloRegistrarAdapter($customConfig, function ($op, $params, $url) use (&$recordedUrl) {
            $recordedUrl = $url;
            return ['reply' => ['code' => 300]];
        });
        $customAdapter->checkAvailability('test.com');
        $this->assertStringStartsWith('https://proxy.example.internal/namesilo/checkRegisterAvailability', $recordedUrl);
    }

    public function testCheckAvailabilityAvailable(): void
    {
        $config = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'key');
        $adapter = new NameSiloRegistrarAdapter($config, function () {
            return [
                'reply' => [
                    'code' => 300,
                    'detail' => 'success',
                    'available' => [
                        'domain' => [
                            '@attributes' => [
                                'price' => '12.99',
                            ],
                        ],
                    ],
                ],
            ];
        });

        $result = $adapter->checkAvailability('awesome-new-domain.com');
        $this->assertTrue($result->isAvailable());
        $this->assertSame('awesome-new-domain.com', $result->getDomain());
        $this->assertSame(12.99, $result->getPrice());
        $this->assertSame('USD', $result->getCurrency());
    }

    public function testCheckAvailabilityUnavailable(): void
    {
        $config = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'key');
        $adapter = new NameSiloRegistrarAdapter($config, function () {
            return [
                'reply' => [
                    'code' => 300,
                    'detail' => 'success',
                    'unavailable' => [
                        'domain' => 'google.com',
                    ],
                ],
            ];
        });

        $result = $adapter->checkAvailability('google.com');
        $this->assertFalse($result->isAvailable());
        $this->assertSame('Domain already registered.', $result->getReason());
    }

    public function testCheckAvailabilityXmlPayloadParsing(): void
    {
        $config = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'key');
        $xmlBody = <<<XML
<namesilo>
    <request>
        <operation>checkRegisterAvailability</operation>
    </request>
    <reply>
        <code>300</code>
        <detail>success</detail>
        <available>
            <domain>xml-domain.org</domain>
        </available>
    </reply>
</namesilo>
XML;

        $adapter = new NameSiloRegistrarAdapter($config, fn() => $xmlBody);

        $result = $adapter->checkAvailability('xml-domain.org');
        $this->assertTrue($result->isAvailable());
    }

    public function testRegisterDomainSuccess(): void
    {
        $config = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'super_secret_key');
        $capturedParams = null;

        $adapter = new NameSiloRegistrarAdapter($config, function ($op, $params) use (&$capturedParams) {
            $capturedParams = $params;
            return [
                'reply' => [
                    'code' => 300,
                    'detail' => 'success',
                    'order_id' => 'NS-ORD-987654',
                    'domain' => 'mybrand.com',
                ],
            ];
        });

        $cmd = new DomainRegistrationCommand(
            domain: 'mybrand.com',
            years: 2,
            nameservers: ['ns1.namesilo.com', 'ns2.namesilo.com'],
            contacts: [
                DomainContact::TYPE_REGISTRANT => new DomainContact(
                    id: 1,
                    domainId: 10,
                    contactType: DomainContact::TYPE_REGISTRANT,
                    firstName: 'John',
                    lastName: 'Doe',
                    email: 'john@mybrand.com',
                    phone: '+1.5559876543'
                ),
            ],
            whoisPrivacy: true
        );

        $result = $adapter->registerDomain($cmd);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('mybrand.com', $result->getDomain());
        $this->assertSame('NS-ORD-987654', $result->getRemoteTransactionId());
        $this->assertNotNull($result->getExpirationDate());

        // Verify sent parameters
        $this->assertSame('mybrand.com', $capturedParams['domain']);
        $this->assertSame('2', $capturedParams['years']);
        $this->assertSame('1', $capturedParams['private']);
        $this->assertSame('ns1.namesilo.com', $capturedParams['ns1']);
        $this->assertSame('ns2.namesilo.com', $capturedParams['ns2']);
    }

    public function testRegisterDomainFailure(): void
    {
        $config = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'key');

        $adapter = new NameSiloRegistrarAdapter($config, function () {
            return [
                'reply' => [
                    'code' => 252,
                    'detail' => 'Insufficient funds in account.',
                ],
            ];
        });

        $cmd = new DomainRegistrationCommand(domain: 'broke.org', years: 1);
        $result = $adapter->registerDomain($cmd);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('252', $result->getErrorCode(), 'Error was: ' . $result->getErrorMessage());
        $this->assertSame('Insufficient funds in account.', $result->getErrorMessage());
    }

    public function testRenewDomainSuccessAndFailure(): void
    {
        $config = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'key');

        // Success
        $successAdapter = new NameSiloRegistrarAdapter($config, fn() => [
            'reply' => [
                'code' => 300,
                'detail' => 'success',
                'order_id' => 'RENEW-12345',
            ],
        ]);
        $renewCmd = new DomainRenewalCommand('renew-me.com', 1);
        $result = $successAdapter->renewDomain($renewCmd);
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('RENEW-12345', $result->getRemoteTransactionId());

        // Failure
        $failAdapter = new NameSiloRegistrarAdapter($config, fn() => [
            'reply' => [
                'code' => 261,
                'detail' => 'Domain not in account.',
            ],
        ]);
        $failResult = $failAdapter->renewDomain($renewCmd);
        $this->assertFalse($failResult->isSuccessful());
        $this->assertSame('261', $failResult->getErrorCode());
    }

    public function testTransferDomain(): void
    {
        $config = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'key');
        $captured = null;

        $adapter = new NameSiloRegistrarAdapter($config, function ($op, $params) use (&$captured) {
            $captured = $params;
            return [
                'reply' => [
                    'code' => 300,
                    'order_id' => 'TRANSFER-999',
                ],
            ];
        });

        $transferCmd = new DomainTransferCommand('incoming-domain.net', 'SecretAuth123!', whoisPrivacy: true);
        $result = $adapter->transferDomain($transferCmd);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('TRANSFER-999', $result->getRemoteTransactionId());
        $this->assertSame('SecretAuth123!', $captured['auth']);
        $this->assertSame('1', $captured['private']);
    }

    public function testNameserversGetAndUpdate(): void
    {
        $config = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'key');

        // 1. Get nameservers
        $adapter = new NameSiloRegistrarAdapter($config, fn() => [
            'reply' => [
                'code' => 300,
                'nameservers' => [
                    'nameserver' => ['NS1.DNSHOST.COM', 'NS2.DNSHOST.COM'],
                ],
            ],
        ]);

        $ns = $adapter->getNameservers('example.com');
        $this->assertSame(['ns1.dnshost.com', 'ns2.dnshost.com'], $ns);

        // 2. Update nameservers
        $captured = null;
        $adapterUpdate = new NameSiloRegistrarAdapter($config, function ($op, $params) use (&$captured) {
            $captured = $params;
            return [
                'reply' => ['code' => 300, 'detail' => 'success'],
            ];
        });

        $res = $adapterUpdate->updateNameservers('example.com', ['ns3.custom.io', 'ns4.custom.io']);
        $this->assertTrue($res->isSuccessful());
        $this->assertSame('ns3.custom.io', $captured['ns1']);
        $this->assertSame('ns4.custom.io', $captured['ns2']);
    }

    public function testRegistrarLockAndEppCode(): void
    {
        $config = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'key');

        // 1. Get lock
        $adapterLockYes = new NameSiloRegistrarAdapter($config, fn() => [
            'reply' => ['code' => 300, 'locked' => 'Yes'],
        ]);
        $this->assertTrue($adapterLockYes->getRegistrarLock('locktest.com'));

        $adapterLockNo = new NameSiloRegistrarAdapter($config, fn() => [
            'reply' => ['code' => 300, 'locked' => 'No'],
        ]);
        $this->assertFalse($adapterLockNo->getRegistrarLock('locktest.com'));

        // 2. Set lock true (domainLock) and false (domainUnlock)
        $lastOp = null;
        $adapterSetLock = new NameSiloRegistrarAdapter($config, function ($op) use (&$lastOp) {
            $lastOp = $op;
            return ['reply' => ['code' => 300]];
        });

        $resLock = $adapterSetLock->setRegistrarLock('locktest.com', true);
        $this->assertTrue($resLock->isSuccessful());
        $this->assertSame('domainLock', $lastOp);

        $resUnlock = $adapterSetLock->setRegistrarLock('locktest.com', false);
        $this->assertTrue($resUnlock->isSuccessful());
        $this->assertSame('domainUnlock', $lastOp);

        // 3. EPP Auth Code
        $adapterEpp = new NameSiloRegistrarAdapter($config, fn() => [
            'reply' => ['code' => 300, 'auth_code' => 'XYZ-EPP-999'],
        ]);
        $this->assertSame('XYZ-EPP-999', $adapterEpp->getEppCode('locktest.com'));
    }

    public function testContactsGetAndUpdate(): void
    {
        $config = new RegistrarConfiguration(registrarId: 'namesilo', apiKey: 'key');

        // 1. Get contacts
        $adapterGet = new NameSiloRegistrarAdapter($config, fn() => [
            'reply' => [
                'code' => 300,
                'contact' => [
                    'first_name' => 'Alice',
                    'last_name' => 'Wong',
                    'company' => 'Acme Inc',
                    'email' => 'alice@acme.com',
                    'phone' => '+1.5551112233',
                    'address' => '100 Market St',
                    'city' => 'San Francisco',
                    'state' => 'CA',
                    'zip' => '94105',
                    'country' => 'US',
                ],
            ],
        ]);

        $contacts = $adapterGet->getContacts('acme.com');
        $this->assertArrayHasKey(DomainContact::TYPE_REGISTRANT, $contacts);
        $registrant = $contacts[DomainContact::TYPE_REGISTRANT];
        $this->assertSame('Alice', $registrant->getFirstName());
        $this->assertSame('Wong', $registrant->getLastName());
        $this->assertSame('Acme Inc', $registrant->getCompanyName());

        // 2. Update contacts
        $captured = null;
        $adapterUpdate = new NameSiloRegistrarAdapter($config, function ($op, $params) use (&$captured) {
            $captured = $params;
            return ['reply' => ['code' => 300]];
        });

        $newContact = new DomainContact(
            id: null,
            domainId: 5,
            contactType: DomainContact::TYPE_REGISTRANT,
            firstName: 'Bob',
            lastName: 'Dylan',
            companyName: 'Music Corp',
            email: 'bob@music.com',
            phone: '+1.5552223344',
            addressLine1: '456 Broadway',
            city: 'New York',
            state: 'NY',
            postalCode: '10001',
            countryCode: 'US'
        );

        $res = $adapterUpdate->updateContacts('acme.com', [
            DomainContact::TYPE_REGISTRANT => $newContact,
        ]);

        $this->assertTrue($res->isSuccessful());
        $this->assertSame('Bob', $captured['fn']);
        $this->assertSame('Dylan', $captured['ln']);
        $this->assertSame('Music Corp', $captured['cp']);
    }

    public function testSecretSanitizationInPayloads(): void
    {
        $config = new RegistrarConfiguration(
            registrarId: 'namesilo',
            apiKey: 'SUPER_SECRET_KEY_9999',
            apiSecret: 'SECRET_HASH_8888'
        );

        $adapter = new NameSiloRegistrarAdapter($config, fn() => [
            'reply' => [
                'code' => 300,
                'key' => 'SUPER_SECRET_KEY_9999',
                'secret' => 'SECRET_HASH_8888',
                'embedded' => 'Here is key: SUPER_SECRET_KEY_9999 in text',
            ],
        ]);

        $cmd = new DomainRenewalCommand('clean.com', 1);
        $result = $adapter->renewDomain($cmd);

        $raw = $result->getRawResponse();
        $this->assertNotNull($raw);
        $this->assertSame('••••••••', $raw['reply']['key']);
        $this->assertSame('••••••••', $raw['reply']['secret']);
        $this->assertSame('Here is key: •••••••• in text', $raw['reply']['embedded']);
    }

    public function testRegistrarAdapterFactory(): void
    {
        $factory = new RegistrarAdapterFactory();

        $config = new RegistrarConfiguration(
            registrarId: 'namesilo',
            apiKey: 'key123'
        );

        $adapter = $factory->create($config);
        $this->assertInstanceOf(NameSiloRegistrarAdapter::class, $adapter);
        $this->assertSame('namesilo', $adapter->getRegistrarId());

        // Unsupported throws ValidationException
        $invalidConfig = new RegistrarConfiguration(
            registrarId: 'unsupported_provider',
            apiKey: 'key'
        );

        $this->expectException(ValidationException::class);
        $factory->create($invalidConfig);
    }
}
