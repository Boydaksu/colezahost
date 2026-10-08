<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Adapters\Notification;

use Coleza\Domain\Automation\Actions\ActionHandlerInterface;
use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Notifications\Channel\NotificationChannel;
use Coleza\Domain\Notifications\Channel\NotificationPriority;
use Coleza\Domain\Notifications\NotificationEngine;
use Throwable;

final class NotificationActionHandler implements ActionHandlerInterface
{
    public function __construct(
        private readonly NotificationEngine $notificationEngine
    ) {
    }

    public function getType(): string
    {
        return 'notification.send';
    }

    public function execute(ActionInterface $action, TriggerContext $context): ActionResult
    {
        $start = microtime(true);
        $resolved = $action->resolveParameters($context);

        $templateKey = (string) ($resolved['template_key'] ?? '');
        $recipientEmail = (string) ($resolved['recipient_email'] ?? $context->get('customer.email') ?? $context->get('user.email') ?? '');

        if ($templateKey === '' || $recipientEmail === '') {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                "Missing required 'template_key' or 'recipient_email'",
                [],
                $durationMs
            );
        }

        $data = (array) ($resolved['data'] ?? $context->all());
        $locale = (string) ($resolved['locale'] ?? 'en');
        $recipientName = isset($resolved['recipient_name']) ? (string) $resolved['recipient_name'] : null;
        $recipientUserId = isset($resolved['recipient_user_id']) ? (int) $resolved['recipient_user_id'] : null;

        $rawPriority = (string) ($resolved['priority'] ?? 'normal');
        $priority = match (strtolower($rawPriority)) {
            'high' => NotificationPriority::HIGH,
            'urgent' => NotificationPriority::URGENT,
            'low' => NotificationPriority::LOW,
            default => NotificationPriority::NORMAL,
        };

        try {
            $deliveryResult = $this->notificationEngine->sendNotification(
                templateKey: $templateKey,
                data: $data,
                recipientEmail: $recipientEmail,
                recipientLocale: $locale,
                recipientName: $recipientName,
                recipientUserId: $recipientUserId,
                priority: $priority
            );

            $durationMs = (microtime(true) - $start) * 1000;

            if ($deliveryResult->isSuccessful()) {
                return ActionResult::success($action->getId(), $this->getType(), [
                    'recipient' => $recipientEmail,
                    'template' => $templateKey,
                    'message_id' => $deliveryResult->getMessageId(),
                    'delivered' => true,
                ], $durationMs);
            }

            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                'Notification delivery failed: ' . ($deliveryResult->getError() ?? 'unknown error'),
                ['recipient' => $recipientEmail],
                $durationMs
            );
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                'Notification exception: ' . $e->getMessage(),
                ['recipient' => $recipientEmail],
                $durationMs
            );
        }
    }
}
