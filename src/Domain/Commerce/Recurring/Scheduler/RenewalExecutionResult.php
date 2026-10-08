<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Recurring\Scheduler;

use Coleza\Domain\Commerce\Invoices\Invoice;

final class RenewalExecutionResult
{
    public function __construct(
        private readonly int $serviceId,
        private readonly bool $generated,
        private readonly ?Invoice $invoice = null,
        private readonly ?string $skippedReason = null,
        private readonly ?string $errorMessage = null,
        private readonly bool $notificationSent = false
    ) {
    }

    public static function generated(int $serviceId, Invoice $invoice, bool $notificationSent = false): self
    {
        return new self(
            serviceId: $serviceId,
            generated: true,
            invoice: $invoice,
            skippedReason: null,
            errorMessage: null,
            notificationSent: $notificationSent
        );
    }

    public static function skipped(int $serviceId, string $reason): self
    {
        return new self(
            serviceId: $serviceId,
            generated: false,
            invoice: null,
            skippedReason: $reason,
            errorMessage: null,
            notificationSent: false
        );
    }

    public static function failed(int $serviceId, string $errorMessage): self
    {
        return new self(
            serviceId: $serviceId,
            generated: false,
            invoice: null,
            skippedReason: null,
            errorMessage: $errorMessage,
            notificationSent: false
        );
    }

    public function getServiceId(): int
    {
        return $this->serviceId;
    }

    public function isGenerated(): bool
    {
        return $this->generated;
    }

    public function isSkipped(): bool
    {
        return $this->skippedReason !== null;
    }

    public function isFailed(): bool
    {
        return $this->errorMessage !== null;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function getSkippedReason(): ?string
    {
        return $this->skippedReason;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function isNotificationSent(): bool
    {
        return $this->notificationSent;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_id' => $this->serviceId,
            'generated' => $this->generated,
            'invoice_id' => $this->invoice?->getId(),
            'invoice_number' => $this->invoice?->getInvoiceNumber(),
            'skipped_reason' => $this->skippedReason,
            'error_message' => $this->errorMessage,
            'notification_sent' => $this->notificationSent,
        ];
    }
}
