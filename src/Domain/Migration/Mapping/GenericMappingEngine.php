<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Mapping;

use Coleza\Domain\Migration\Canonical\CanonicalClientDto;
use Coleza\Domain\Migration\Canonical\CanonicalDomainDto;
use Coleza\Domain\Migration\Canonical\CanonicalEntityInterface;
use Coleza\Domain\Migration\Canonical\CanonicalInvoiceDto;
use Coleza\Domain\Migration\Canonical\CanonicalPaymentDto;
use Coleza\Domain\Migration\Canonical\CanonicalProductDto;
use Coleza\Domain\Migration\Canonical\CanonicalServiceDto;
use Coleza\Domain\Migration\Canonical\CanonicalTicketDto;
use Coleza\Foundation\Exceptions\ValidationException;

final class GenericMappingEngine implements MappingEngineInterface
{
    /**
     * @var array<string, array<string, callable>>
     */
    private array $customTransformers = [];

    public function registerTransformer(string $entityType, string $field, callable $transformer): void
    {
        $this->customTransformers[$entityType][$field] = $transformer;
    }

    public function map(string $sourceSystem, string $entityType, array $rawPayload): CanonicalEntityInterface
    {
        return match (strtolower(trim($entityType))) {
            'client', 'clients', 'user', 'users' => $this->mapClient($sourceSystem, $rawPayload),
            'product', 'products', 'package' => $this->mapProduct($sourceSystem, $rawPayload),
            'service', 'services', 'hosting' => $this->mapService($sourceSystem, $rawPayload),
            'domain', 'domains' => $this->mapDomain($sourceSystem, $rawPayload),
            'invoice', 'invoices' => $this->mapInvoice($sourceSystem, $rawPayload),
            'payment', 'payments', 'transaction', 'transactions' => $this->mapPayment($sourceSystem, $rawPayload),
            'ticket', 'tickets' => $this->mapTicket($sourceSystem, $rawPayload),
            default => throw new ValidationException(
                ['entity_type' => "Unsupported canonical migration entity type: {$entityType}"],
                'Unsupported entity type'
            ),
        };
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapClient(string $sourceSystem, array $raw): CanonicalClientDto
    {
        $sourceId = (string) ($raw['id'] ?? $raw['client_id'] ?? $raw['userid'] ?? '');
        $firstName = trim((string) ($raw['firstname'] ?? $raw['first_name'] ?? ''));
        $lastName = trim((string) ($raw['lastname'] ?? $raw['last_name'] ?? ''));
        $email = strtolower(trim((string) ($raw['email'] ?? '')));
        $company = isset($raw['companyname']) ? (string)$raw['companyname'] : (isset($raw['company']) ? (string)$raw['company'] : (isset($raw['company_name']) ? (string)$raw['company_name'] : null));
        $phone = isset($raw['phonenumber']) ? (string)$raw['phonenumber'] : (isset($raw['phone']) ? (string)$raw['phone'] : (isset($raw['phone_number']) ? (string)$raw['phone_number'] : null));
        $address1 = isset($raw['address1']) ? (string)$raw['address1'] : (isset($raw['address_line1']) ? (string)$raw['address_line1'] : null);
        $address2 = isset($raw['address2']) ? (string)$raw['address2'] : (isset($raw['address_line2']) ? (string)$raw['address_line2'] : null);
        $city = isset($raw['city']) ? (string)$raw['city'] : null;
        $state = isset($raw['state']) ? (string)$raw['state'] : null;
        $postcode = isset($raw['postcode']) ? (string)$raw['postcode'] : (isset($raw['zip']) ? (string)$raw['zip'] : null);
        $country = isset($raw['country']) ? strtoupper(trim((string)$raw['country'])) : (isset($raw['country_code']) ? strtoupper(trim((string)$raw['country_code'])) : null);
        $currency = isset($raw['currency']) ? strtoupper(trim((string)$raw['currency'])) : 'USD';

        $rawStatus = strtolower(trim((string) ($raw['status'] ?? 'active')));
        $status = match ($rawStatus) {
            'active' => 'active',
            'inactive' => 'inactive',
            'closed' => 'closed',
            default => 'active',
        };

        $taxExempt = !empty($raw['taxexempt']) && ($raw['taxexempt'] === 'on' || $raw['taxexempt'] === 1 || $raw['taxexempt'] === true || $raw['taxexempt'] === '1');
        $createdAt = isset($raw['datecreated']) ? (string)$raw['datecreated'] : (isset($raw['created_at']) ? (string)$raw['created_at'] : null);

        $customFields = (array) ($raw['customfields'] ?? $raw['custom_fields'] ?? []);

        // Retain unmapped keys into metadata for zero silent loss
        $mappedKeys = [
            'id', 'client_id', 'userid', 'firstname', 'first_name', 'lastname', 'last_name', 'email',
            'companyname', 'company', 'company_name', 'phonenumber', 'phone', 'phone_number',
            'address1', 'address_line1', 'address2', 'address_line2',
            'city', 'state', 'postcode', 'zip', 'country', 'country_code', 'currency', 'status',
            'taxexempt', 'datecreated', 'created_at', 'customfields', 'custom_fields', 'unmapped_source_columns',
        ];
        $unsupported = array_diff_key($raw, array_flip($mappedKeys));

        return new CanonicalClientDto(
            sourceId: $sourceId,
            sourceSystem: $sourceSystem,
            firstName: $firstName,
            lastName: $lastName,
            email: $email,
            companyName: $company,
            phoneNumber: $phone,
            addressLine1: $address1,
            addressLine2: $address2,
            city: $city,
            state: $state,
            postcode: $postcode,
            countryCode: $country,
            currency: $currency,
            status: $status,
            taxExempt: $taxExempt,
            createdAt: $createdAt,
            customFields: $customFields,
            metadata: ['unsupported_source_fields' => $unsupported]
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapProduct(string $sourceSystem, array $raw): CanonicalProductDto
    {
        $sourceId = (string) ($raw['id'] ?? $raw['product_id'] ?? '');
        $name = trim((string) ($raw['name'] ?? ''));
        $rawType = strtolower(trim((string) ($raw['type'] ?? 'hosting')));
        $type = match ($rawType) {
            'hostingaccount', 'hosting' => 'hosting',
            'reselleraccount', 'reseller' => 'reseller',
            'server', 'dedicatedserver' => 'server',
            'other' => 'other',
            default => 'hosting',
        };

        $description = (string) ($raw['description'] ?? '');
        $price = (float) ($raw['price'] ?? $raw['monthly'] ?? 0.0);
        $currency = isset($raw['currency']) ? strtoupper(trim((string)$raw['currency'])) : 'USD';
        $billingCycle = strtolower(trim((string) ($raw['paytype'] ?? $raw['billing_cycle'] ?? 'monthly')));
        $module = isset($raw['servertype']) ? (string)$raw['servertype'] : (isset($raw['module']) ? (string)$raw['module'] : null);
        $packageName = isset($raw['configoption1']) ? (string)$raw['configoption1'] : (isset($raw['package_name']) ? (string)$raw['package_name'] : null);
        $isActive = !isset($raw['retired']) || (int)$raw['retired'] === 0;

        $mappedKeys = ['id', 'product_id', 'name', 'type', 'description', 'price', 'monthly', 'currency', 'paytype', 'billing_cycle', 'servertype', 'module', 'configoption1', 'package_name', 'retired'];
        $unsupported = array_diff_key($raw, array_flip($mappedKeys));

        return new CanonicalProductDto(
            sourceId: $sourceId,
            sourceSystem: $sourceSystem,
            name: $name,
            type: $type,
            description: $description,
            price: $price,
            currency: $currency,
            billingCycle: $billingCycle,
            module: $module,
            packageName: $packageName,
            isActive: $isActive,
            metadata: ['unsupported_source_fields' => $unsupported]
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapService(string $sourceSystem, array $raw): CanonicalServiceDto
    {
        $sourceId = (string) ($raw['id'] ?? $raw['service_id'] ?? '');
        $clientSourceId = (string) ($raw['userid'] ?? $raw['client_id'] ?? '');
        $productSourceId = (string) ($raw['packageid'] ?? $raw['product_id'] ?? '');
        $domain = isset($raw['domain']) ? trim((string)$raw['domain']) : null;
        $username = isset($raw['username']) ? trim((string)$raw['username']) : null;
        $dedicatedIp = isset($raw['dedicatedip']) ? trim((string)$raw['dedicatedip']) : null;

        $rawStatus = strtolower(trim((string) ($raw['domainstatus'] ?? $raw['status'] ?? 'active')));
        $status = match ($rawStatus) {
            'active' => 'active',
            'suspended' => 'suspended',
            'terminated' => 'terminated',
            'cancelled' => 'cancelled',
            'pending' => 'pending',
            default => 'active',
        };

        $billingCycle = strtolower(trim((string) ($raw['billingcycle'] ?? $raw['billing_cycle'] ?? 'monthly')));
        $amount = (float) ($raw['amount'] ?? $raw['firstpaymentamount'] ?? 0.0);
        $currency = isset($raw['currency']) ? strtoupper(trim((string)$raw['currency'])) : 'USD';
        $regDate = isset($raw['regdate']) ? (string)$raw['regdate'] : (isset($raw['registration_date']) ? (string)$raw['registration_date'] : null);
        $nextDueDate = isset($raw['nextduedate']) ? (string)$raw['nextduedate'] : (isset($raw['next_due_date']) ? (string)$raw['next_due_date'] : null);
        $suspendReason = isset($raw['suspendreason']) ? (string)$raw['suspendreason'] : null;

        $mappedKeys = ['id', 'service_id', 'userid', 'client_id', 'packageid', 'product_id', 'domain', 'username', 'dedicatedip', 'domainstatus', 'status', 'billingcycle', 'billing_cycle', 'amount', 'firstpaymentamount', 'currency', 'regdate', 'registration_date', 'nextduedate', 'next_due_date', 'suspendreason'];
        $unsupported = array_diff_key($raw, array_flip($mappedKeys));

        return new CanonicalServiceDto(
            sourceId: $sourceId,
            sourceSystem: $sourceSystem,
            clientSourceId: $clientSourceId,
            productSourceId: $productSourceId,
            domain: $domain,
            username: $username,
            dedicatedIp: $dedicatedIp,
            status: $status,
            billingCycle: $billingCycle,
            recurringAmount: $amount,
            currency: $currency,
            registrationDate: $regDate,
            nextDueDate: $nextDueDate,
            suspensionReason: $suspendReason,
            metadata: ['unsupported_source_fields' => $unsupported]
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapDomain(string $sourceSystem, array $raw): CanonicalDomainDto
    {
        $sourceId = (string) ($raw['id'] ?? $raw['domain_id'] ?? '');
        $clientSourceId = (string) ($raw['userid'] ?? $raw['client_id'] ?? '');
        $domainName = strtolower(trim((string) ($raw['domain'] ?? '')));
        $registrar = isset($raw['registrar']) ? trim((string)$raw['registrar']) : null;

        $rawStatus = strtolower(trim((string) ($raw['status'] ?? 'active')));
        $status = match ($rawStatus) {
            'active' => 'active',
            'expired' => 'expired',
            'cancelled' => 'cancelled',
            'transferredout' => 'transferred_out',
            'pending' => 'pending',
            default => 'active',
        };

        $recurringAmount = (float) ($raw['recurringamount'] ?? 0.0);
        $currency = isset($raw['currency']) ? strtoupper(trim((string)$raw['currency'])) : 'USD';
        $years = (int) ($raw['registrationperiod'] ?? 1);
        $regDate = isset($raw['registrationdate']) ? (string)$raw['registrationdate'] : null;
        $expiryDate = isset($raw['expirydate']) ? (string)$raw['expirydate'] : null;
        $nextDueDate = isset($raw['nextduedate']) ? (string)$raw['nextduedate'] : null;
        $autoRenew = !isset($raw['donotrenew']) || (int)$raw['donotrenew'] === 0;
        $idProtection = isset($raw['idprotection']) && ((int)$raw['idprotection'] === 1 || $raw['idprotection'] === true);

        $mappedKeys = ['id', 'domain_id', 'userid', 'client_id', 'domain', 'registrar', 'status', 'recurringamount', 'currency', 'registrationperiod', 'registrationdate', 'expirydate', 'nextduedate', 'donotrenew', 'idprotection'];
        $unsupported = array_diff_key($raw, array_flip($mappedKeys));

        return new CanonicalDomainDto(
            sourceId: $sourceId,
            sourceSystem: $sourceSystem,
            clientSourceId: $clientSourceId,
            domainName: $domainName,
            registrar: $registrar,
            status: $status,
            recurringAmount: $recurringAmount,
            currency: $currency,
            registrationPeriodYears: $years,
            registrationDate: $regDate,
            expiryDate: $expiryDate,
            nextDueDate: $nextDueDate,
            autoRenew: $autoRenew,
            idProtection: $idProtection,
            metadata: ['unsupported_source_fields' => $unsupported]
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapInvoice(string $sourceSystem, array $raw): CanonicalInvoiceDto
    {
        $sourceId = (string) ($raw['id'] ?? $raw['invoice_id'] ?? '');
        $clientSourceId = (string) ($raw['userid'] ?? $raw['client_id'] ?? '');
        $invoiceNum = (string) ($raw['invoicenum'] ?? $raw['id'] ?? '');
        $subtotal = (float) ($raw['subtotal'] ?? 0.0);
        $tax = (float) ($raw['tax'] ?? 0.0) + (float) ($raw['tax2'] ?? 0.0);
        $total = (float) ($raw['total'] ?? ($subtotal + $tax));
        $currency = isset($raw['currency']) ? strtoupper(trim((string)$raw['currency'])) : 'USD';

        $rawStatus = strtolower(trim((string) ($raw['status'] ?? 'paid')));
        $status = match ($rawStatus) {
            'paid' => 'paid',
            'unpaid' => 'unpaid',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'collections' => 'collections',
            default => 'unpaid',
        };

        $date = isset($raw['date']) ? (string)$raw['date'] : null;
        $dueDate = isset($raw['duedate']) ? (string)$raw['duedate'] : (isset($raw['due_date']) ? (string)$raw['due_date'] : null);
        $datePaid = isset($raw['datepaid']) ? (string)$raw['datepaid'] : (isset($raw['date_paid']) ? (string)$raw['date_paid'] : null);
        $lineItems = (array) ($raw['items'] ?? $raw['line_items'] ?? []);

        $mappedKeys = [
            'id', 'invoice_id', 'userid', 'client_id', 'invoicenum', 'invoice_number',
            'subtotal', 'tax', 'tax2', 'total', 'currency', 'status',
            'date', 'duedate', 'due_date', 'datepaid', 'date_paid', 'items', 'line_items',
            'unmapped_source_columns',
        ];
        $unsupported = array_diff_key($raw, array_flip($mappedKeys));

        return new CanonicalInvoiceDto(
            sourceId: $sourceId,
            sourceSystem: $sourceSystem,
            clientSourceId: $clientSourceId,
            invoiceNumber: $invoiceNum,
            subtotal: $subtotal,
            tax: $tax,
            total: $total,
            currency: $currency,
            status: $status,
            date: $date,
            dueDate: $dueDate,
            datePaid: $datePaid,
            lineItems: $lineItems,
            metadata: ['unsupported_source_fields' => $unsupported]
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapPayment(string $sourceSystem, array $raw): CanonicalPaymentDto
    {
        $sourceId = (string) ($raw['id'] ?? $raw['transaction_id'] ?? '');
        $clientSourceId = (string) ($raw['userid'] ?? $raw['client_id'] ?? '');
        $invoiceSourceId = isset($raw['invoiceid']) ? (string)$raw['invoiceid'] : null;
        $amount = (float) ($raw['amountin'] ?? $raw['amount'] ?? 0.0);
        $fee = (float) ($raw['fees'] ?? $raw['fee'] ?? 0.0);
        $currency = isset($raw['currency']) ? strtoupper(trim((string)$raw['currency'])) : 'USD';
        $txnId = isset($raw['transid']) ? (string)$raw['transid'] : (isset($raw['transaction_id']) ? (string)$raw['transaction_id'] : null);
        $gateway = strtolower(trim((string) ($raw['gateway'] ?? 'banktransfer')));
        $status = strtolower(trim((string) ($raw['status'] ?? 'completed')));
        $paymentDate = isset($raw['date']) ? (string)$raw['date'] : null;

        $mappedKeys = ['id', 'transaction_id', 'userid', 'client_id', 'invoiceid', 'amountin', 'amount', 'fees', 'fee', 'currency', 'transid', 'gateway', 'status', 'date'];
        $unsupported = array_diff_key($raw, array_flip($mappedKeys));

        return new CanonicalPaymentDto(
            sourceId: $sourceId,
            sourceSystem: $sourceSystem,
            clientSourceId: $clientSourceId,
            invoiceSourceId: $invoiceSourceId,
            amount: $amount,
            feeAmount: $fee,
            currency: $currency,
            transactionId: $txnId,
            gateway: $gateway,
            status: $status,
            paymentDate: $paymentDate,
            metadata: ['unsupported_source_fields' => $unsupported]
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapTicket(string $sourceSystem, array $raw): CanonicalTicketDto
    {
        $sourceId = (string) ($raw['id'] ?? $raw['ticket_id'] ?? '');
        $clientSourceId = (string) ($raw['userid'] ?? $raw['client_id'] ?? '');
        $dept = (string) ($raw['did'] ?? $raw['department'] ?? 'General Support');
        $subject = trim((string) ($raw['title'] ?? $raw['subject'] ?? ''));
        $message = (string) ($raw['message'] ?? '');
        $priority = strtolower(trim((string) ($raw['urgency'] ?? $raw['priority'] ?? 'medium')));
        $status = strtolower(trim((string) ($raw['status'] ?? 'closed')));
        $ticketMask = isset($raw['tid']) ? (string)$raw['tid'] : null;
        $createdAt = isset($raw['date']) ? (string)$raw['date'] : null;
        $lastReplyAt = isset($raw['lastreply']) ? (string)$raw['lastreply'] : null;
        $replies = (array) ($raw['replies'] ?? []);

        $mappedKeys = ['id', 'ticket_id', 'userid', 'client_id', 'did', 'department', 'title', 'subject', 'message', 'urgency', 'priority', 'status', 'tid', 'date', 'lastreply', 'replies'];
        $unsupported = array_diff_key($raw, array_flip($mappedKeys));

        return new CanonicalTicketDto(
            sourceId: $sourceId,
            sourceSystem: $sourceSystem,
            clientSourceId: $clientSourceId,
            department: $dept,
            subject: $subject,
            message: $message,
            priority: $priority,
            status: $status,
            ticketMask: $ticketMask,
            createdAt: $createdAt,
            lastReplyAt: $lastReplyAt,
            replies: $replies,
            metadata: ['unsupported_source_fields' => $unsupported]
        );
    }
}
