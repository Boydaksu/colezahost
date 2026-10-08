<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Versioning;

use Coleza\Domain\Automation\Engine\AutomationRule;
use DateTimeImmutable;
use InvalidArgumentException;

final class RuleVersionManager
{
    /** @var array<string, array<int, RuleVersion>> */
    private array $history = [];

    public function recordVersion(
        AutomationRule $rule,
        string $createdBy,
        string $changeSummary
    ): RuleVersion {
        $ruleId = $rule->getId();
        $currentVersions = $this->history[$ruleId] ?? [];
        $nextVersionNum = count($currentVersions) + 1;

        $snapshot = [
            'id' => $rule->getId(),
            'name' => $rule->getName(),
            'description' => $rule->getDescription(),
            'enabled' => $rule->isEnabled(),
            'priority' => $rule->getPriority(),
            'execution_mode' => $rule->getExecutionMode()->value,
            'delay_seconds' => $rule->getDelaySeconds(),
            'requires_approval' => $rule->requiresApproval(),
            'metadata' => $rule->getMetadata(),
        ];

        $version = new RuleVersion(
            $nextVersionNum,
            $ruleId,
            $rule->getName(),
            $rule->getDescription(),
            $rule->getTrigger()->getName(),
            $rule->getTrigger()->getType(),
            $rule->getPriority(),
            $createdBy,
            $changeSummary,
            $snapshot,
            new DateTimeImmutable()
        );

        $this->history[$ruleId][$nextVersionNum] = $version;
        $rule->setVersion($nextVersionNum);

        return $version;
    }

    /**
     * @return array<int, RuleVersion>
     */
    public function getVersions(string $ruleId): array
    {
        return array_values($this->history[$ruleId] ?? []);
    }

    public function getVersion(string $ruleId, int $versionNumber): ?RuleVersion
    {
        return $this->history[$ruleId][$versionNumber] ?? null;
    }

    public function getLatestVersion(string $ruleId): ?RuleVersion
    {
        $versions = $this->history[$ruleId] ?? [];
        if (empty($versions)) {
            return null;
        }

        return end($versions);
    }

    /**
     * Compute differences between two versions of a rule.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diff(string $ruleId, int $v1, int $v2): array
    {
        $version1 = $this->getVersion($ruleId, $v1);
        $version2 = $this->getVersion($ruleId, $v2);

        if ($version1 === null || $version2 === null) {
            throw new InvalidArgumentException("One or both versions ({$v1}, {$v2}) do not exist for rule {$ruleId}");
        }

        $s1 = $version1->getSnapshot();
        $s2 = $version2->getSnapshot();

        $diff = [];
        $allKeys = array_unique(array_merge(array_keys($s1), array_keys($s2)));

        foreach ($allKeys as $key) {
            $val1 = $s1[$key] ?? null;
            $val2 = $s2[$key] ?? null;

            if ($val1 !== $val2) {
                $diff[$key] = [
                    'from' => $val1,
                    'to' => $val2,
                ];
            }
        }

        return $diff;
    }
}
