<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Validation;

use Coleza\Domain\Migration\Canonical\CanonicalClientDto;
use Coleza\Domain\Migration\Canonical\CanonicalDomainDto;
use Coleza\Domain\Migration\Canonical\CanonicalEntityInterface;
use Coleza\Domain\Migration\Canonical\CanonicalInvoiceDto;
use Coleza\Domain\Migration\Canonical\CanonicalPaymentDto;
use Coleza\Domain\Migration\Canonical\CanonicalProductDto;
use Coleza\Domain\Migration\Canonical\CanonicalServiceDto;
use Coleza\Domain\Migration\Canonical\CanonicalTicketDto;

final class CanonicalValidationEngine
{
    public function validate(CanonicalEntityInterface $dto): ValidationResult
    {
        $errors = [];
        $warnings = [];

        if (trim($dto->getSourceId()) === '') {
            $errors[] = 'Source ID is missing or empty.';
        }

        if (trim($dto->getSourceSystem()) === '') {
            $errors[] = 'Source system identifier is missing.';
        }

        match (true) {
            $dto instanceof CanonicalClientDto => $this->validateClient($dto, $errors, $warnings),
            $dto instanceof CanonicalProductDto => $this->validateProduct($dto, $errors, $warnings),
            $dto instanceof CanonicalServiceDto => $this->validateService($dto, $errors, $warnings),
            $dto instanceof CanonicalDomainDto => $this->validateDomain($dto, $errors, $warnings),
            $dto instanceof CanonicalInvoiceDto => $this->validateInvoice($dto, $errors, $warnings),
            $dto instanceof CanonicalPaymentDto => $this->validatePayment($dto, $errors, $warnings),
            $dto instanceof CanonicalTicketDto => $this->validateTicket($dto, $errors, $warnings),
            default => null,
        };

        return new ValidationResult($errors, $warnings);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateClient(CanonicalClientDto $client, array &$errors, array &$warnings): void
    {
        if (trim($client->getFirstName()) === '') {
            $errors[] = 'Client first name is required.';
        }

        $email = $client->getEmail();
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Client email [{$email}] is invalid or missing.";
        }

        if ($client->getCountryCode() !== null && strlen($client->getCountryCode()) !== 2) {
            $warnings[] = "Client country code [{$client->getCountryCode()}] is not a 2-letter ISO code.";
        }

        if (strlen($client->getCurrency()) !== 3) {
            $errors[] = "Client currency [{$client->getCurrency()}] must be a 3-character ISO code.";
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateProduct(CanonicalProductDto $product, array &$errors, array &$warnings): void
    {
        if (trim($product->getName()) === '') {
            $errors[] = 'Product name is required.';
        }

        if ($product->getPrice() < 0.0) {
            $errors[] = 'Product price cannot be negative.';
        }

        if (strlen($product->getCurrency()) !== 3) {
            $errors[] = "Product currency [{$product->getCurrency()}] must be a 3-character ISO code.";
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateService(CanonicalServiceDto $service, array &$errors, array &$warnings): void
    {
        if (trim($service->getClientSourceId()) === '') {
            $errors[] = 'Service is missing client association (clientSourceId).';
        }

        if (trim($service->getProductSourceId()) === '') {
            $errors[] = 'Service is missing product association (productSourceId).';
        }

        if ($service->getRecurringAmount() < 0.0) {
            $errors[] = 'Service recurring amount cannot be negative.';
        }

        if (strlen($service->getCurrency()) !== 3) {
            $errors[] = "Service currency [{$service->getCurrency()}] must be a 3-character ISO code.";
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateDomain(CanonicalDomainDto $domain, array &$errors, array &$warnings): void
    {
        if (trim($domain->getClientSourceId()) === '') {
            $errors[] = 'Domain is missing client association (clientSourceId).';
        }

        $domainName = $domain->getDomainName();
        if ($domainName === '' || !str_contains($domainName, '.') || str_contains($domainName, ' ')) {
            $errors[] = "Domain name [{$domainName}] is invalid.";
        }

        if ($domain->getRegistrationPeriodYears() < 1) {
            $errors[] = 'Registration period must be at least 1 year.';
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateInvoice(CanonicalInvoiceDto $invoice, array &$errors, array &$warnings): void
    {
        if (trim($invoice->getClientSourceId()) === '') {
            $errors[] = 'Invoice is missing client association (clientSourceId).';
        }

        if (strlen($invoice->getCurrency()) !== 3) {
            $errors[] = "Invoice currency [{$invoice->getCurrency()}] must be a 3-character ISO code.";
        }

        $expectedTotal = round($invoice->getSubtotal() + $invoice->getTax(), 2);
        $actualTotal = round($invoice->getTotal(), 2);
        if (abs($actualTotal - $expectedTotal) > 0.05) {
            $errors[] = sprintf(
                'Invoice math inconsistency: total (%.2f) does not match subtotal (%.2f) + tax (%.2f) [expected: %.2f].',
                $actualTotal,
                $invoice->getSubtotal(),
                $invoice->getTax(),
                $expectedTotal
            );
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validatePayment(CanonicalPaymentDto $payment, array &$errors, array &$warnings): void
    {
        if (trim($payment->getClientSourceId()) === '') {
            $errors[] = 'Payment is missing client association (clientSourceId).';
        }

        if ($payment->getAmount() <= 0.0) {
            $errors[] = 'Payment amount must be greater than zero.';
        }

        if (strlen($payment->getCurrency()) !== 3) {
            $errors[] = "Payment currency [{$payment->getCurrency()}] must be a 3-character ISO code.";
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateTicket(CanonicalTicketDto $ticket, array &$errors, array &$warnings): void
    {
        if (trim($ticket->getClientSourceId()) === '') {
            $errors[] = 'Ticket is missing client association (clientSourceId).';
        }

        if (trim($ticket->getSubject()) === '') {
            $errors[] = 'Ticket subject is required.';
        }
    }
}
