<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Execution;
final class ExecutionSnapshot
{
    public static function validate(array $snapshot): void
    {
        if ($snapshot === []) { return; } // Historical pending entries have no provenance.
        if (array_key_exists('delay_seconds', $snapshot)
            && (!is_int($snapshot['delay_seconds']) || $snapshot['delay_seconds'] < 0 || $snapshot['delay_seconds'] > 2147483647)) {
            throw new \InvalidArgumentException('Invalid approval delay policy');
        }
        if (array_key_exists('approval_id', $snapshot) && (!is_string($snapshot['approval_id']) || $snapshot['approval_id'] === '')) {
            throw new \InvalidArgumentException('Invalid originating approval id');
        }
        if (array_key_exists('delay_seconds', $snapshot) && array_diff(array_keys($snapshot), ['delay_seconds', 'approval_id']) === []) {
            return; // Explicit policy; other provenance stays unknown.
        }
        $version = $snapshot['rule_version'] ?? null;
        $condition = $snapshot['condition_passed'] ?? null;
        $branch = $snapshot['branch_taken'] ?? null;
        $hash = $snapshot['rule_fingerprint'] ?? null;
        if (!is_int($version) || $version < 0 || !is_bool($condition)
            || !in_array($branch, ['THEN', 'ELSE', 'NONE'], true)
            || ($condition && $branch !== 'THEN') || (!$condition && $branch === 'THEN')
            || ($version > 0 && (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)))
            || ($version === 0 && $hash !== null)) {
            throw new \InvalidArgumentException('Invalid automation execution provenance');
        }
    }
}
