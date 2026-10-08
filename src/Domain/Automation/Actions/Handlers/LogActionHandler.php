<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Actions\Handlers;

use Coleza\Domain\Automation\Actions\ActionHandlerInterface;
use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Psr\Log\LoggerInterface;

final class LogActionHandler implements ActionHandlerInterface
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    private array $loggedMessages = [];

    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function getType(): string
    {
        return 'log';
    }

    public function execute(ActionInterface $action, TriggerContext $context): ActionResult
    {
        $start = microtime(true);
        $resolved = $action->resolveParameters($context);

        $level = (string) ($resolved['level'] ?? 'info');
        $message = (string) ($resolved['message'] ?? 'Automation action executed');
        $extra = (array) ($resolved['context'] ?? []);

        $logEntry = [
            'level' => $level,
            'message' => $message,
            'context' => array_merge($extra, [
                'event' => $context->getEventName(),
                'correlation_id' => $context->getCorrelationId(),
            ]),
        ];

        $this->loggedMessages[] = $logEntry;

        if ($this->logger !== null) {
            $this->logger->log($level, $message, $logEntry['context']);
        }

        $durationMs = (microtime(true) - $start) * 1000;

        return ActionResult::success($action->getId(), 'log', [
            'level' => $level,
            'message' => $message,
            'logged' => true,
        ], $durationMs);
    }

    /**
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    public function getLoggedMessages(): array
    {
        return $this->loggedMessages;
    }

    public function clear(): void
    {
        $this->loggedMessages = [];
    }
}
