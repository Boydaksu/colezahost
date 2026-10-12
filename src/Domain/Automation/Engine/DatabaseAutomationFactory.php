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
    public static function create(Connection $db, ActionRegistryInterface $registry, ?\Psr\Log\LoggerInterface $logger = null): AutomationEngine
    {
        if ($db->selectOne('SELECT id FROM automation_state_lock WHERE id = 1') === null) {
            throw new \RuntimeException('Automation state lock missing; engine initialization refused.');
        }
        $db->select('SELECT id, kind, status, payload FROM automation_pending LIMIT 0');
        $db->select('SELECT id, rule_id, correlation_id, executed_at, payload FROM automation_run_history LIMIT 0');
        $db->select('SELECT rule_id, version_number, payload FROM automation_rule_versions LIMIT 0');
        $pause = new DatabaseEmergencyPauseManager($db);
        $pause->getState(); // Missing/unreadable safety state must fail before any action.
        return new AutomationEngine($registry, $logger, new DatabaseApprovalManager($db), new DatabaseDelayManager($db),
            new DatabaseRunHistoryRepository($db), $pause, new DatabaseBlastRadiusLimiter($db), new \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager($db),
            new \Coleza\Domain\Automation\Approval\DatabaseApprovalDelayCoordinator($db));
    }
}
