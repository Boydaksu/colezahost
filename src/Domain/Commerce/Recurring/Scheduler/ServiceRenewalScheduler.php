<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Recurring\Scheduler;

use Coleza\Domain\Commerce\Recurring\BillingPeriod;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Notifications\NotificationEngine;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final class ServiceRenewalScheduler
{
    public function __construct(
        private readonly ServiceService $serviceService,
        private readonly RenewalInvoiceService $renewalInvoiceService,
        private readonly ?NotificationEngine $notificationEngine = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Run renewal evaluation and invoice generation batch.
     */
    public function run(RenewalPolicy $policy, ?string $asOfDate = null): RenewalBatchReport
    {
        $start = microtime(true);
        $referenceDate = $asOfDate ?? date('Y-m-d');

        $dueServices = $this->serviceService->findServicesDueForRenewal($referenceDate, $policy->getLeadDays(), $policy->isAutoRenewOnly());

        $periods=array_map(static fn(Service $service):array=>['service_id'=>$service->getId(),'period_start'=>BillingPeriod::computePeriod($service->getNextDueDate(),$service->getBillingCycle())['start']],$dueServices);
        $existing=$this->renewalInvoiceService->existingPeriods($periods);
        $results = [];
        foreach ($dueServices as $service) {
            $results[] = $this->evaluateService($service,$policy,$referenceDate,isset($existing[$service->getId().':'.$service->getNextDueDate()]));
        }

        $durationMs = (microtime(true) - $start) * 1000;
        $report = new RenewalBatchReport($referenceDate, $results, $durationMs, new DateTimeImmutable());

        $this->logReport($report);

        return $report;
    }

    /**
     * Evaluate and generate a renewal invoice for a single service.
     */
    public function evaluateAndGenerateForService(Service $service,RenewalPolicy $policy,string $referenceDate): RenewalExecutionResult
    {
        return $this->evaluateService($service,$policy,$referenceDate,null);
    }

    private function evaluateService(Service $service,RenewalPolicy $policy,string $referenceDate,?bool $alreadyGenerated): RenewalExecutionResult
    {
        $serviceId = (int) $service->getId();

        if(\Coleza\Domain\Pricing\Entities\PriceCycle::getMonths($service->getBillingCycle())<=0||!BillingPeriod::isDueForRenewal($service->getNextDueDate(),$referenceDate,$policy->getLeadDays())){return RenewalExecutionResult::skipped($serviceId,'Service is outside the recurring renewal window');}

        // 1. Check auto_renew preference
        if ($policy->isAutoRenewOnly() && !$service->getBillingRelation()->isAutoRenew()) {
            return RenewalExecutionResult::skipped($serviceId, 'Auto-renew disabled for service');
        }

        // 2. Check period and idempotency
        $period = BillingPeriod::computePeriod($service->getNextDueDate(), $service->getBillingCycle());
        if ($alreadyGenerated ?? $this->renewalInvoiceService->isRenewalAlreadyGenerated($serviceId, $period['start'])) {
            return RenewalExecutionResult::skipped($serviceId, "Renewal invoice already exists for period {$period['start']}");
        }

        // 3. Prepare service payload
        $serviceData = [
            'id' => $serviceId,
            'user_id' => $service->getUserId(),
            'organization_id' => $service->getOrganizationId(),
            'currency_code' => $service->getCurrencyCode(),
            'service_number' => $service->getServiceNumber(),
            'product_id' => $service->getProductId(),
            'domain' => $service->getDomain(),
            'billing_cycle' => $service->getBillingCycle(),
            'recurring_amount_minor' => $service->getRecurringAmountMinor(),
            'next_due_date' => $service->getNextDueDate(),
            'description' => "Service Renewal #{$service->getServiceNumber()} ({$service->getDomain()})",
        ];

        $invoiceDueDate = null;
        if ($policy->getInvoiceDueDays() !== null) {
            $invoiceDueDate = (new DateTimeImmutable($referenceDate))
                ->modify('+' . $policy->getInvoiceDueDays() . ' days')
                ->format('Y-m-d');
        }

        // 4. Generate invoice
        try {
            $invoice = $this->renewalInvoiceService->generateRenewalInvoice($serviceData, $invoiceDueDate, !$policy->isAutoRenewOnly());

            // 5. Send notification if configured
            $notificationSent = false;
            if ($policy->shouldSendNotification() && $this->notificationEngine !== null) {
                $notificationSent = $this->sendRenewalNotification($service, $invoice);
            }

            return RenewalExecutionResult::generated($serviceId, $invoice, $notificationSent);
        } catch (Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->error("Failed to generate renewal invoice for service {$serviceId}: {$e->getMessage()}", [
                    'service_id' => $serviceId,
                    'exception' => $e,
                ]);
            }

            return RenewalExecutionResult::failed($serviceId, $e->getMessage());
        }
    }

    private function sendRenewalNotification(Service $service, $invoice): bool
    {
        $metadata = $service->getMetadata();
        $email = (string) ($metadata['customer_email'] ?? $metadata['email'] ?? '');

        if ($email === '') {
            return false;
        }

        try {
            $result = $this->notificationEngine->sendNotification(
                templateKey: 'invoice_created',
                data: [
                    'invoice_number' => $invoice->getInvoiceNumber(),
                    'total_amount' => sprintf('%.2f %s', $invoice->getTotalMinor() / 100, $invoice->getCurrencyCode()),
                    'due_date' => $invoice->getDueDate(),
                    'service_number' => $service->getServiceNumber(),
                ],
                recipientEmail: $email,
                recipientUserId: $service->getUserId()
            );

            return $result->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private function logReport(RenewalBatchReport $report): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->info("Service renewal batch completed for asOfDate {$report->getAsOfDate()}", [
            'evaluated' => $report->totalEvaluated(),
            'generated' => $report->getGeneratedCount(),
            'skipped' => $report->getSkippedCount(),
            'failed' => $report->getFailedCount(),
            'duration_ms' => $report->getDurationMs(),
        ]);
    }
}
