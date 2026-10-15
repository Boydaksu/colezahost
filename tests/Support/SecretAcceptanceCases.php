<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
trait SecretAcceptanceCases
{
    public function testQueryDiagnosticsDoNotRetainBoundOrInlineSecrets(): void
    {
        $this->db->enableQueryLog();
        self::assertSame('synthetic-unlabelled-secret', $this->db->selectOne("SELECT 'synthetic-unlabelled-secret' AS value")['value']);
        self::assertSame('synthetic-bound-secret', $this->db->selectOne('SELECT ? AS value', ['synthetic-bound-secret'])['value']);
        self::assertStringNotContainsString('synthetic-unlabelled-secret', json_encode($this->db->getQueryLog(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('synthetic-bound-secret', json_encode($this->db->getQueryLog(), JSON_THROW_ON_ERROR));
    }
    public function testNotificationHistoryDoesNotPersistOrExposeDeliverySecrets(): void
    {
        $center = new \Coleza\Domain\Notifications\Center\NotificationCenterService($this->db->getPdo());
        $center->recordDelivery(\Coleza\Domain\Notifications\Channel\NotificationChannel::EMAIL, 'subject@example.test', 'Reset notification', 'failed', error: 'Remote echoed synthetic-notification-secret', metadata: ['token' => 'synthetic-notification-secret']);
        self::assertStringNotContainsString('synthetic-notification-secret', json_encode($this->db->select('SELECT * FROM notification_logs'), JSON_THROW_ON_ERROR));
    }
    public function testIncompletePrivacyScanCannotReportCompliant(): void
    {
        $scan = (new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($this->db))->scanForUnredactedPii(7);
        self::assertFalse($scan->isCompliant());
    }
    public function testProviderExceptionCannotExposeItsKnownSecretContext(): void
    {
        $error = new \Coleza\Domain\Providers\Exceptions\ProviderException('Remote echoed synthetic-provider-secret', context: ['password' => 'synthetic-provider-secret']);
        self::assertStringNotContainsString('synthetic-provider-secret', $error->getMessage());
        self::assertStringNotContainsString('synthetic-provider-secret', json_encode($error->getContext(), JSON_THROW_ON_ERROR));
    }
    public function testApiHistoryMasksResetTokenPathSegments(): void
    {
        $logger = new \Coleza\Api\Logging\ApiRequestLogger($this->db); $logger->ensureTable();
        $logger->log('GET', '/api/password-reset/synthetic-route-secret', 200, 3, 7);
        self::assertStringNotContainsString('synthetic-route-secret', json_encode($logger->getLogsForKey(7), JSON_THROW_ON_ERROR));
    }

    public function testKnownSecretEchoesAreRemovedFromSiblingDiagnosticFields(): void
    {
        $secret = 'synthetic-sibling-secret';
        $meta = ['password' => $secret, 'details' => ['remote' => "Echoed $secret"]];
        $error = new \Coleza\Domain\Providers\Exceptions\ProviderException("Echoed $secret", context: $meta);
        $health = \Coleza\Foundation\Health\HealthCheckResult::unhealthy('probe', "Echoed $secret", $meta);
        self::assertStringNotContainsString($secret, json_encode([$error->getMessage(), $error->getContext(), $health->getMessage(), $health->getMeta()], JSON_THROW_ON_ERROR));
        $center = new \Coleza\Domain\Notifications\Center\NotificationCenterService($this->db->getPdo());
        $center->recordDelivery(\Coleza\Domain\Notifications\Channel\NotificationChannel::EMAIL, 'test@example.test', 'Delivery', 'failed', metadata: $meta);
        self::assertStringNotContainsString($secret, json_encode($this->db->select('SELECT * FROM notification_logs'), JSON_THROW_ON_ERROR));
    }

    public function testLegacyNotificationReadMasksWithoutRewritingEvidence(): void
    {
        $center = new \Coleza\Domain\Notifications\Center\NotificationCenterService($this->db->getPdo());
        $center->recordDelivery(\Coleza\Domain\Notifications\Channel\NotificationChannel::EMAIL, 'test@example.test', 'Delivery', 'failed');
        $this->db->statement('UPDATE notification_logs SET error = ?, metadata_json = ?', ['Echoed synthetic-old-secret', '{"token":"synthetic-old-secret"}']);
        $before = $this->db->select('SELECT * FROM notification_logs');
        $log = $center->getDeliveryLogs()[0];
        self::assertStringNotContainsString('synthetic-old-secret', json_encode([$log->getError(), $log->getMetadata()], JSON_THROW_ON_ERROR));
        self::assertSame($before, $this->db->select('SELECT * FROM notification_logs'));
    }

    public function testCompleteCleanScanAndMissingColumnHaveDifferentResults(): void
    {
        $this->db->statement('CREATE TABLE users (id INT, status VARCHAR(32), name VARCHAR(128), phone VARCHAR(32), tax_number VARCHAR(32), password_hash VARCHAR(128))');
        $this->db->statement('CREATE TABLE payment_methods (id INT, user_id INT, token VARCHAR(128), card_last4 VARCHAR(4))');
        $this->db->statement('CREATE TABLE billing_profiles (id INT, user_id INT, phone VARCHAR(32), tax_number VARCHAR(32))');
        $this->db->statement('CREATE TABLE hosting_services (id INT, user_id INT, password_encrypted VARCHAR(128))');
        $this->db->statement('CREATE TABLE privacy_consents (user_id INT)');
        $audit = new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($this->db);
        self::assertTrue($audit->scanForUnredactedPii(7)->isCompliant());
        $this->db->statement('INSERT INTO payment_methods VALUES (1, 7, ?, ?)', ['synthetic-residual-token', '1234']);
        $dirty = $audit->scanForUnredactedPii(7);
        self::assertTrue($dirty->isComplete()); self::assertFalse($dirty->isCompliant());
        self::assertStringNotContainsString('synthetic-residual-token', json_encode($dirty, JSON_THROW_ON_ERROR));
        $this->db->statement('DROP TABLE billing_profiles');
        $this->db->statement('CREATE TABLE billing_profiles (user_id INT)');
        $incomplete = $audit->scanForUnredactedPii(7);
        self::assertFalse($incomplete->isComplete()); self::assertFalse($incomplete->isCompliant());
        self::assertSame(['billing_profiles'], $incomplete->getUnverifiedTables());
    }

    public function testApiErrorSafetyPreservesSuccessfulAuthenticationPayload(): void
    {
        $response = \Coleza\Api\Response\ApiResponse::error('Echoed synthetic-api-secret', errors: ['token' => ['synthetic-api-secret']]);
        self::assertStringNotContainsString('synthetic-api-secret', json_encode($response, JSON_THROW_ON_ERROR));
        $response = \Coleza\Api\Response\ApiResponse::error('Echoed synthetic-api-secret', 'INVALID_INPUT', 422, ['token' => ['synthetic-api-secret']]);
        self::assertStringNotContainsString('synthetic-api-secret', json_encode($response, JSON_THROW_ON_ERROR));
        self::assertSame('INVALID_INPUT', $response['error_code']);
        self::assertSame(['token' => 'synthetic-auth-token'], \Coleza\Api\Response\ApiResponse::success(['token' => 'synthetic-auth-token'])['data']);
    }

    public function testNotificationDeliveryStillContainsTheRecipientsResetLink(): void
    {
        $mailer = new \Coleza\Domain\Notifications\Transport\MemoryMailTransport();
        $engine = new \Coleza\Domain\Notifications\NotificationEngine($mailer);
        $url = 'https://example.test/reset/synthetic-delivery-token';
        $engine->sendDirectEmail('recipient@example.test', 'Reset', '<a href="'.$url.'">Reset</a>', $url);
        $message = $mailer->getSentMessages()[0];
        self::assertStringContainsString($url, $message->getHtmlBody());
        self::assertSame($url, $message->getPlainTextBody());
    }

    public function testProviderClassificationRetainsRetryDecisionButRemovesSecrets(): void
    {
        $result = \Coleza\Domain\Providers\DTO\ProviderOperationResult::failure('create', 'timeout echoed synthetic-classifier-secret', 'TIMEOUT', ['password' => 'synthetic-classifier-secret']);
        $classified = \Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassifier::classifyResult($result);
        self::assertTrue($classified->isRetryable());
        self::assertStringNotContainsString('synthetic-classifier-secret', json_encode($classified->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('synthetic-classifier-secret', $result->getData()['password']);
        $exception = \Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassifier::classifyThrowable(new \RuntimeException('timeout password=synthetic-trace-secret'));
        self::assertArrayNotHasKey('trace', $exception->getRawDetails()['raw_response']);
        self::assertStringNotContainsString('synthetic-trace-secret', json_encode($exception->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testQueueFailureStoresExceptionTypeWithoutTraceOrMessage(): void
    {
        $queue = new \Coleza\Foundation\Queue\DatabaseQueue($this->db, new \Coleza\Domain\Vault\QueuePayloadStore($this->db, \Coleza\Tests\Support\SecretFixtureKey::encryptor()));
        $queue->push(new SecretAcceptanceFailingJob());
        $worker = new \Coleza\Foundation\Queue\QueueWorker($queue);
        self::assertTrue($worker->runNext());
        self::assertSame(0, $queue->size()); self::assertSame(1, $queue->failedSize());
        self::assertSame(\RuntimeException::class, $this->db->selectOne('SELECT exception FROM failed_jobs')['exception']);
    }

    public function testSqlDiagnosticLexerRemovesCommentsEscapedStringsAndNumbers(): void
    {
        $sql = "SELECT 'synthetic-sql-secret''suffix', \"synthetic-double-secret\", 876543 /* synthetic-comment-secret */ -- synthetic-line-secret\n FROM example_table WHERE id = :id";
        $safe = \Coleza\Foundation\Database\SqlDiagnostics::statement($sql);
        foreach (['synthetic-', '876543'] as $value) { self::assertStringNotContainsString($value, $safe); }
        self::assertStringContainsString('FROM example_table WHERE id = :id', $safe);
        $ambiguous = \Coleza\Foundation\Database\SqlDiagnostics::statement("SELECT 'path\\', 'synthetic-mode-secret'");
        self::assertStringNotContainsString('synthetic-mode-secret', $ambiguous);
        self::assertSame('[SQL statement omitted: ambiguous quoting]', $ambiguous);
    }
    public function testUnsafeLegacyPrivacyExportIsRejectedWithoutRewritingItsRow(): void
    {
        $service = new \Coleza\Domain\Privacy\Requests\PrivacyRequestService($this->db);
        $request = $service->createRequest(7, \Coleza\Domain\Privacy\Requests\PrivacyRequestType::EXPORT)['request'];
        $payload = ['schema' => 'colezahost_gdpr_export_v1', 'user_id' => 7, 'generated_at' => '2026-10-15 09:00:00', 'data' => ['password' => 'synthetic-export-secret', 'note' => 'Echoed synthetic-export-secret']];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->db->statement('UPDATE privacy_requests SET completed_at = ?, export_data_json = ?, export_checksum = ? WHERE id = ?', [$payload['generated_at'], $json, hash('sha256', $json), $request->getId()]);
        $before = $this->db->select('SELECT * FROM privacy_requests');
        self::assertNull($service->getExport($request->getId()));
        self::assertSame($before, $this->db->select('SELECT * FROM privacy_requests'));
        $safe = \Coleza\Domain\Privacy\Export\PrivacyExportPackage::create(7, $payload['data'], new \DateTimeImmutable($payload['generated_at']));
        self::assertTrue($safe->verifyIntegrity());
        self::assertStringNotContainsString('synthetic-export-secret', $safe->toJson());
        $this->db->statement('UPDATE privacy_requests SET export_data_json = ?, export_checksum = ? WHERE id = ?', [$safe->toJson(), $safe->getChecksum(), $request->getId()]);
        self::assertTrue($service->getExport($request->getId())->verifyIntegrity());
    }

    public function testFailedHealthMeasurementIsUnhealthyAndCannotEchoExceptionSecrets(): void
    {
        $hook = new \Coleza\Domain\Integrations\Health\WebhookQueueHealthCheck(pdo: $this->db->getPdo());
        $failed = $hook->check();
        self::assertTrue($failed->isUnhealthy());
        self::assertArrayHasKey('exception_class', $failed->getMeta());
        $config = new \Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration('synthetic-ping-key', 'synthetic-ping-secret', 'https://example.test', true);
        $payment = new \Coleza\Domain\Integrations\Health\PaymentGatewayHealthCheck(null, $config, static function (): never { throw new \RuntimeException('Echoed synthetic-probe-secret'); });
        $failed = $payment->check();
        self::assertTrue($failed->isUnhealthy());
        self::assertStringNotContainsString('synthetic-probe-secret', json_encode([$failed->getMessage(), $failed->getMeta()], JSON_THROW_ON_ERROR));
    }

    public function testDiagnosticScrubbingDoesNotCallObjectHooksAndHandlesEncodedRoutes(): void
    {
        $value = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed { throw new \RuntimeException('A diagnostic boundary must never execute this hook.'); }
        };
        self::assertSame(['value' => '[object]'], \Coleza\Foundation\Security\SecretRedactor::diagnostic(['value' => $value]));
        self::assertStringNotContainsString('synthetic-encoded-token', \Coleza\Foundation\Security\SecretRedactor::route('/api/%2572eset-password/synthetic-encoded-token?token=ignored'));
        self::assertSame('/api/invoices/7', \Coleza\Foundation\Security\SecretRedactor::route('/api/invoices/7?access_token=ignored'));
    }

}

final class SecretAcceptanceFailingJob implements \Coleza\Foundation\Queue\JobInterface
{
    public function handle(): void { throw new \RuntimeException('Echoed synthetic-job-exception-secret'); }
    public function queue(): string { return 'default'; }
    public function maxAttempts(): int { return 1; }
    public function backoffSeconds(): int { return 0; }
}
