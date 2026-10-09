<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Adoption;

use Coleza\Foundation\Database\Connection;

final class AdoptedIdentityRepository
{
    private string $table = 'adopted_provider_identities';

    public function __construct(
        private Connection $db
    ) {
    }

    public function ensureTable(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                batch_id VARCHAR(64) NOT NULL,
                identity_type VARCHAR(32) NOT NULL,
                source_id VARCHAR(64) NOT NULL,
                source_system VARCHAR(32) NOT NULL,
                target_entity_id INT NOT NULL,
                provider_type VARCHAR(32) NOT NULL,
                provider_identifier VARCHAR(128) NOT NULL,
                external_reference_id VARCHAR(128) NOT NULL,
                external_metadata_json TEXT NULL,
                remote_verification_passed TINYINT(1) NOT NULL DEFAULT 0,
                suppress_provisioning TINYINT(1) NOT NULL DEFAULT 1,
                adopted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (provider_type, provider_identifier, external_reference_id)
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    public function save(AdoptedProviderIdentity $identity): void
    {
        $this->ensureTable();

        $metaJson = json_encode($identity->getExternalMetadata(), JSON_THROW_ON_ERROR);

        if ($identity->getId() === null) {
            $sql = sprintf(
                'INSERT INTO %s
                (batch_id, identity_type, source_id, source_system, target_entity_id, provider_type,
                 provider_identifier, external_reference_id, external_metadata_json,
                 remote_verification_passed, suppress_provisioning, adopted_at)
                VALUES
                (:b, :it, :sid, :sys, :tid, :pt, :pi, :er, :meta, :rv, :sp, :ad)',
                $this->table
            );

            $this->db->statement($sql, [
                'b' => $identity->getBatchId(),
                'it' => $identity->getIdentityType()->value,
                'sid' => $identity->getSourceId(),
                'sys' => $identity->getSourceSystem(),
                'tid' => $identity->getTargetEntityId(),
                'pt' => $identity->getProviderType(),
                'pi' => $identity->getProviderIdentifier(),
                'er' => $identity->getExternalReferenceId(),
                'meta' => $metaJson,
                'rv' => $identity->isRemoteVerificationPassed() ? 1 : 0,
                'sp' => $identity->isProvisioningSuppressed() ? 1 : 0,
                'ad' => $identity->getAdoptedAt() ?? date('Y-m-d H:i:s'),
            ]);

            $id = (int) $this->db->getPdo()->lastInsertId();
            $identity->setId($id);
        } else {
            $sql = sprintf(
                'UPDATE %s
                 SET external_metadata_json = :meta,
                     remote_verification_passed = :rv,
                     suppress_provisioning = :sp
                 WHERE id = :id',
                $this->table
            );

            $this->db->statement($sql, [
                'id' => $identity->getId(),
                'meta' => $metaJson,
                'rv' => $identity->isRemoteVerificationPassed() ? 1 : 0,
                'sp' => $identity->isProvisioningSuppressed() ? 1 : 0,
            ]);
        }
    }

    public function findByExternalReference(
        AdoptedIdentityType $type,
        string $providerIdentifier,
        string $referenceId
    ): ?AdoptedProviderIdentity {
        $this->ensureTable();

        $rows = $this->db->select(
            sprintf(
                'SELECT * FROM %s WHERE identity_type = :it AND provider_identifier = :pi AND external_reference_id = :er',
                $this->table
            ),
            ['it' => $type->value, 'pi' => $providerIdentifier, 'er' => $referenceId]
        );

        if (empty($rows)) {
            return null;
        }

        return $this->mapRowToIdentity($rows[0]);
    }

    public function findByTargetEntity(AdoptedIdentityType $type, int $targetEntityId): ?AdoptedProviderIdentity
    {
        $this->ensureTable();

        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE identity_type = :it AND target_entity_id = :tid', $this->table),
            ['it' => $type->value, 'tid' => $targetEntityId]
        );

        if (empty($rows)) {
            return null;
        }

        return $this->mapRowToIdentity($rows[0]);
    }

    /**
     * @return list<AdoptedProviderIdentity>
     */
    public function getBatchIdentities(string $batchId): array
    {
        $this->ensureTable();

        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE batch_id = :b ORDER BY id ASC', $this->table),
            ['b' => $batchId]
        );

        return array_map(fn (array $r) => $this->mapRowToIdentity($r), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToIdentity(array $row): AdoptedProviderIdentity
    {
        $meta = $row['external_metadata_json'] !== null
            ? json_decode((string) $row['external_metadata_json'], true)
            : [];

        return new AdoptedProviderIdentity(
            id: (int) $row['id'],
            batchId: (string) $row['batch_id'],
            identityType: AdoptedIdentityType::from((string) $row['identity_type']),
            sourceId: (string) $row['source_id'],
            sourceSystem: (string) $row['source_system'],
            targetEntityId: (int) $row['target_entity_id'],
            providerType: (string) $row['provider_type'],
            providerIdentifier: (string) $row['provider_identifier'],
            externalReferenceId: (string) $row['external_reference_id'],
            externalMetadata: is_array($meta) ? $meta : [],
            remoteVerificationPassed: (bool) $row['remote_verification_passed'],
            suppressProvisioning: (bool) $row['suppress_provisioning'],
            adoptedAt: (string) $row['adopted_at']
        );
    }
}
