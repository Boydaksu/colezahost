<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Versioning;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;
use Coleza\Domain\Automation\Persistence\AutomationCodec;
use Coleza\Domain\Automation\Engine\AutomationRule;
use DateTimeImmutable;
final class DatabaseRuleVersionManager implements RuleVersionManagerInterface
{
    public function __construct(private readonly Connection $db, private readonly ?\Coleza\Domain\Vault\Encryptor $encryptor = null, private readonly bool $readOnly = false) {}
    public static function installSchema(Connection $db): void
    {
        PdoSchema::autoIncrement($db->getPdo());
        $text = $db->getDriverName() === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $suffix = $db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->statement("CREATE TABLE IF NOT EXISTS automation_rule_versions (rule_id VARCHAR(128) NOT NULL, version_number INT NOT NULL,
            payload {$text} NOT NULL, PRIMARY KEY(rule_id, version_number), CHECK(version_number > 0)){$suffix}");
    }
    public function recordVersion(AutomationRule $rule, string $createdBy, string $changeSummary): RuleVersion
    {
        if (trim($createdBy) === '' || trim($changeSummary) === '' || trim($rule->getId()) === '') {
            throw new \InvalidArgumentException('Rule id, author and change summary are required');
        }
        $snapshot = RuleSnapshotCodec::snapshot($rule);
        $version = $this->db->transaction(function () use ($rule, $createdBy, $changeSummary, $snapshot) {
            (new \Coleza\Domain\Automation\Persistence\AutomationSecretStore($this->db, $this->encryptor))->guard();
            $this->db->lockRow('automation_state_lock', 1);
            if ($this->db->selectOne('SELECT id FROM automation_state_lock WHERE id = 1') === null) { throw new \RuntimeException('Automation lock missing'); }
            $latest = $this->db->selectOne('SELECT version_number FROM automation_rule_versions WHERE rule_id = ? ORDER BY version_number DESC LIMIT 1' . $this->db->forUpdate(), [$rule->getId()]);
            $number = $latest === null ? 1 : (int) $latest['version_number'] + 1;
            if ($number > 2147483647) { throw new \OverflowException('Rule version sequence exhausted'); }
            $record = new RuleVersion($number, $rule->getId(), $rule->getName(), $rule->getDescription(), $rule->getTrigger()->getName(),
                $rule->getTrigger()->getType(), $rule->getPriority(), $createdBy, $changeSummary, $snapshot, new DateTimeImmutable());
            $this->db->insert('automation_rule_versions', ['rule_id' => $rule->getId(), 'version_number' => $number, 'payload' => (new \Coleza\Domain\Automation\Persistence\AutomationSecretStore($this->db, $this->encryptor))->write('automation_rule_versions', ['rule_id' => $rule->getId(), 'version_number' => $number], $record->toArray())]);
            return $record;
        });
        $rule->setVersion($version->getVersionNumber());
        return $version;
    }
    private function hydrate(array $row): RuleVersion
    {
        $d = (new \Coleza\Domain\Automation\Persistence\AutomationSecretStore($this->db, $this->encryptor))->read('automation_rule_versions', $row, $this->readOnly);
        if ($d['rule_id'] !== $row['rule_id'] || $d['version_number'] !== (int) $row['version_number']) { throw new \UnexpectedValueException('Rule version identity mismatch'); }
        $rule = RuleSnapshotCodec::restore($d['snapshot'], $d['version_number']);
        if ($rule->getId() !== $d['rule_id'] || $rule->getName() !== $d['name'] || $rule->getDescription() !== $d['description']
            || $rule->getPriority() !== $d['priority'] || $rule->getTrigger()->getName() !== $d['trigger_name'] || $rule->getTrigger()->getType() !== $d['trigger_type']) {
            throw new \UnexpectedValueException('Rule snapshot identity mismatch');
        }
        return new RuleVersion($d['version_number'], $d['rule_id'], $d['name'], $d['description'], $d['trigger_name'], $d['trigger_type'],
            $d['priority'], $d['created_by'], $d['change_summary'], $d['snapshot'], new DateTimeImmutable($d['created_at']));
    }
    public function getVersions(string $ruleId): array
    {
        return array_map($this->hydrate(...), $this->db->select('SELECT * FROM automation_rule_versions WHERE rule_id = ? ORDER BY version_number', [$ruleId]));
    }
    public function getVersion(string $ruleId, int $versionNumber): ?RuleVersion
    {
        $row = $this->db->selectOne('SELECT * FROM automation_rule_versions WHERE rule_id = ? AND version_number = ?' . $this->db->forUpdate(), [$ruleId, $versionNumber]);
        return $row === null ? null : $this->hydrate($row);
    }
    public function getLatestVersion(string $ruleId): ?RuleVersion
    {
        $row = $this->db->selectOne('SELECT * FROM automation_rule_versions WHERE rule_id = ? ORDER BY version_number DESC LIMIT 1', [$ruleId]);
        return $row === null ? null : $this->hydrate($row);
    }
    public function diff(string $ruleId, int $v1, int $v2): array
    {
        $a = $this->getVersion($ruleId, $v1); $b = $this->getVersion($ruleId, $v2);
        if ($a === null || $b === null) { throw new \InvalidArgumentException('Requested version not found'); }
        $diff = [];
        foreach (array_unique(array_merge(array_keys($a->getSnapshot()), array_keys($b->getSnapshot()))) as $key) {
            $from = $a->getSnapshot()[$key] ?? null; $to = $b->getSnapshot()[$key] ?? null;
            if ($from !== $to) { $diff[$key] = ['from' => $from, 'to' => $to]; }
        }
        return $diff;
    }
}
