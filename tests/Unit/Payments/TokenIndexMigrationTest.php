<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Payments;

use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Payments\PaymentConcurrencySchema;
use Coleza\Foundation\Database\Connection;
use PHPUnit\Framework\TestCase;

final class TokenIndexMigrationTest extends TestCase
{
    private Connection $db;
    private PaymentService $payments;

    protected function setUp(): void
    {
        $this->db = new Connection(new \PDO('sqlite::memory:'));
        $this->payments = new PaymentService($this->db);
        $this->payments->ensureTables();
    }

    public function testLegacyTokenColumnIsAddedAndBackfilled(): void
    {
        $this->db->statement('DROP TABLE payments');
        $this->db->statement('CREATE TABLE payments (id INTEGER PRIMARY KEY, metadata_json TEXT)');
        $this->db->statement('INSERT INTO payments VALUES (1, ?)', ['{"checkout_token":"legacy-token"}']);
        (new PaymentConcurrencySchema($this->db))->upgrade();
        self::assertSame(hash('sha256', 'legacy-token'), $this->db->selectOne('SELECT checkout_token_hash FROM payments')['checkout_token_hash']);
        (new PaymentConcurrencySchema($this->db))->upgrade();
        self::assertSame(1, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM payments')['n']);
    }

    public function testDuplicateLegacyTokensBlockMigrationWithoutDiscardingRecords(): void
    {
        $this->db->statement('DROP TABLE payments');
        $this->db->statement('CREATE TABLE payments (id INTEGER PRIMARY KEY, metadata_json TEXT)');
        $this->db->statement('INSERT INTO payments VALUES (1, ?), (2, ?)', ['{"checkout_token":"same"}', '{"checkout_token":"same"}']);
        try {
            (new PaymentConcurrencySchema($this->db))->upgrade();
            self::fail('Conflicting tokens require reconciliation.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Duplicate legacy checkout token', $error->getMessage());
        }
        self::assertSame(2, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM payments')['n']);
        self::assertNotContains('checkout_token_hash', array_column($this->db->select('PRAGMA table_info(payments)'), 'name'));
    }

    public function testDuplicateLegacyEventsAreNotSilentlyMergedOrDeleted(): void
    {
        $this->db->statement('DROP TABLE payment_webhook_events');
        $this->db->statement('CREATE TABLE payment_webhook_events (id INTEGER PRIMARY KEY, gateway TEXT, event_id TEXT, status TEXT)');
        $this->db->statement('INSERT INTO payment_webhook_events VALUES (1, "iyzico", "old-event", "processed"), (2, "iyzico", "old-event", "failed")');
        try {
            (new PaymentConcurrencySchema($this->db))->upgrade();
            self::fail('Duplicate historical events require reconciliation.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Duplicate legacy webhook events', $error->getMessage());
        }
        self::assertSame(2, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM payment_webhook_events')['n']);
    }

    public function testIndexedTokenLookupHasConstantQueryBudget(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->payments->recordPayment(['user_id' => 7, 'amount_minor' => 1000, 'currency_code' => 'TRY',
                'payment_method' => 'iyzico', 'status' => 'pending', 'metadata' => ['checkout_token' => 'token-' . $i]]);
        }
        $this->db->enableQueryLog();
        self::assertNotNull($this->payments->findPaymentByToken('token-99'));
        self::assertSame(3, $this->db->getQueryCount());
        $plan = $this->db->selectOne('EXPLAIN QUERY PLAN SELECT id FROM payments WHERE checkout_token_hash = ?', [hash('sha256', 'token-99')]);
        self::assertStringContainsString('INDEX', $plan['detail']);
    }

    public function testTokenCannotBeReplacedOrAssignedToAnotherPayment(): void
    {
        $first = $this->payments->recordPayment(['user_id' => 7, 'amount_minor' => 1000, 'currency_code' => 'TRY',
            'payment_method' => 'iyzico', 'status' => 'pending']);
        $second = $this->payments->recordPayment(['user_id' => 7, 'amount_minor' => 1000, 'currency_code' => 'TRY',
            'payment_method' => 'iyzico', 'status' => 'pending']);
        $this->payments->attachCheckoutToken((int) $first->getId(), 'original');
        foreach ([[$first->getId(), 'replacement'], [$second->getId(), 'original']] as [$id, $token]) {
            try {
                $this->payments->attachCheckoutToken((int) $id, $token);
                self::fail('Replacing or duplicating a token must fail.');
            } catch (\Coleza\Foundation\Exceptions\ValidationException) {}
        }
        self::assertSame($first->getId(), $this->payments->findPaymentByToken('original')->getId());
        self::assertNull($this->payments->findPaymentByToken('replacement'));
    }
}
