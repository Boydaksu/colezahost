<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Adapters\Service;

use Coleza\Domain\Automation\Actions\ActionHandlerInterface;
use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Commerce\Services\ServiceService;
use Throwable;

final class ServiceTerminateActionHandler implements ActionHandlerInterface
{
    public function __construct(
        private readonly ServiceService $serviceService
    ) {
    }

    public function getType(): string
    {
        return 'service.terminate';
    }

    public function execute(ActionInterface $action, TriggerContext $context): ActionResult
    {
        $start = microtime(true);
        $resolved = $action->resolveParameters($context);

        $rawId = $resolved['service_id'] ?? $context->get('service.id') ?? $context->get('service_id');
        if ($rawId === null || $rawId === '') {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                "Missing required 'service_id' parameter",
                [],
                $durationMs
            );
        }

        $serviceId = (int) $rawId;
        $reason = (string) ($resolved['reason'] ?? 'Terminated by automation policy');
        $expectedLockVersion = isset($resolved['expected_lock_version']) ? (int) $resolved['expected_lock_version'] : null;

        try {
            $service = $this->serviceService->terminateService($serviceId, $reason, $expectedLockVersion);
            $durationMs = (microtime(true) - $start) * 1000;

            return ActionResult::success($action->getId(), $this->getType(), [
                'service_id' => $service->getId(),
                'service_number' => $service->getServiceNumber(),
                'status' => $service->getStatus(),
                'terminated_at' => $service->getTerminationDate(),
            ], $durationMs);
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                'Service termination command failed: ' . $e->getMessage(),
                ['service_id' => $serviceId],
                $durationMs
            );
        }
    }
}
