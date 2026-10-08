<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Lifecycle;

use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Domain\Domains\DomainContact;
use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Domains\DomainStateMachine;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class DomainExpiryService
{
    private string $remindersTable = 'domain_renewal_reminders';

    public function __construct(
        private readonly Connection $db,
        private readonly DomainService $domainService,
        private readonly DomainCatalogService $catalogService,
        private readonly ?DomainLifecyclePolicy $defaultPolicy = null
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                domain_id INT NOT NULL,
                reminder_type VARCHAR(50) NOT NULL,
                expiry_date VARCHAR(20) NOT NULL,
                recipient_email VARCHAR(255) NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (domain_id, reminder_type, expiry_date)
            )',
            $this->remindersTable,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Compute comprehensive ICANN lifecycle metadata, windows, stages, and restoration pricing.
     *
     * @return array<string, mixed>
     */
    public function getDomainLifecycleMetadata(int $domainId, ?DateTimeImmutable $referenceDate = null): array
    {
        $domain = $this->domainService->findDomainById($domainId);
        if ($domain === null) {
            throw new ValidationException(['domain_id' => "Domain ID {$domainId} not found."], 'Domain not found');
        }

        $expiryDateStr = $domain->getExpiryDate();
        if ($expiryDateStr === null || $expiryDateStr === '') {
            return [
                'domain_id' => $domainId,
                'domain' => $domain->getDomain(),
                'status' => $domain->getStatus(),
                'expiry_date' => null,
                'days_until_expiry' => null,
                'lifecycle_stage' => 'pending',
                'is_renewable' => false,
                'is_restorable' => false,
            ];
        }

        $refDate = $referenceDate ?? new DateTimeImmutable('today');
        $refDateStr = $refDate->format('Y-m-d');
        $expiryDateTime = new DateTimeImmutable($expiryDateStr);

        // Days until expiry: positive = future, negative = expired
        $daysUntilExpiry = (int) $refDate->diff($expiryDateTime)->format('%r%a');

        $split = $this->catalogService->splitDomain($domain->getDomain());
        $tld = $this->catalogService->findTldByExtension($split['tld']);

        $graceDays = $tld?->getGracePeriodDays() ?? $this->defaultPolicy?->getGracePeriodDays() ?? 30;
        $redemptionDays = $tld?->getRedemptionPeriodDays() ?? $this->defaultPolicy?->getRedemptionPeriodDays() ?? 30;

        $graceEnd = $expiryDateTime->modify("+{$graceDays} days")->format('Y-m-d');
        $redemptionEnd = $expiryDateTime->modify("+" . ($graceDays + $redemptionDays) . " days")->format('Y-m-d');

        // Stage calculation
        if ($refDateStr <= $expiryDateStr) {
            $stage = 'active';
        } elseif ($refDateStr <= $graceEnd) {
            $stage = 'grace';
        } elseif ($refDateStr <= $redemptionEnd) {
            $stage = 'redemption';
        } else {
            $stage = 'cancelled';
        }

        // Pricing lookup
        $renewPriceMinor = null;
        $restorePriceMinor = null;

        try {
            $renewPriceMinor = $this->catalogService->calculateRenewalPrice($split['tld'], 1);
        } catch (\Throwable) {
            // pricing not configured
        }

        if ($stage === 'redemption' || $domain->getStatus() === DomainStateMachine::STATUS_REDEMPTION) {
            try {
                $restorePriceMinor = $this->catalogService->calculateRestorePrice($split['tld']);
            } catch (\Throwable) {
                // restore price not configured
            }
        }

        return [
            'domain_id' => $domainId,
            'domain' => $domain->getDomain(),
            'status' => $domain->getStatus(),
            'expiry_date' => $expiryDateStr,
            'days_until_expiry' => $daysUntilExpiry,
            'lifecycle_stage' => $stage,
            'grace_period_days' => $graceDays,
            'grace_ends_at' => $graceEnd,
            'redemption_period_days' => $redemptionDays,
            'redemption_ends_at' => $redemptionEnd,
            'is_renewable' => in_array($stage, ['active', 'grace'], true),
            'is_restorable' => $stage === 'redemption',
            'renewal_price_minor' => $renewPriceMinor,
            'restore_price_minor' => $restorePriceMinor,
            'currency' => 'USD',
        ];
    }

    /**
     * Evaluate all domain expiration thresholds against reference date and advance state machine.
     *
     * @return array{transitioned_to_grace: int, transitioned_to_redemption: int, cancelled: int}
     */
    public function evaluateAndAdvanceDomainLifecycles(
        ?DateTimeImmutable $referenceDate = null,
        string $actorType = 'automation'
    ): array {
        $refDate = $referenceDate ?? new DateTimeImmutable('today');
        $refDateStr = $refDate->format('Y-m-d');

        $rows = $this->db->select(
            "SELECT id, status, domain, expiry_date, tld FROM domains WHERE status IN ('active', 'grace', 'expired', 'redemption') AND expiry_date IS NOT NULL"
        );

        $transitionedGrace = 0;
        $transitionedRedemption = 0;
        $cancelled = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $currentStatus = (string) $row['status'];
            $expiryDateStr = (string) $row['expiry_date'];
            $tldExt = (string) $row['tld'];

            $tld = $this->catalogService->findTldByExtension($tldExt);
            $graceDays = $tld?->getGracePeriodDays() ?? $this->defaultPolicy?->getGracePeriodDays() ?? 30;
            $redemptionDays = $tld?->getRedemptionPeriodDays() ?? $this->defaultPolicy?->getRedemptionPeriodDays() ?? 30;

            $expiryDateTime = new DateTimeImmutable($expiryDateStr);
            $graceEnd = $expiryDateTime->modify("+{$graceDays} days")->format('Y-m-d');
            $redemptionEnd = $expiryDateTime->modify("+" . ($graceDays + $redemptionDays) . " days")->format('Y-m-d');

            // 1. Check redemption expired -> Cancelled
            if ($refDateStr > $redemptionEnd && $currentStatus === DomainStateMachine::STATUS_REDEMPTION) {
                $this->domainService->transitionStatus(
                    id: $id,
                    newStatus: DomainStateMachine::STATUS_CANCELLED,
                    reason: "Redemption period ended on {$redemptionEnd}.",
                    actorType: $actorType
                );

                $this->domainService->recordTimelineEvent(
                    domainId: $id,
                    eventType: 'domain_lifecycle_cancelled',
                    description: "Domain redemption period expired without restoration. Asset cancelled.",
                    actorType: $actorType
                );
                $cancelled++;
                continue;
            }

            // 2. Check grace expired -> Redemption
            if ($refDateStr > $graceEnd && in_array($currentStatus, [DomainStateMachine::STATUS_ACTIVE, DomainStateMachine::STATUS_GRACE, DomainStateMachine::STATUS_EXPIRED], true)) {
                $this->domainService->transitionStatus(
                    id: $id,
                    newStatus: DomainStateMachine::STATUS_REDEMPTION,
                    reason: "Grace period ended on {$graceEnd}.",
                    actorType: $actorType
                );

                $this->domainService->recordTimelineEvent(
                    domainId: $id,
                    eventType: 'domain_entered_redemption',
                    description: "Domain entered REDEMPTION stage. Restoration fee required for recovery until {$redemptionEnd}.",
                    payload: ['redemption_ends_at' => $redemptionEnd],
                    actorType: $actorType
                );
                $transitionedRedemption++;
                continue;
            }

            // 3. Check expiry passed -> Grace
            if ($refDateStr > $expiryDateStr && in_array($currentStatus, [DomainStateMachine::STATUS_ACTIVE], true)) {
                $this->domainService->transitionStatus(
                    id: $id,
                    newStatus: DomainStateMachine::STATUS_GRACE,
                    reason: "Domain expired on {$expiryDateStr}.",
                    actorType: $actorType
                );

                $this->domainService->recordTimelineEvent(
                    domainId: $id,
                    eventType: 'domain_entered_grace',
                    description: "Domain expired and entered GRACE period. Renewable at standard rate until {$graceEnd}.",
                    payload: ['grace_ends_at' => $graceEnd],
                    actorType: $actorType
                );
                $transitionedGrace++;
            }
        }

        return [
            'transitioned_to_grace' => $transitionedGrace,
            'transitioned_to_redemption' => $transitionedRedemption,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * Dispatches scheduled ICANN renewal reminders with strict deduplication.
     *
     * @param callable(string $domain, string $reminderType, string $recipientEmail, int $daysUntilExpiry): void|null $notifier
     * @return array{reminders_sent: int, skipped_already_sent: int}
     */
    public function dispatchDueReminders(
        ?DateTimeImmutable $referenceDate = null,
        ?callable $notifier = null
    ): array {
        $refDate = $referenceDate ?? new DateTimeImmutable('today');
        $rows = $this->db->select(
            "SELECT id, user_id, domain, expiry_date, status FROM domains WHERE status IN ('active', 'grace', 'redemption') AND expiry_date IS NOT NULL"
        );

        $remindersSent = 0;
        $skippedAlreadySent = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $domainName = (string) $row['domain'];
            $expiryDateStr = (string) $row['expiry_date'];
            $status = (string) $row['status'];

            $expiryDateTime = new DateTimeImmutable($expiryDateStr);
            $daysUntilExpiry = (int) $refDate->diff($expiryDateTime)->format('%r%a');

            $dueType = $this->resolveDueReminderType($daysUntilExpiry, $status);
            if ($dueType === null) {
                continue;
            }

            // Check if reminder was already sent for this cycle
            if ($this->hasReminderBeenSent($id, $dueType, $expiryDateStr)) {
                $skippedAlreadySent++;
                continue;
            }

            // Determine recipient email from registrant contact
            $contact = $this->domainService->getContact($id, DomainContact::TYPE_REGISTRANT);
            $email = $contact?->getEmail() ?? 'owner@' . $domainName;

            if ($notifier !== null) {
                $notifier($domainName, $dueType, $email, $daysUntilExpiry);
            }

            $this->recordReminderSent($id, $dueType, $expiryDateStr, $email);

            $this->domainService->recordTimelineEvent(
                domainId: $id,
                eventType: 'reminder_sent',
                description: "ICANN ERRP renewal notice '{$dueType}' dispatched to {$email}.",
                payload: [
                    'reminder_type' => $dueType,
                    'expiry_date' => $expiryDateStr,
                    'days_until_expiry' => $daysUntilExpiry,
                    'recipient' => $email,
                ],
                actorType: 'automation'
            );

            $remindersSent++;
        }

        return [
            'reminders_sent' => $remindersSent,
            'skipped_already_sent' => $skippedAlreadySent,
        ];
    }

    /**
     * @return list<DomainRenewalReminder>
     */
    public function listRemindersForDomain(int $domainId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE domain_id = ? ORDER BY id DESC', $this->remindersTable),
            [$domainId]
        );

        return array_map([$this, 'hydrateReminder'], $rows);
    }

    public function hasReminderBeenSent(int $domainId, string $reminderType, string $expiryDate): bool
    {
        $rows = $this->db->select(
            sprintf('SELECT id FROM %s WHERE domain_id = ? AND reminder_type = ? AND expiry_date = ? LIMIT 1', $this->remindersTable),
            [$domainId, $reminderType, $expiryDate]
        );

        return !empty($rows);
    }

    private function recordReminderSent(
        int $domainId,
        string $reminderType,
        string $expiryDate,
        string $email
    ): void {
        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'INSERT INTO %s (domain_id, reminder_type, expiry_date, recipient_email, sent_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            $this->remindersTable
        );

        $this->db->statement($sql, [
            $domainId,
            $reminderType,
            $expiryDate,
            $email,
            $now,
            $now,
        ]);
    }

    private function resolveDueReminderType(int $daysUntilExpiry, string $status): ?string
    {
        if ($status === DomainStateMachine::STATUS_REDEMPTION) {
            return DomainRenewalReminder::TYPE_REDEMPTION_WARNING;
        }

        return match ($daysUntilExpiry) {
            30 => DomainRenewalReminder::TYPE_BEFORE_30D,
            7 => DomainRenewalReminder::TYPE_BEFORE_7D,
            1 => DomainRenewalReminder::TYPE_BEFORE_1D,
            -1 => DomainRenewalReminder::TYPE_AFTER_1D,
            -5 => DomainRenewalReminder::TYPE_AFTER_5D,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateReminder(array $row): DomainRenewalReminder
    {
        return new DomainRenewalReminder(
            id: (int) $row['id'],
            domainId: (int) $row['domain_id'],
            reminderType: (string) $row['reminder_type'],
            expiryDate: (string) $row['expiry_date'],
            recipientEmail: (string) $row['recipient_email'],
            sentAt: !empty($row['sent_at']) ? new DateTimeImmutable((string) $row['sent_at']) : null,
            createdAt: !empty($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null
        );
    }
}
