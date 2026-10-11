<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Safety;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Automation\Persistence\AutomationCodec;

final class DatabaseEmergencyPauseManager implements EmergencyPauseManagerInterface
{
    public function __construct(private readonly Connection $db, private readonly ?DestructiveActionRegistry $registry = null) {}
    public function getState(): EmergencyPauseState
    {
        $row = $this->db->selectOne('SELECT payload FROM automation_emergency_pause WHERE id = 1' . $this->db->forUpdate());
        if ($row === null) { throw new \RuntimeException('Emergency pause state missing; automation must stop.'); }
        $d = AutomationCodec::decode($row['payload']);
        return new EmergencyPauseState($d['is_paused'], $d['paused_by'], $d['reason'], AutomationCodec::date($d['paused_at']),
            $d['resumed_by'], AutomationCodec::date($d['resumed_at']), $d['scope'], $d['resumed_reason'] ?? null);
    }
    public function isPaused(?string $actionType = null, string $scope = 'ALL'): bool
    {
        return (new EmergencyPauseManager($this->registry, $this->getState()))->isPaused($actionType, $scope);
    }
    public function pause(string $pausedBy, string $reason, string $scope = 'ALL'): void
    {
        $this->mutate(static fn(EmergencyPauseManager $manager) => $manager->pause($pausedBy, $reason, $scope));
    }
    public function resume(string $resumedBy, string $reason): void
    {
        $this->mutate(static fn(EmergencyPauseManager $manager) => $manager->resume($resumedBy, $reason));
    }
    private function mutate(callable $change): void
    {
        $this->db->transaction(function () use ($change): void {
            $this->db->lockRow('automation_state_lock', 1);
            if ($this->db->selectOne('SELECT id FROM automation_state_lock WHERE id = 1') === null) {
                throw new \RuntimeException('Automation state lock missing; mutation refused.');
            }
            $manager = new EmergencyPauseManager($this->registry, $this->getState());
            $change($manager);
            $this->db->update('automation_emergency_pause', ['payload' => AutomationCodec::encode($manager->getState()->toArray())], 'id = 1');
        });
    }
}
