<?php
declare(strict_types=1);
namespace Coleza\Domain\Migration\Adoption;

use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;

/** Converts only the documented legacy adoption shapes; original tables remain archived. */
final class LegacyAdoptionUpgrade
{
    private const MARKERS = 'legacy_adoption_upgrades';
    private const LEGACY = [
        'services' => ['id','user_id','product_id','server_id','domain','username','status','billing_cycle','recurring_amount','currency','registration_date','next_due_date','is_adopted','metadata_json','created_at'],
        'domains' => ['id','user_id','domain_name','registrar','status','registration_period_years','recurring_amount','currency','registration_date','expiry_date','next_due_date','auto_renew','id_protection','nameservers_json','is_adopted','metadata_json','created_at'],
    ];
    private const CANONICAL = [
        'services' => ['service_number','recurring_amount_minor','currency_code'],
        'domains' => ['domain','sld','tld','registrar_id'],
    ];

    public function __construct(private Connection $db) {}

    public function upgrade(?string $only = null): void
    {
        $tables = $only === null ? ['services', 'domains'] : [$only];
        foreach ($tables as $table) {
            if (!isset(self::LEGACY[$table])) { throw new \InvalidArgumentException('Unknown legacy adoption table.'); }
        }
        if ($this->preflight($tables) === []) { return; }
        PdoSchema::autoIncrement($this->db->getPdo());
        $lock = null;
        if ($this->db->getDriverName() === 'mysql') {
            $database = (string) $this->db->getPdo()->query('SELECT DATABASE()')->fetchColumn();
            $lock = 'coleza_adoption_v12_' . substr(hash('sha256', $database), 0, 32);
            if ((int) $this->db->selectOne('SELECT GET_LOCK(?, 30) AS acquired', [$lock])['acquired'] !== 1) {
                throw new \RuntimeException('Legacy adoption upgrade lock unavailable.');
            }
        }
        try {
            $plans = $this->preflight($tables);
            if ($plans === []) { return; }
            $this->db->statement('CREATE TABLE IF NOT EXISTS legacy_adoption_upgrades (
                entity VARCHAR(16) PRIMARY KEY, source_checksum VARCHAR(64) NOT NULL,
                source_count INT NOT NULL, source_next_id INT NOT NULL, status VARCHAR(16) NOT NULL, completed_at VARCHAR(32) NULL
            )');
            foreach ($plans as $table => $plan) { $this->copy($table, $plan); }
        } finally {
            if ($lock !== null) { $this->db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]); }
        }
    }

    private function preflight(array $tables): array
    {
        $plans = [];
        $markerColumns = $this->columns(self::MARKERS);
        if ($markerColumns !== [] && array_diff(['entity','source_checksum','source_count','source_next_id','status','completed_at'], $markerColumns) !== []) {
            $this->stop('Unknown upgrade marker schema.');
        }
        foreach ($tables as $table) {
            $archive = $this->archive($table);
            $columns = $this->columns($table);
            $archiveColumns = $this->columns($archive);
            $marker = $markerColumns === [] ? null : $this->db->selectOne('SELECT * FROM legacy_adoption_upgrades WHERE entity = ?', [$table]);
            $canonical = $columns !== [] && array_diff(self::CANONICAL[$table], $columns) === [];
            if ($marker !== null && $marker['status'] === 'complete') {
                if (!$canonical) { $this->stop('Completed upgrade has no canonical ' . $table . ' table.'); }
                if ($archiveColumns === []) { $this->stop('Completed upgrade archive is missing.'); }
                continue; // Never replay archived values over later customer changes.
            }
            if ($marker !== null && $marker['status'] !== 'copying') { $this->stop('Unknown upgrade state.'); }
            if ($archiveColumns !== []) {
                if ($marker === null) { $this->stop('Archive name collision: ' . $archive); }
                $source = $archive;
                $sourceColumns = $archiveColumns;
                if ($columns !== [] && (!$canonical || $this->db->select('SELECT id FROM ' . $table . ' LIMIT 1') !== [])) {
                    $this->stop('Interrupted upgrade target is not empty: ' . $table);
                }
            } else {
                if ($canonical || $columns === []) {
                    if ($marker !== null) { $this->stop('Interrupted upgrade source missing.'); }
                    continue;
                }
                $source = $table;
                $sourceColumns = $columns;
            }
            $optional = $table === 'services' ? ['auto_renew','grace_period_days','termination_grace_period_days','lock_version'] : [];
            if (array_diff(self::LEGACY[$table], $sourceColumns) !== [] || array_diff($sourceColumns, array_merge(self::LEGACY[$table], $optional)) !== []) {
                $this->stop('Unknown legacy ' . $table . ' schema.');
            }
            $this->checkDependencies($source);
            $rows = $this->db->select('SELECT * FROM ' . $source . ' ORDER BY id');
            $converted = [];
            $names = [];
            foreach ($rows as $row) {
                $record = $this->convert($table, $row);
                if ($table === 'domains') {
                    if (isset($names[$record['domain']])) { $this->stop('Duplicate normalized domain in legacy data.'); }
                    $names[$record['domain']] = true;
                }
                $converted[] = $record;
            }
            $checksum = $this->checksum($rows);
            $nextId = $this->nextId($source);
            if ($marker !== null && (!hash_equals($marker['source_checksum'], $checksum) || (int) $marker['source_count'] !== count($rows) || (int) $marker['source_next_id'] !== $nextId)) {
                $this->stop('Archived source changed after interrupted upgrade.');
            }
            if ($table === 'services' && $this->columns('service_placements') !== []) {
                foreach ($rows as $row) {
                    $placements = $this->db->select('SELECT server_id FROM service_placements WHERE service_id = ? AND released_at IS NULL', [$row['id']]);
                    if (count($placements) > 1 || ($placements !== [] && $row['server_id'] !== null && (int) $placements[0]['server_id'] !== (int) $row['server_id'])) { $this->stop('Conflicting legacy service placement.'); }
                }
            }
            $plans[$table] = ['rows' => $rows, 'converted' => $converted, 'checksum' => $checksum, 'next_id' => $nextId, 'renamed' => $source === $archive, 'marker' => $marker];
        }
        return $plans;
    }

    private function copy(string $table, array $plan): void
    {
        if ($plan['marker'] === null) {
            $this->db->insert(self::MARKERS, ['entity' => $table, 'source_checksum' => $plan['checksum'], 'source_count' => count($plan['rows']), 'source_next_id' => $plan['next_id'], 'status' => 'copying', 'completed_at' => null]);
        }
        $archive = $this->archive($table);
        if (!$plan['renamed']) {
            $sql = $this->db->getDriverName() === 'mysql' ? "RENAME TABLE {$table} TO {$archive}" : "ALTER TABLE {$table} RENAME TO {$archive}";
            $this->db->statement($sql);
        }
        if (!hash_equals($plan['checksum'], $this->checksum($this->db->select('SELECT * FROM ' . $archive . ' ORDER BY id')))) {
            $this->stop('Source changed while archiving; reconcile before copying.');
        }
        if ($table === 'services') { (new ServiceService($this->db))->ensureTables(true); }
        else { (new DomainService($this->db))->ensureTables(true); }
        // Set the next-id upper bound before the data transaction: MySQL ALTER implicitly commits.
        if ($this->db->getDriverName() === 'mysql') {
            $this->db->statement('ALTER TABLE ' . $table . ' AUTO_INCREMENT = ' . $plan['next_id']);
        } else {
            if ($this->db->selectOne('SELECT seq FROM sqlite_sequence WHERE name = ?', [$table]) === null) {
                $this->db->insert('sqlite_sequence', ['name' => $table, 'seq' => $plan['next_id'] - 1]);
            } else {
                $this->db->statement('UPDATE sqlite_sequence SET seq = ? WHERE name = ?', [$plan['next_id'] - 1, $table]);
            }
        }
        $this->db->transaction(function () use ($table, $plan): void {
            foreach ($plan['converted'] as $offset => $record) {
                $this->db->insert($table, $record);
                if ($table === 'services' && $plan['rows'][$offset]['server_id'] !== null) {
                    if ($this->db->selectOne('SELECT id FROM service_placements WHERE service_id = ? AND released_at IS NULL', [$record['id']]) === null) {
                        $this->db->insert('service_placements', [
                            'service_id' => $record['id'], 'server_id' => (int) $plan['rows'][$offset]['server_id'],
                            'status' => 'placed', 'assigned_at' => null, 'created_at' => $record['created_at'],
                        ]);
                    }
                }
            }
            $this->db->update(self::MARKERS, ['status' => 'complete', 'completed_at' => date('Y-m-d H:i:s')], 'entity = :entity', ['entity' => $table]);
        });
    }

    private function convert(string $table, array $row): array
    {
        foreach (['id','user_id'] as $key) { $this->positive($row[$key], $key); }
        if (!in_array((string) $row['is_adopted'], ['0','1'], true)) { $this->stop('Unknown adoption flag.'); }
        $metadata = $this->jsonObject($row['metadata_json']);
        if (array_key_exists('legacy_upgrade', $metadata)) { $this->stop('Reserved legacy metadata key already exists.'); }
        $currency = (string) $row['currency'];
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) { $this->stop('Invalid legacy currency.'); }
        $precision = $this->columns('currencies') === [] ? null : $this->db->selectOne('SELECT decimal_places FROM currencies WHERE code = ?', [$currency]);
        if (($precision !== null && (int) $precision['decimal_places'] !== 2) || ($precision === null && !in_array($currency, ['USD','TRY','EUR','GBP'], true))) {
            $this->stop('Legacy amount conversion requires explicit supported two-decimal currency precision.');
        }
        $minor = $this->minor($row['recurring_amount']);
        if (!is_string($row['created_at']) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $row['created_at'])) { $this->stop('Unknown legacy creation timestamp.'); }
        $this->date(substr($row['created_at'], 0, 10), false);
        $timestamp = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row['created_at']);
        if ($timestamp === false || $timestamp->format('Y-m-d H:i:s') !== $row['created_at']) { $this->stop('Invalid legacy creation timestamp.'); }
        $metadata['legacy_upgrade'] = ['version' => 12, 'source_table' => $this->archive($table), 'source_id' => (int) $row['id']];
        if (array_key_exists('is_adopted', $metadata) && (bool) $metadata['is_adopted'] !== (bool) $row['is_adopted']) { $this->stop('Conflicting legacy adoption flag.'); }
        $metadata['is_adopted'] = (bool) $row['is_adopted'];
        $common = ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'status' => $row['status'], 'created_at' => $row['created_at']];
        if ($table === 'services') {
            $this->positive($row['product_id'], 'product_id');
            if ($row['server_id'] !== null) { $this->positive($row['server_id'], 'server_id'); }
            if (!in_array($row['status'], ['pending','active','suspended','cancelled','terminated','fraud'], true) || !PriceCycle::isValid($row['billing_cycle'])) { $this->stop('Unknown legacy service state or cycle.'); }
            foreach (['registration_date','next_due_date'] as $key) { $this->date($row[$key], false); }
            if (($row['domain'] !== null && mb_strlen($row['domain']) > 255) || ($row['username'] !== null && mb_strlen($row['username']) > 100)) { $this->stop('Legacy service fields exceed canonical lengths.'); }
            if (array_key_exists('auto_renew', $row) && !in_array((string) $row['auto_renew'], ['0','1'], true)) { $this->stop('Unknown legacy renewal flag.'); }
            foreach (['grace_period_days','termination_grace_period_days'] as $key) {
                if (array_key_exists($key, $row) && (!preg_match('/^\d+$/D', (string) $row[$key]) || (float) $row[$key] > 2147483647)) { $this->stop('Invalid legacy grace period.'); }
            }
            if (array_key_exists('lock_version', $row)) { $this->positive($row['lock_version'], 'lock_version'); }
            $metadata['legacy_upgrade']['auto_renew_unknown'] = !array_key_exists('auto_renew', $row);
            $metadata['provisioning_suppressed'] = (bool) $row['is_adopted'] || (bool) ($metadata['provisioning_suppressed'] ?? false);
            return $common + [
                'service_number' => 'SRV-LEGACY-' . $row['id'], 'product_id' => (int) $row['product_id'],
                'billing_cycle' => $row['billing_cycle'], 'recurring_amount_minor' => $minor, 'currency_code' => $currency,
                'registration_date' => $row['registration_date'], 'next_due_date' => $row['next_due_date'],
                'domain' => $row['domain'], 'username' => $row['username'], 'metadata_json' => $this->encodeMetadata($metadata),
                'auto_renew' => (int) ($row['auto_renew'] ?? 0), 'grace_period_days' => (int) ($row['grace_period_days'] ?? 7),
                'termination_grace_period_days' => (int) ($row['termination_grace_period_days'] ?? 30), 'lock_version' => (int) ($row['lock_version'] ?? 1),
            ];
        }
        $name = strtolower(trim($row['domain_name']));
        if (strlen($name) > 255 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $name)) { $this->stop('Invalid legacy domain name.'); }
        $dot = strpos($name, '.');
        $sld = substr($name, 0, $dot);
        $tld = substr($name, $dot);
        if (strlen($tld) > 32) { $this->stop('Legacy domain suffix exceeds canonical field length.'); }
        if (!in_array($row['status'], ['pending_registration','active','pending_transfer','expired','grace','redemption','cancelled','transferred_out'], true)) { $this->stop('Unknown legacy domain state.'); }
        $this->positive($row['registration_period_years'], 'registration_period_years');
        if ($row['registrar'] === '' || strlen($row['registrar']) > 64) { $this->stop('Invalid legacy registrar.'); }
        foreach (['registration_date','expiry_date','next_due_date'] as $key) { $this->date($row[$key], true); }
        foreach (['auto_renew','id_protection'] as $key) {
            if (!in_array((string) $row[$key], ['0','1'], true)) { $this->stop('Unknown legacy domain flag.'); }
        }
        if ($row['nameservers_json'] !== null) {
            try { $nameservers = json_decode($row['nameservers_json'], true, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException $error) { $this->stop('Invalid legacy nameserver JSON.'); }
            if (!is_array($nameservers) || !array_is_list($nameservers) || array_filter($nameservers, fn ($name): bool => !is_string($name)) !== []) { $this->stop('Invalid legacy nameserver list.'); }
        }
        if (array_key_exists('adopted_billing', $metadata)) { $this->stop('Reserved billing metadata key already exists.'); }
        $metadata['adopted_billing'] = ['recurring_amount' => $minor / 100, 'currency_code' => $currency];
        $metadata['registrar_api_suppressed'] = (bool) $row['is_adopted'] || (bool) ($metadata['registrar_api_suppressed'] ?? false);
        return $common + [
            'domain' => $name, 'sld' => $sld, 'tld' => $tld, 'registrar_id' => $row['registrar'],
            'registration_period_years' => (int) $row['registration_period_years'], 'registration_date' => $row['registration_date'],
            'expiry_date' => $row['expiry_date'], 'next_due_date' => $row['next_due_date'], 'auto_renew' => (int) $row['auto_renew'],
            'whois_privacy' => (int) $row['id_protection'], 'dns_management' => null, 'email_forwarding' => null, 'is_locked' => null,
            'nameservers_json' => $row['nameservers_json'], 'metadata_json' => $this->encodeMetadata($metadata), 'updated_at' => null,
        ];
    }

    private function checkDependencies(string $source): void
    {
        if ($this->db->getDriverName() === 'mysql') {
            if ($this->db->select('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND ((TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?) OR (REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?))', [$source, $source]) !== [] ||
                $this->db->select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', [$source]) !== []) {
                $this->stop('Legacy table has foreign-key or trigger dependencies.');
            }
            if ($this->db->select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$source]) !== []) { $this->stop('Legacy table has custom CHECK constraints.'); }
            $indexes = [];
            foreach ($this->db->select('SHOW INDEX FROM ' . $source) as $index) {
                $indexes[$index['Key_name']]['columns'][(int) $index['Seq_in_index']] = $index['Column_name'];
                $indexes[$index['Key_name']]['unique'] = (int) $index['Non_unique'] === 0;
            }
            foreach ($indexes as $index) {
                ksort($index['columns']);
                if (!$index['unique'] || !in_array(array_values($index['columns']), [['id'], ['domain_name']], true)) { $this->stop('Unknown legacy index.'); }
            }
        } else {
            if ($this->db->select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?", [$source]) !== []) { $this->stop('Legacy table has trigger dependencies.'); }
            foreach ($this->db->select("SELECT name FROM sqlite_master WHERE type = 'table'") as $table) {
                $quoted = '`' . str_replace('`', '``', $table['name']) . '`';
                foreach ($this->db->select('PRAGMA foreign_key_list(' . $quoted . ')') as $foreign) {
                    if ($foreign['table'] === $source || $table['name'] === $source) { $this->stop('Legacy table has foreign-key dependencies.'); }
                }
            }
            $definition = $this->db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$source])['sql'];
            if (preg_match('/\bCHECK\s*\(/i', $definition) || !preg_match('/\bAUTOINCREMENT\b/i', $definition)) { $this->stop('Unknown legacy table constraints or id allocation.'); }
            foreach ($this->db->select('PRAGMA index_list(' . $source . ')') as $index) {
                $quoted = '`' . str_replace('`', '``', $index['name']) . '`';
                $columns = array_column($this->db->select('PRAGMA index_info(' . $quoted . ')'), 'name');
                if ((int) $index['unique'] !== 1 || !in_array($columns, [['id'], ['domain_name']], true)) { $this->stop('Unknown legacy index.'); }
            }
        }
    }

    private function columns(string $table): array
    {
        return $this->db->getDriverName() === 'sqlite'
            ? array_column($this->db->select('PRAGMA table_info(' . $table . ')'), 'name')
            : array_column($this->db->select('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]), 'COLUMN_NAME');
    }
    private function nextId(string $table): int
    {
        $next = $this->db->getDriverName() === 'mysql'
            ? $this->db->selectOne('SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])['next_id']
            : ((int) ($this->db->selectOne('SELECT seq FROM sqlite_sequence WHERE name = ?', [$table])['seq'] ?? 0) + 1);
        $this->positive($next, 'next_id');
        return (int) $next;
    }
    private function archive(string $table): string { return 'legacy_adoption_' . $table . '_v12'; }
    private function checksum(array $rows): string { return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)); }
    private function positive(mixed $value, string $field): void
    {
        if (!preg_match('/^[1-9]\d*$/D', (string) $value) || (float) $value > 2147483647) { $this->stop('Invalid legacy ' . $field); }
    }
    private function minor(mixed $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', (string) $amount, $parts) || strlen($parts[1]) > 8) { $this->stop('Invalid legacy amount precision or sign.'); }
        $minor = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($minor > 2147483647) { $this->stop('Legacy amount exceeds canonical range.'); }
        return $minor;
    }
    private function date(mixed $date, bool $nullable): void
    {
        if ($date === null && $nullable) { return; }
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) { $this->stop('Missing or invalid legacy date.'); }
    }
    private function jsonObject(?string $json): array
    {
        if ($json === null) { return []; }
        try { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { $this->stop('Invalid legacy metadata JSON.'); }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) { $this->stop('Legacy metadata must be an object.'); }
        return $value;
    }
    private function encodeMetadata(array $metadata): string
    {
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 65535) { $this->stop('Converted metadata exceeds canonical TEXT length.'); }
        return $json;
    }
    private function stop(string $reason): never { throw new \LogicException('Legacy adoption requires explicit reconciliation: ' . $reason); }
}
