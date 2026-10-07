<?php

declare(strict_types=1);

namespace Coleza\Domain\Integrations\Health;

use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentGatewayInterface;
use Coleza\Domain\Notifications\Transport\MailerInterface;
use Coleza\Domain\Notifications\Transport\SmtpConfiguration;
use Coleza\Domain\Webhooks\WebhookService;
use Coleza\Foundation\Health\HealthManager;
use PDO;

final class IntegrationHealthRegistry
{
    public function __construct(
        private HealthManager $healthManager
    ) {
    }

    public function registerAll(
        ?MailerInterface $mailTransport = null,
        ?SmtpConfiguration $smtpConfig = null,
        ?PaymentGatewayInterface $gateway = null,
        ?IyzicoConfiguration $iyzicoConfig = null,
        ?WebhookService $webhookService = null,
        ?PDO $pdo = null,
        mixed $smtpProbe = null,
        mixed $gatewayProbe = null
    ): self {
        $this->healthManager->register(new SmtpHealthCheck($mailTransport, $smtpConfig, $smtpProbe));
        $this->healthManager->register(new PaymentGatewayHealthCheck($gateway, $iyzicoConfig, $gatewayProbe));
        $this->healthManager->register(new WebhookQueueHealthCheck($webhookService, $pdo));

        return $this;
    }

    /**
     * @return array{status: string, healthy: bool, checks: array<string, array<string, mixed>>}
     */
    public function report(): array
    {
        return $this->healthManager->report();
    }
}
