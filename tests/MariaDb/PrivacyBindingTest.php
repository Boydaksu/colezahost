<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class PrivacyBindingTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\PrivacyBindingCases;

    public function testCurrentBindingFencesCachedWriterInsideAnOlderParentSnapshot(): void
    {
        $writer = $this->privacyWriter(); $writer->recordTombstone(7, 'first@example.test');
        $dsn = getenv('COLEZA_TEST_MARIADB_DSN') . ';dbname=' . $this->database;
        $other = new \Coleza\Foundation\Database\Connection(new \PDO($dsn, getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        $replacement = \Coleza\Domain\Privacy\Integrity\PrivacyKeyBinding::value('tombstone', \Coleza\Tests\Support\PrivacyFixtureKeys::redaction());
        $this->root->beginTransaction();
        try {
            $old = $this->db->selectOne("SELECT * FROM privacy_key_bindings WHERE purpose='tombstone'");
            // Simulate a newer signed binding, not a supported privacy-rotation procedure.
            $other->update('privacy_key_bindings', ['key_id' => $replacement['key_id'], 'proof' => $replacement['proof']], 'purpose = :purpose', ['purpose' => 'tombstone']);
            self::assertSame($old, $this->db->selectOne("SELECT * FROM privacy_key_bindings WHERE purpose='tombstone'"));
            $this->bindingRefused(fn () => $writer->recordTombstone(8, 'second@example.test'));
            self::assertTrue($this->root->inTransaction());
            self::assertNull($this->db->selectOne('SELECT * FROM privacy_tombstones WHERE user_id=8'));
        } finally { $this->root->rollBack(); }
    }
    public function testNonTransactionalOwnerStorageFailsBeforeBindingOrRecordWrites(): void
    {
        $this->privacyWriter()->ensureTables(); $this->db->statement('ALTER TABLE privacy_tombstones ENGINE=MyISAM');
        $before = $this->bindingSnapshot();
        $this->bindingRefused(fn () => $this->privacyWriter()->recordTombstone(7, 'first@example.test'));
        self::assertSame($before, $this->bindingSnapshot());
    }
}
