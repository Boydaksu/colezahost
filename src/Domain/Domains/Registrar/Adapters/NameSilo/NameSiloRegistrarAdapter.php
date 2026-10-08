<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar\Adapters\NameSilo;

use Coleza\Domain\Domains\DomainContact;
use Coleza\Domain\Domains\Registrar\DomainAvailabilityResult;
use Coleza\Domain\Domains\Registrar\DomainRegistrationCommand;
use Coleza\Domain\Domains\Registrar\DomainRenewalCommand;
use Coleza\Domain\Domains\Registrar\DomainTransferCommand;
use Coleza\Domain\Domains\Registrar\RegistrarCapability;
use Coleza\Domain\Domains\Registrar\RegistrarOperationResult;
use Coleza\Domain\Domains\Registrar\RegistrarProviderInterface;
use Coleza\Domain\Domains\Registrar\Vault\RegistrarConfiguration;
use Throwable;

final class NameSiloRegistrarAdapter implements RegistrarProviderInterface
{
    public const DEFAULT_PROD_ENDPOINT = 'https://www.namesilo.com/api';
    public const DEFAULT_SANDBOX_ENDPOINT = 'https://sandbox.namesilo.com/api';

    /**
     * @var callable|null
     */
    private $httpClient;

    public function __construct(
        private RegistrarConfiguration $config,
        ?callable $httpClient = null
    ) {
        $this->httpClient = $httpClient;
    }

    public function getRegistrarId(): string
    {
        return 'namesilo';
    }

    public function getName(): string
    {
        return 'NameSilo';
    }

    public function supportsCapability(string $capability): bool
    {
        return in_array($capability, $this->getSupportedCapabilities(), true);
    }

    /**
     * @return list<string>
     */
    public function getSupportedCapabilities(): array
    {
        return [
            RegistrarCapability::AVAILABILITY_CHECK,
            RegistrarCapability::REGISTER,
            RegistrarCapability::RENEW,
            RegistrarCapability::TRANSFER,
            RegistrarCapability::UPDATE_NAMESERVERS,
            RegistrarCapability::SET_LOCK,
            RegistrarCapability::GET_EPP_CODE,
            RegistrarCapability::UPDATE_CONTACTS,
            RegistrarCapability::ID_PROTECTION,
        ];
    }

    public function checkAvailability(string $domain): DomainAvailabilityResult
    {
        try {
            $response = $this->sendApiRequest('checkRegisterAvailability', [
                'domains' => $domain,
            ]);

            $reply = $response['reply'] ?? [];
            $code = (int) ($reply['code'] ?? 0);

            if ($code === 300) {
                // Check if in available block
                if (isset($reply['available'])) {
                    $availNode = $reply['available'];
                    $domainNode = $availNode['domain'] ?? null;
                    $price = null;

                    if (is_array($domainNode)) {
                        $priceStr = $domainNode['@attributes']['price'] ?? ($domainNode['price'] ?? null);
                        if ($priceStr !== null) {
                            $price = (float) $priceStr;
                        }
                    }

                    return DomainAvailabilityResult::available(
                        domain: $domain,
                        price: $price,
                        currency: 'USD'
                    );
                }

                if (isset($reply['unavailable'])) {
                    return DomainAvailabilityResult::unavailable(
                        domain: $domain,
                        reason: 'Domain already registered.'
                    );
                }
            }

            $detail = (string) ($reply['detail'] ?? 'Domain availability lookup failed');
            return DomainAvailabilityResult::unavailable($domain, $detail);
        } catch (Throwable $e) {
            return DomainAvailabilityResult::unavailable($domain, 'Registrar connection error: ' . $e->getMessage());
        }
    }

