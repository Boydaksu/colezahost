<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Approval;
use Coleza\Domain\Automation\Versioning\RuleVersionManagerInterface;
use Coleza\Domain\Automation\Persistence\AutomationCodec;
final class ApprovalExecutionPolicy
{
    public static function delaySeconds(PendingApproval $request, ?RuleVersionManagerInterface $versions): int
    {
        $s = $request->getExecutionSnapshot();
        if (($s['rule_version'] ?? 0) > 0) {
            $version = $versions?->getVersion($request->getRuleId(), $s['rule_version']);
            if ($version !== null && hash_equals($s['rule_fingerprint'], hash('sha256', AutomationCodec::encode($version->getSnapshot())))) {
                $delay = $version->getSnapshot()['delay_seconds'];
                if (is_int($delay) && $delay >= 0 && $delay <= 2147483647) {
                    if (array_key_exists('delay_seconds', $s) && $s['delay_seconds'] !== $delay) {
                        throw new \LogicException('Approval delay policy differs from recorded rule version');
                    }
                    return $delay;
                }
            }
            throw new \LogicException('Recorded approval policy cannot be verified; reconciliation required');
        }
        if (array_key_exists('delay_seconds', $s)) { return $s['delay_seconds']; }
        throw new \LogicException('Approval delay policy is unknown; reconciliation required before execution');
    }
}
