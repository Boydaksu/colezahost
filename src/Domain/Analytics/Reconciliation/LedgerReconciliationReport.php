<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Reconciliation;

final class LedgerReconciliationReport
{
    /**
     * @param list<string> $discrepancies
     */
    public function __construct(
        private string $startDate,
        private string $endDate,
        private string $currency,
        private float $analyticsCashCollected,
        private float $ledgerPaymentsCollected,
        private float $paymentVariance,
        private float $analyticsCashRefunded,
        private float $ledgerRefundsPaid,
        private float $refundVariance,
        private float $analyticsNetCash,
        private float $ledgerNetCash,
        private float $netCashVariance,
        private ReconciliationStatus $status,
        private array $discrepancies = []
    ) {
    }

    public function getStartDate(): string
    {
        return $this->startDate;
    }

    public function getEndDate(): string
    {
        return $this->endDate;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getAnalyticsCashCollected(): float
    {
        return $this->analyticsCashCollected;
    }

    public function getLedgerPaymentsCollected(): float
    {
        return $this->ledgerPaymentsCollected;
    }

    public function getPaymentVariance(): float
    {
        return $this->paymentVariance;
    }

    public function getAnalyticsCashRefunded(): float
    {
        return $this->analyticsCashRefunded;
    }

    public function getLedgerRefundsPaid(): float
    {
        return $this->ledgerRefundsPaid;
    }

    public function getRefundVariance(): float
    {
        return $this->refundVariance;
    }

    public function getAnalyticsNetCash(): float
    {
        return $this->analyticsNetCash;
    }

    public function getLedgerNetCash(): float
    {
        return $this->ledgerNetCash;
    }

    public function getNetCashVariance(): float
    {
        return $this->netCashVariance;
    }

    public function getStatus(): ReconciliationStatus
    {
        return $this->status;
    }

    /**
     * @return list<string>
     */
    public function getDiscrepancies(): array
    {
        return $this->discrepancies;
    }

    public function isReconciled(): bool
    {
        return $this->status === ReconciliationStatus::MATCHED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'currency' => $this->currency,
            'analytics_cash_collected' => round($this->analyticsCashCollected, 2),
            'ledger_payments_collected' => round($this->ledgerPaymentsCollected, 2),
            'payment_variance' => round($this->paymentVariance, 2),
            'analytics_cash_refunded' => round($this->analyticsCashRefunded, 2),
            'ledger_refunds_paid' => round($this->ledgerRefundsPaid, 2),
            'refund_variance' => round($this->refundVariance, 2),
            'analytics_net_cash' => round($this->analyticsNetCash, 2),
            'ledger_net_cash' => round($this->ledgerNetCash, 2),
            'net_cash_variance' => round($this->netCashVariance, 2),
            'status' => $this->status->value,
            'is_reconciled' => $this->isReconciled(),
            'discrepancies' => $this->discrepancies,
        ];
    }
}
