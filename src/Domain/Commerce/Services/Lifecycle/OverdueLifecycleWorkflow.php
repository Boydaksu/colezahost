<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services\Lifecycle;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServicePlacement;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Notifications\NotificationEngine;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final class OverdueLifecycleWorkflow
{
    public function __construct(
        private readonly ServiceService $serviceService,
        private readonly InvoiceService $invoiceService,
        private readonly ?NotificationEngine $notificationEngine = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Evaluate active and suspended services against grace policies and execute lifecycle transitions.
     */
    public function evaluateAndProcessOverdue(
        OverdueGracePolicy $policy,
        ?string $referenceDate = null
    ): OverdueBatchReport {
        $start = microtime(true);
        $refDate = $referenceDate ?? date('Y-m-d');
        $results = [];

        // 1. Process active services (warning reminders & suspension)
        $activeServices = $this->serviceService->listServicesByStatus(ServiceStateMachine::STATUS_ACTIVE);
        foreach ($activeServices as $service) {
            $results[] = $this->evaluateActiveService($service, $policy, $refDate);
        }

        // 2. Process suspended services (permanent termination threshold)
        $suspendedServices = $this->serviceService->listServicesByStatus(ServiceStateMachine::STATUS_SUSPENDED);
        foreach ($suspendedServices as $service) {
            $results[] = $this->evaluateSuspendedService($service, $policy, $refDate);
        }

        $durationMs = (microtime(true) - $start) * 1000;
        $report = new OverdueBatchReport($refDate, $results, $durationMs, new DateTimeImmutable());

        $this->logReport($report);

        return $report;
    }

    /**
     * Automatically reactivate suspended services and renew cycles upon invoice payment settlement.
     *
     * @return array<int, array{service_id: int, action: string, status: string}>
     */
    public function handleInvoicePaid(int $invoiceId, ?OverdueGracePolicy $policy = null): array
    {
        $activePolicy = $policy ?? new OverdueGracePolicy();
        $invoice = $this->invoiceService->findInvoiceById($invoiceId);
        if ($invoice === null || !$invoice->isPaid()) {
            return [];
        }

        $actions = [];
        $serviceIds = [];

        foreach ($invoice->getItems() as $item) {
            $serviceId = $item->getServiceId();
            if ($serviceId !== null && !in_array($serviceId, $serviceIds, true)) {
                $serviceIds[] = $serviceId;
            }
        }

        foreach ($serviceIds as $serviceId) {
            $service = $this->serviceService->findServiceById($serviceId);
            if ($service === null) {
                continue;
            }

            // 1. Reactivate / unsuspend if suspended
            if ($service->isSuspended() && $activePolicy->isAutoReactivationAllowed()) {
                try {
                    $unsuspended = $this->serviceService->unsuspendService($serviceId);

                    if ($activePolicy->shouldSendNotifications() && $this->notificationEngine !== null) {
                        $this->sendNotification($unsuspended, 'service_unsuspended', [
                            'service_name' => $unsuspended->getDomain() ?? $unsuspended->getServiceNumber(),
                        ]);
                    }

                    $actions[] = [
                        'service_id' => $serviceId,
                        'action' => 'unsuspended',
                        'status' => $unsuspended->getStatus(),
                    ];
                } catch (Throwable $e) {
                    $this->logger?->error("Failed to unsuspend service {$serviceId} on payment: {$e->getMessage()}");
                }
            }

            // 2. Advance renewal date if this was a renewal invoice
            try {
                $renewed = $this->serviceService->renewService($serviceId);
                $actions[] = [
                    'service_id' => $serviceId,
                    'action' => 'renewed',
                    'status' => $renewed->getStatus(),
                    'next_due_date' => $renewed->getNextDueDate(),
                ];
            } catch (Throwable $e) {
                $this->logger?->info("Service {$serviceId} renewal step: {$e->getMessage()}");
            }
        }

        return $actions;
    }

    private function evaluateActiveService(
        Service $service,
        OverdueGracePolicy $policy,
        string $refDate
    ): OverdueEvaluationResult {
        $serviceId = (int) $service->getId();
        $serviceNumber = $service->getServiceNumber();

        // Check exemptions
        $exemptTag = $this->findExemptTag($service, $policy);
        if ($exemptTag !== null) {
            return OverdueEvaluationResult::exempt($serviceId, $serviceNumber, $exemptTag);
        }

        $daysOverdue = $service->getBillingRelation()->daysOverdue($refDate);

        // Due for suspension
        if ($daysOverdue >= $policy->getGracePeriodDays()) {
            try {
                $reason = "Auto-suspended: overdue by {$daysOverdue} days exceeding {$policy->getGracePeriodDays()} day grace threshold";
                $this->serviceService->suspendService($serviceId, $reason);

                $notified = false;
                if ($policy->shouldSendNotifications() && $this->notificationEngine !== null) {
                    $notified = $this->sendNotification($service, 'service_suspended', [
                        'service_name' => $service->getDomain() ?? $serviceNumber,
                        'reason' => $reason,
                    ]);
                }

                return OverdueEvaluationResult::suspended($serviceId, $serviceNumber, $daysOverdue, $reason, $notified);
            } catch (Throwable $e) {
                return OverdueEvaluationResult::failed($serviceId, $serviceNumber, $e->getMessage());
            }
        }

        // Warning reminder inside grace period
        if ($daysOverdue > 0 && in_array($daysOverdue, $policy->getReminderDays(), true)) {
            $notified = false;
            if ($policy->shouldSendNotifications() && $this->notificationEngine !== null) {
                $notified = $this->sendNotification($service, 'service_overdue_reminder', [
                    'service_name' => $service->getDomain() ?? $serviceNumber,
                    'days_overdue' => $daysOverdue,
                ]);
            }
            return OverdueEvaluationResult::reminder($serviceId, $serviceNumber, $daysOverdue, $notified);
        }

        return OverdueEvaluationResult::none($serviceId, $serviceNumber, $daysOverdue);
    }

    private function evaluateSuspendedService(
        Service $service,
        OverdueGracePolicy $policy,
        string $refDate
    ): OverdueEvaluationResult {
        $serviceId = (int) $service->getId();
        $serviceNumber = $service->getServiceNumber();

        // Check exemptions
        $exemptTag = $this->findExemptTag($service, $policy);
        if ($exemptTag !== null) {
            return OverdueEvaluationResult::exempt($serviceId, $serviceNumber, $exemptTag);
        }

        $daysOverdue = $service->getBillingRelation()->daysOverdue($refDate);

        // Due for termination
        if ($daysOverdue >= $policy->getTerminationGraceDays()) {
            if ($policy->requiresApprovalForTermination()) {
                return OverdueEvaluationResult::pendingApproval(
                    $serviceId,
                    $serviceNumber,
                    "Termination requires manual approval: {$daysOverdue} days overdue"
                );
            }

            try {
                $reason = "Auto-terminated: overdue by {$daysOverdue} days exceeding {$policy->getTerminationGraceDays()} day termination grace threshold";
                $this->serviceService->terminateService($serviceId, $reason);
                $this->serviceService->releasePlacement($serviceId, ServicePlacement::STATUS_EVICTED);

                $notified = false;
                if ($policy->shouldSendNotifications() && $this->notificationEngine !== null) {
                    $notified = $this->sendNotification($service, 'service_terminated', [
                        'service_name' => $service->getDomain() ?? $serviceNumber,
                    ]);
                }

                return OverdueEvaluationResult::terminated($serviceId, $serviceNumber, $daysOverdue, $reason, $notified);
            } catch (Throwable $e) {
                return OverdueEvaluationResult::failed($serviceId, $serviceNumber, $e->getMessage());
            }
        }

        return OverdueEvaluationResult::none($serviceId, $serviceNumber, $daysOverdue);
    }

    private function findExemptTag(Service $service, OverdueGracePolicy $policy): ?string
    {
        $metadata = $service->getMetadata();
        $tags = (array) ($metadata['tags'] ?? []);
        if (isset($metadata['tier'])) {
            $tags[] = (string) $metadata['tier'];
        }

        foreach ($policy->getExemptTags() as $exemptTag) {
            if (in_array(strtolower($exemptTag), array_map('strtolower', $tags), true)) {
                return $exemptTag;
            }
        }

        return null;
    }

    private function sendNotification(Service $service, string $templateKey, array $data): bool
    {
        if ($this->notificationEngine === null) {
            return false;
        }

        $metadata = $service->getMetadata();
        $email = (string) ($metadata['customer_email'] ?? $metadata['email'] ?? '');

        if ($email === '') {
            return false;
        }

        try {
            $result = $this->notificationEngine->sendNotification(
                templateKey: $templateKey,
                data: $data,
                recipientEmail: $email,
                recipientUserId: $service->getUserId()
            );

            return $result->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private function logReport(OverdueBatchReport $report): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->info("Overdue lifecycle evaluation completed for {$report->getReferenceDate()}", [
            'evaluated' => $report->totalEvaluated(),
            'suspended' => $report->getSuspendedCount(),
            'terminated' => $report->getTerminatedCount(),
            'reminders' => $report->getRemindersCount(),
            'duration_ms' => $report->getDurationMs(),
        ]);
    }
}
