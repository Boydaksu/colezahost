<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Versioning;
use Coleza\Domain\Automation\Engine\AutomationRule;
interface RuleVersionManagerInterface
{
    public function recordVersion(AutomationRule $rule, string $createdBy, string $changeSummary): RuleVersion;
    public function getVersions(string $ruleId): array;
    public function getVersion(string $ruleId, int $versionNumber): ?RuleVersion;
    public function getLatestVersion(string $ruleId): ?RuleVersion;
    public function diff(string $ruleId, int $v1, int $v2): array;
}
