<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Engine;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Automation\Actions\ActionRegistryInterface;
use Coleza\Domain\Automation\Approval\DatabaseApprovalManager;
use Coleza\Domain\Automation\Delay\DatabaseDelayManager;
use Coleza\Domain\Automation\Execution\DatabaseRunHistoryRepository;
use Coleza\Domain\Automation\Safety\{DatabaseEmergencyPauseManager, DatabaseBlastRadiusLimiter};

/** Requires installed migrations. Does not run DDL during application requests. */
final class DatabaseAutomationFactory
{
    public static function create(Connection $db, ActionRegistryInterface $registry, ?\Psr\Log\LoggerInterface $logger = null, ?\Coleza\Domain\Vault\Encryptor $encryptor = null): AutomationEngine
    {
        $encryptor ??= \Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::configured());
        \Coleza\Domain\Vault\SecretStorageUpgrade::assertStorage($db, \Coleza\Domain\Automation\Persistence\AutomationSecretStore::requirements()
            + \Coleza\Domain\Vault\VaultService::schemaRequirements() + ['security_audit_logs' => ['id', 'actor_user_id', 'impersonator_user_id', 'event_type', 'target_resource', 'ip_address', 'user_agent', 'payload', 'created_at']]);
        if ($db->selectOne('SELECT id FROM automation_state_lock WHERE id = 1') === null) {
            throw new \RuntimeException('Automation state lock missing; engine initialization refused.');
        }
        $db->select('SELECT id, kind, status, payload FROM automation_pending LIMIT 0');
        $db->select('SELECT id, rule_id, correlation_id, executed_at, payload FROM automation_run_history LIMIT 0');
        $db->select('SELECT rule_id, version_number, payload FROM automation_rule_versions LIMIT 0');
        $db->select('SELECT id, dispatch_id, action_position, status, input_fingerprint FROM automation_action_attempts LIMIT 0');
        (new \Coleza\Domain\Automation\Persistence\AutomationSecretStore($db, $encryptor))->guard();
        $pause = new DatabaseEmergencyPauseManager($db);
        $pause->getState(); // Missing/unreadable safety state must fail before any action.
        return new AutomationEngine($registry, $logger, new DatabaseApprovalManager($db, $encryptor), new DatabaseDelayManager($db, $encryptor),
            new DatabaseRunHistoryRepository($db, $encryptor), $pause, new DatabaseBlastRadiusLimiter($db), new \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager($db, $encryptor),
            new \Coleza\Domain\Automation\Approval\DatabaseApprovalDelayCoordinator($db, $encryptor),
            new \Coleza\Domain\Automation\Execution\DatabaseActionAttemptJournal($db));
    }
}