    public function registerDomain(DomainRegistrationCommand $command): RegistrarOperationResult
    {
        try {
            $params = [
                'domain' => $command->getDomain(),
                'years' => (string) $command->getYears(),
                'private' => $command->hasWhoisPrivacy() ? '1' : '0',
            ];

            // Map nameservers
            $nsList = $command->getNameservers();
            foreach ($nsList as $idx => $ns) {
                $params['ns' . ($idx + 1)] = $ns;
            }

            $response = $this->sendApiRequest('registerDomain', $params);
            $reply = $response['reply'] ?? [];
            $code = (int) ($reply['code'] ?? 0);

            if ($code === 300) {
                $orderId = isset($reply['order_id']) ? (string) $reply['order_id'] : null;
                $expiry = date('Y-m-d', strtotime("+{$command->getYears()} years"));

                return RegistrarOperationResult::success(
                    operation: 'register',
                    domain: $command->getDomain(),
                    remoteTransactionId: $orderId,
                    expirationDate: $expiry,
                    rawResponse: $this->sanitizePayload($response)
                );
            }

            $detail = (string) ($reply['detail'] ?? 'Domain registration failed at registrar.');
            return RegistrarOperationResult::failure(
                operation: 'register',
                domain: $command->getDomain(),
                errorCode: (string) $code,
                errorMessage: $detail,
                rawResponse: $this->sanitizePayload($response)
            );
        } catch (Throwable $e) {
            return RegistrarOperationResult::failure(
                operation: 'register',
                domain: $command->getDomain(),
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    public function renewDomain(DomainRenewalCommand $command): RegistrarOperationResult
    {
        try {
            $params = [
                'domain' => $command->getDomain(),
                'years' => (string) $command->getYears(),
            ];

            $response = $this->sendApiRequest('renewDomain', $params);
            $reply = $response['reply'] ?? [];
            $code = (int) ($reply['code'] ?? 0);

            if ($code === 300) {
                $orderId = isset($reply['order_id']) ? (string) $reply['order_id'] : null;
                $expiry = date('Y-m-d', strtotime("+{$command->getYears()} years"));

                return RegistrarOperationResult::success(
                    operation: 'renew',
                    domain: $command->getDomain(),
                    remoteTransactionId: $orderId,
                    expirationDate: $expiry,
                    rawResponse: $this->sanitizePayload($response)
                );
            }

            $detail = (string) ($reply['detail'] ?? 'Domain renewal failed at registrar.');
            return RegistrarOperationResult::failure(
                operation: 'renew',
                domain: $command->getDomain(),
                errorCode: (string) $code,
                errorMessage: $detail,
                rawResponse: $this->sanitizePayload($response)
            );
        } catch (Throwable $e) {
            return RegistrarOperationResult::failure(
                operation: 'renew',
                domain: $command->getDomain(),
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    public function transferDomain(DomainTransferCommand $command): RegistrarOperationResult
    {
        try {
            $params = [
                'domain' => $command->getDomain(),
                'auth' => $command->getAuthCode(),
                'private' => $command->hasWhoisPrivacy() ? '1' : '0',
            ];

            $response = $this->sendApiRequest('transferDomain', $params);
            $reply = $response['reply'] ?? [];
            $code = (int) ($reply['code'] ?? 0);

            if ($code === 300) {
                $orderId = isset($reply['order_id']) ? (string) $reply['order_id'] : null;

                return RegistrarOperationResult::success(
                    operation: 'transfer',
                    domain: $command->getDomain(),
                    remoteTransactionId: $orderId,
                    rawResponse: $this->sanitizePayload($response)
                );
            }

            $detail = (string) ($reply['detail'] ?? 'Domain transfer failed at registrar.');
            return RegistrarOperationResult::failure(
                operation: 'transfer',
                domain: $command->getDomain(),
                errorCode: (string) $code,
                errorMessage: $detail,
                rawResponse: $this->sanitizePayload($response)
            );
        } catch (Throwable $e) {
            return RegistrarOperationResult::failure(
                operation: 'transfer',
                domain: $command->getDomain(),
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    public function getNameservers(string $domain): array
    {
        try {
            $response = $this->sendApiRequest('getDomainInfo', [
                'domain' => $domain,
            ]);

            $reply = $response['reply'] ?? [];
            if ((int) ($reply['code'] ?? 0) !== 300) {
                return [];
            }

            $nameservers = [];
            $nsData = $reply['nameservers'] ?? null;

            if (is_array($nsData)) {
                $rawNs = $nsData['nameserver'] ?? $nsData;
                if (is_array($rawNs)) {
                    foreach ($rawNs as $ns) {
                        if (is_string($ns) && trim($ns) !== '') {
                            $nameservers[] = strtolower(trim($ns));
                        }
                    }
                } elseif (is_string($rawNs) && trim($rawNs) !== '') {
                    $nameservers[] = strtolower(trim($rawNs));
                }
            }

            return array_values(array_unique($nameservers));
        } catch (Throwable) {
            return [];
        }
    }

    public function updateNameservers(string $domain, array $nameservers): RegistrarOperationResult
    {
        try {
            $params = [
                'domain' => $domain,
            ];

            foreach (array_values($nameservers) as $idx => $ns) {
                $params['ns' . ($idx + 1)] = $ns;
            }

            $response = $this->sendApiRequest('domainChangeNameServers', $params);
            $reply = $response['reply'] ?? [];
            $code = (int) ($reply['code'] ?? 0);

            if ($code === 300) {
                return RegistrarOperationResult::success(
                    operation: 'update_nameservers',
                    domain: $domain,
                    rawResponse: $this->sanitizePayload($response)
                );
            }

            return RegistrarOperationResult::failure(
                operation: 'update_nameservers',
                domain: $domain,
                errorCode: (string) $code,
                errorMessage: (string) ($reply['detail'] ?? 'Nameserver update failed at registrar.'),
                rawResponse: $this->sanitizePayload($response)
            );
        } catch (Throwable $e) {
            return RegistrarOperationResult::failure(
                operation: 'update_nameservers',
                domain: $domain,
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    public function getRegistrarLock(string $domain): bool
    {
        try {
            $response = $this->sendApiRequest('getDomainInfo', [
                'domain' => $domain,
            ]);

            $reply = $response['reply'] ?? [];
            if ((int) ($reply['code'] ?? 0) !== 300) {
                return false;
            }

            $locked = strtolower((string) ($reply['locked'] ?? 'no'));
            return $locked === 'yes';
        } catch (Throwable) {
            return false;
        }
    }

    public function setRegistrarLock(string $domain, bool $locked): RegistrarOperationResult
    {
        try {
            $operation = $locked ? 'domainLock' : 'domainUnlock';
            $response = $this->sendApiRequest($operation, [
                'domain' => $domain,
            ]);

            $reply = $response['reply'] ?? [];
            $code = (int) ($reply['code'] ?? 0);

            if ($code === 300) {
                return RegistrarOperationResult::success(
                    operation: 'set_lock',
                    domain: $domain,
                    rawResponse: $this->sanitizePayload($response)
                );
            }

            return RegistrarOperationResult::failure(
                operation: 'set_lock',
                domain: $domain,
                errorCode: (string) $code,
                errorMessage: (string) ($reply['detail'] ?? 'Lock change failed at registrar.'),
                rawResponse: $this->sanitizePayload($response)
            );
        } catch (Throwable $e) {
            return RegistrarOperationResult::failure(
                operation: 'set_lock',
                domain: $domain,
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    public function getEppCode(string $domain): ?string
    {
        try {
            $response = $this->sendApiRequest('retrieveAuthCode', [
                'domain' => $domain,
            ]);

            $reply = $response['reply'] ?? [];
            if ((int) ($reply['code'] ?? 0) === 300) {
                $code = $reply['auth_code'] ?? ($reply['detail'] ?? null);
                return $code !== null ? (string) $code : null;
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    public function getContacts(string $domain): array
    {
        try {
            $response = $this->sendApiRequest('getDomainInfo', [
                'domain' => $domain,
            ]);

            $reply = $response['reply'] ?? [];
            if ((int) ($reply['code'] ?? 0) !== 300) {
                return [];
            }

            $contacts = [];
            $contactData = $reply['contact'] ?? [];

            if (!empty($contactData) && is_array($contactData)) {
                $contacts[DomainContact::TYPE_REGISTRANT] = new DomainContact(
                    id: 1,
                    domainId: 1,
                    contactType: DomainContact::TYPE_REGISTRANT,
                    firstName: (string) ($contactData['first_name'] ?? 'Registrant'),
                    lastName: (string) ($contactData['last_name'] ?? 'Owner'),
                    companyName: isset($contactData['company']) ? (string) $contactData['company'] : null,
                    email: (string) ($contactData['email'] ?? 'admin@' . $domain),
                    phone: (string) ($contactData['phone'] ?? '+1.5550000000'),
                    addressLine1: isset($contactData['address']) ? (string) $contactData['address'] : 'Street 1',
                    city: isset($contactData['city']) ? (string) $contactData['city'] : 'City',
                    state: isset($contactData['state']) ? (string) $contactData['state'] : 'State',
                    postalCode: isset($contactData['zip']) ? (string) $contactData['zip'] : '00000',
                    countryCode: isset($contactData['country']) ? strtoupper((string) $contactData['country']) : 'US'
                );
            }

            return $contacts;
        } catch (Throwable) {
            return [];
        }
    }

    public function updateContacts(string $domain, array $contacts): RegistrarOperationResult
    {
        try {
            /** @var DomainContact|null $registrant */
            $registrant = $contacts[DomainContact::TYPE_REGISTRANT] ?? (reset($contacts) ?: null);

            if ($registrant === null) {
                return RegistrarOperationResult::failure(
                    operation: 'update_contacts',
                    domain: $domain,
                    errorCode: 'INVALID_CONTACT',
                    errorMessage: 'At least one contact profile must be provided.'
                );
            }

            $params = [
                'domain' => $domain,
                'fn' => $registrant->getFirstName(),
                'ln' => $registrant->getLastName(),
                'em' => $registrant->getEmail(),
                'ph' => $registrant->getPhone(),
                'ad' => $registrant->getAddressLine1() !== '' ? $registrant->getAddressLine1() : 'Street 1',
                'cy' => $registrant->getCity() !== '' ? $registrant->getCity() : 'City',
                'st' => $registrant->getState() ?? 'State',
                'zp' => $registrant->getPostalCode() !== '' ? $registrant->getPostalCode() : '00000',
                'ct' => $registrant->getCountryCode(),
            ];

            if ($registrant->getCompanyName() !== null) {
                $params['cp'] = $registrant->getCompanyName();
            }

            $response = $this->sendApiRequest('domainUpdateContacts', $params);
            $reply = $response['reply'] ?? [];
            $code = (int) ($reply['code'] ?? 0);

            if ($code === 300) {
                return RegistrarOperationResult::success(
                    operation: 'update_contacts',
                    domain: $domain,
                    rawResponse: $this->sanitizePayload($response)
                );
            }

            return RegistrarOperationResult::failure(
                operation: 'update_contacts',
                domain: $domain,
                errorCode: (string) $code,
                errorMessage: (string) ($reply['detail'] ?? 'Contact update failed at registrar.'),
                rawResponse: $this->sanitizePayload($response)
            );
        } catch (Throwable $e) {
            return RegistrarOperationResult::failure(
                operation: 'update_contacts',
                domain: $domain,
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    /**
     * @param string $operation
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>
     */
    public function sendApiRequest(string $operation, array $params = []): array
    {
        $endpoint = $this->config->getEndpoint();
        if ($endpoint === '' || $endpoint === null) {
            $endpoint = $this->config->isSandbox() ? self::DEFAULT_SANDBOX_ENDPOINT : self::DEFAULT_PROD_ENDPOINT;
        }

        $mergedParams = array_filter(
            array_merge([
                'version' => '1',
                'type' => 'json',
                'key' => $this->config->getApiKey(),
            ], $params),
            fn($val) => $val !== null
        );

        $url = rtrim($endpoint, '/') . '/' . $operation . '?' . http_build_query($mergedParams);

        if ($this->httpClient !== null) {
            $res = ($this->httpClient)($operation, $mergedParams, $url);
            if (is_array($res)) {
                return $res;
            }
            if (is_string($res)) {
                return $this->parseResponse($res);
            }
            return [];
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json, text/xml\r\nUser-Agent: ColezaHost-Registrar/1.0\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $rawBody = @file_get_contents($url, false, $ctx);
        if ($rawBody === false) {
            throw new \RuntimeException('Failed to connect to NameSilo registrar API endpoint.');
        }

        return $this->parseResponse($rawBody);
    }

    /**
     * @param string $rawBody
     * @return array<string, mixed>
     */
    public function parseResponse(string $rawBody): array
    {
        $trimmed = trim($rawBody);
        if (str_starts_with($trimmed, '{')) {
            try {
                return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR) ?? [];
            } catch (\JsonException) {
                // fall through to xml
            }
        }

        if (str_starts_with($trimmed, '<')) {
            $xml = @simplexml_load_string($trimmed, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml !== false) {
                $encoded = json_encode($xml);
                if ($encoded !== false) {
                    return json_decode($encoded, true) ?? [];
                }
            }
        }

        return [];
    }

    /**
     * Masks any secrets in raw payloads before logging or returning in DTOs.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitizePayload(array $payload): array
    {
        $apiKey = (string) ($this->config->getApiKey() ?? '');
        $apiSecret = (string) ($this->config->getApiSecret() ?? '');

        $sanitize = function ($data) use (&$sanitize, $apiKey, $apiSecret) {
            if (is_array($data)) {
                $cleaned = [];
                foreach ($data as $key => $val) {
                    if (in_array(strtolower((string) $key), ['key', 'apikey', 'api_key', 'apisecret', 'api_secret', 'secret'], true)) {
                        $cleaned[$key] = '••••••••';
                    } else {
                        $cleaned[$key] = $sanitize($val);
                    }
                }
                return $cleaned;
            }
            if (is_string($data)) {
                if ($apiKey !== '' && str_contains($data, $apiKey)) {
                    $data = str_replace($apiKey, '••••••••', $data);
                }
                if ($apiSecret !== '' && str_contains($data, $apiSecret)) {
                    $data = str_replace($apiSecret, '••••••••', $data);
                }
            }
            return $data;
        };

        return $sanitize($payload);
    }
}
