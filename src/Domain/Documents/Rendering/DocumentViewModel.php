<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Rendering;

use Coleza\Domain\Documents\DocumentType;

final class DocumentViewModel
{
    /**
     * @param array<DocumentItemLine> $items
     * @param array<array{bankName: string, accountHolder: string, iban: string, swift?: ?string, currency: string}> $bankAccounts
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $documentId,
        private string $documentNumber,
        private DocumentType $type,
        private string $status,
        private string $issueDate,
        private ?string $dueDate,
        private ?string $paidAt,
        private string $currency,
        private string $currencySymbol,
        private DocumentParty $issuer,
        private DocumentParty $recipient,
        private array $items,
        private DocumentTotals $totals,
        private string $locale = 'en',
        private ?string $paymentInstructions = null,
        private array $bankAccounts = [],
        private ?string $notes = null,
        private ?string $terms = null,
        private ?string $qrCodeData = null,
        private ?string $verificationUrl = null,
        private array $metadata = []
    ) {
    }

    public function getDocumentId(): string
    {
        return $this->documentId;
    }

    public function getDocumentNumber(): string
    {
        return $this->documentNumber;
    }

    public function getType(): DocumentType
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return strtoupper($this->status);
    }

    public function getIssueDate(): string
    {
        return $this->issueDate;
    }

    public function getDueDate(): ?string
    {
        return $this->dueDate;
    }

    public function getPaidAt(): ?string
    {
        return $this->paidAt;
    }

    public function getCurrency(): string
    {
        return strtoupper($this->currency);
    }

    public function getCurrencySymbol(): string
    {
        return $this->currencySymbol;
    }

    public function getIssuer(): DocumentParty
    {
        return $this->issuer;
    }

    public function getRecipient(): DocumentParty
    {
        return $this->recipient;
    }

    /**
     * @return array<DocumentItemLine>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotals(): DocumentTotals
    {
        return $this->totals;
    }

    public function getLocale(): string
    {
        return strtolower($this->locale);
    }

    public function getPaymentInstructions(): ?string
    {
        return $this->paymentInstructions;
    }

    /**
     * @return array<array{bankName: string, accountHolder: string, iban: string, swift?: ?string, currency: string}>
     */
    public function getBankAccounts(): array
    {
        return $this->bankAccounts;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getTerms(): ?string
    {
        return $this->terms;
    }

    public function getQrCodeData(): ?string
    {
        return $this->qrCodeData;
    }

    public function getVerificationUrl(): ?string
    {
        return $this->verificationUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
