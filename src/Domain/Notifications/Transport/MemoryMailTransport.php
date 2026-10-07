<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Transport;

use Coleza\Domain\Notifications\Delivery\DeliveryResult;
use Coleza\Domain\Notifications\Messages\NotificationMessage;

final class MemoryMailTransport implements MailerInterface
{
    /**
     * @var array<NotificationMessage>
     */
    private array $sentMessages = [];

    private bool $simulateFailure = false;
    private ?string $simulatedError = null;

    public function getTransportName(): string
    {
        return 'memory';
    }

    public function send(NotificationMessage $message): DeliveryResult
    {
        if ($this->simulateFailure) {
            return DeliveryResult::failure(
                error: $this->simulatedError ?? 'Simulated transport delivery failure',
                transport: $this->getTransportName(),
                messageId: null,
                metadata: ['recipient' => $message->getRecipientEmail()]
            );
        }

        $this->sentMessages[] = $message;
        $messageId = 'msg_' . bin2hex(random_bytes(8));

        return DeliveryResult::success(
            messageId: $messageId,
            transport: $this->getTransportName(),
            metadata: [
                'recipient' => $message->getRecipientEmail(),
                'subject' => $message->getSubject(),
                'locale' => $message->getLocale(),
            ]
        );
    }

    /**
     * @return array<NotificationMessage>
     */
    public function getSentMessages(): array
    {
        return $this->sentMessages;
    }

    public function count(): int
    {
        return count($this->sentMessages);
    }

    public function clear(): void
    {
        $this->sentMessages = [];
    }

    public function setSimulateFailure(bool $simulate, ?string $errorMessage = null): void
    {
        $this->simulateFailure = $simulate;
        $this->simulatedError = $errorMessage;
    }

    public function findLastByRecipient(string $email): ?NotificationMessage
    {
        for ($i = count($this->sentMessages) - 1; $i >= 0; $i--) {
            if ($this->sentMessages[$i]->getRecipientEmail() === $email) {
                return $this->sentMessages[$i];
            }
        }

        return null;
    }
}
