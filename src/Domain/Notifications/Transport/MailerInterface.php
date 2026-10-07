<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Transport;

use Coleza\Domain\Notifications\Delivery\DeliveryResult;
use Coleza\Domain\Notifications\Messages\NotificationMessage;

interface MailerInterface
{
    /**
     * Dispatches an outbound notification message through this transport.
     */
    public function send(NotificationMessage $message): DeliveryResult;

    /**
     * Transport identifier name.
     */
    public function getTransportName(): string;
}
