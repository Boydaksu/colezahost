<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Linkage;

use Coleza\Domain\Fraud\Lists\FraudListEntryType;
use Coleza\Domain\Fraud\Lists\FraudListService;
use Coleza\Domain\Fraud\Lists\FraudListType;
use Coleza\Domain\Fraud\Risk\RiskDecision;
use Coleza\Foundation\Database\Connection;

final class AccountLinkageService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?FraudListService $listService = null
    ) {
    }

    public function findLinkedAccounts(
        int $subjectUserId,
        ?string $clientIp = null,
        ?string $email = null
    ): RelatedAccountResult {
        $linkedUserIds = [];
        $fraudulentUserIds = [];
        $details = [];

        // 1. Identify users sharing the same client IP from velocity events
        if ($clientIp !== null && trim($clientIp) !== '') {
            $normIp = trim(strtolower($clientIp));
            try {
                $rows = $this->db->select(
                    "SELECT DISTINCT metadata_json FROM fraud_velocity_events
                     WHERE identifier_type = 'ip' AND identifier_value = :ip AND metadata_json IS NOT NULL",
                    ['ip' => $normIp]
                );

                foreach ($rows as $row) {
                    $meta = json_decode((string) $row['metadata_json'], true);
                    if (isset($meta['user_id'])) {
                        $uid = (int) $meta['user_id'];
                        if ($uid !== $subjectUserId && !in_array($uid, $linkedUserIds, true)) {
                            $linkedUserIds[] = $uid;
                            $details['by_ip'][$uid] = $normIp;
                        }
                    }
                }
            } catch (\Throwable) {
                // Table might not exist yet if empty
            }

            // Also check fraud_risk_evaluations for users evaluated under this IP
            try {
                $evalRows = $this->db->select(
                    "SELECT DISTINCT user_id FROM fraud_risk_evaluations
                     WHERE user_id IS NOT NULL AND user_id != :subject_user_id
                       AND context_snapshot_json LIKE :ip_pattern",
                    [
                        'subject_user_id' => $subjectUserId,
                        'ip_pattern' => '%' . $normIp . '%',
                    ]
                );

                foreach ($evalRows as $eRow) {
                    $uid = (int) $eRow['user_id'];
                    if (!in_array($uid, $linkedUserIds, true)) {
                        $linkedUserIds[] = $uid;
                        $details['by_ip'][$uid] = $normIp;
                    }
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        // 2. Identify fraudulent history among linked users
        foreach ($linkedUserIds as $linkedId) {
            $isFraud = false;

            // Check if user has a REJECT evaluation in fraud_risk_evaluations
            try {
                $rejectRow = $this->db->selectOne(
                    "SELECT id FROM fraud_risk_evaluations
                     WHERE user_id = :user_id AND decision = :reject_decision LIMIT 1",
                    [
                        'user_id' => $linkedId,
                        'reject_decision' => RiskDecision::REJECT->value,
                    ]
                );
                if ($rejectRow !== null) {
                    $isFraud = true;
                    $details['fraud_reasons'][$linkedId][] = 'Prior rejected risk evaluation on record';
                }
            } catch (\Throwable) {
                // Ignore
            }

            // Check if user is on the DENY list
            if ($this->listService !== null) {
                $listMatch = $this->listService->checkValue(FraudListEntryType::USER_ID, (string) $linkedId);
                if ($listMatch === FraudListType::DENY) {
                    $isFraud = true;
                    $details['fraud_reasons'][$linkedId][] = 'Linked user is explicitly blacklisted on DENY list';
                }
            }

            if ($isFraud && !in_array($linkedId, $fraudulentUserIds, true)) {
                $fraudulentUserIds[] = $linkedId;
            }
        }

        // Check if subject user themselves is explicitly on DENY list
        if ($this->listService !== null) {
            $subMatch = $this->listService->checkValue(FraudListEntryType::USER_ID, (string) $subjectUserId);
            if ($subMatch === FraudListType::DENY) {
                $fraudulentUserIds[] = $subjectUserId;
                $details['fraud_reasons'][$subjectUserId][] = 'Subject user is explicitly blacklisted on DENY list';
            }
        }

        return new RelatedAccountResult(
            subjectUserId: $subjectUserId,
            linkedUserIds: $linkedUserIds,
            fraudulentUserIds: $fraudulentUserIds,
            details: $details
        );
    }
}
