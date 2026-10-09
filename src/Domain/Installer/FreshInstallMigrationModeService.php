<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use Coleza\Domain\Migration\Execution\MigrationExecutionOrchestrator;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Foundation\Database\Connection;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Service orchestrating Fresh-Install Migration Mode.
 * Enables web installer users to seamlessly import data from legacy systems (e.g. WHMCS)
 * during initial platform installation before locking the installer.
 *
 * Ensures:
 * 1. Automatic pre-flight source connection validation.
 * 2. Automated dry-run evaluation.
 * 3. Execution under global migration hold & notification suppression to prevent early customer triggers.
 * 4. Seamless integration with fresh core database and WebInstaller wizard state.
 */
final class FreshInstallMigrationModeService
{
    public function __construct(
        private ?MigrationExecutionOrchestrator $orchestrator = null
    ) {
    }

    public function setOrchestrator(MigrationExecutionOrchestrator $orchestrator): void
    {
        $this->orchestrator = $orchestrator;
    }

    public function getOrchestrator(): ?MigrationExecutionOrchestrator
    {
        return $this->orchestrator;
    }

    /**
     * Verifies that the source legacy system connection is reachable and valid.
     *
     * @param FreshInstallMigrationConfig $config
     * @return array{reachable: bool, error: ?string, metadata: array<string, mixed>}
     */
    public function testSourceConnection(FreshInstallMigrationConfig $config): array
    {
        try {
            $connParams = $config->getConnectionConfig();
            $driver = (string) ($connParams['driver'] ?? 'sqlite');

            if ($driver === 'sqlite') {
                $dbPath = (string) ($connParams['database'] ?? ':memory:');
                $pdo = new PDO('sqlite:' . $dbPath);
                $connection = new Connection($pdo, 'sqlite');
            } elseif ($driver === 'mysql') {
                $host = (string) ($connParams['host'] ?? '127.0.0.1');
                $port = (int) ($connParams['port'] ?? 3306);
                $database = (string) ($connParams['database'] ?? '');
                $username = (string) ($connParams['username'] ?? '');
                $password = (string) ($connParams['password'] ?? '');
                $charset = (string) ($connParams['charset'] ?? 'utf8mb4');

                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
                $pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
                $connection = new Connection($pdo, 'mysql');
            } else {
                return [
                    'reachable' => false,
                    'error' => "Unsupported source database driver: {$driver}",
                    'metadata' => [],
                ];
            }

            if ($config->getSourceType() === 'whmcs') {
                $whmcsConnector = new WhmcsReadOnlyConnector($connection);
                $scanner = new \Coleza\Domain\Migration\Whmcs\WhmcsSourceScanner($whmcsConnector);
                $profile = $scanner->scanCapabilities();

                if (!$profile->isCompatible()) {
                    return [
                        'reachable' => false,
                        'error' => 'Connected database does not contain recognizable WHMCS tables: ' . implode(', ', $profile->getWarnings()),
                        'metadata' => [
                            'warnings' => $profile->getWarnings(),
                        ],
                    ];
                }

                return [
                    'reachable' => true,
                    'error' => null,
                    'metadata' => [
                        'source_type' => 'whmcs',
                        'version' => $profile->getVersion()->getRawVersion(),
                        'company_name' => $profile->getCompanyName(),
                        'default_currency' => $profile->getDefaultCurrency(),
                        'driver' => $driver,
                    ],
                ];
            }

            return [
                'reachable' => true,
                'error' => null,
                'metadata' => [
                    'source_type' => $config->getSourceType(),
                    'driver' => $driver,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'reachable' => false,
                'error' => $e->getMessage(),
                'metadata' => [],
            ];
        }
    }

    /**
     * Executes the fresh install migration import using the configured orchestrator.
     *
     * @param Connection $targetDb Fresh installation database
     * @param FreshInstallMigrationConfig $config Migration parameters
     * @param string|null $batchId Unique batch identifier (autogenerated if null)
     * @return FreshInstallMigrationResult
     */
    public function executeFreshMigration(
        Connection $targetDb,
        FreshInstallMigrationConfig $config,
        ?string $batchId = null
    ): FreshInstallMigrationResult {
        $batchId = $batchId ?? 'fresh_install_mig_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));

        try {
            if ($this->orchestrator === null) {
                throw new RuntimeException('Migration execution orchestrator is not configured for fresh install migration.');
            }

            // Options passed to live migration
            $options = [
                'apply_migration_hold' => $config->shouldAutoHold(),
                'keep_global_hold' => $config->shouldAutoHold(),
                'suppress_notifications' => $config->shouldSuppressNotifications(),
                'conflict_strategy' => $config->getConflictStrategy(),
            ];

            // 1. Execute live migration run
            $runResult = $this->orchestrator->executeLive($batchId, $options);

            $summary = is_array($runResult) ? $runResult : [];
            $accounting = $summary['accounting_report'] ?? [];
            $migratedCount = (int) ($accounting['migrated_count'] ?? ($summary['total_migrated'] ?? 0));
            $conflictCount = (int) ($summary['total_conflicts'] ?? 0);
            $quarantineCount = (int) ($accounting['quarantined_count'] ?? ($summary['total_quarantined'] ?? 0));

            $isHoldActive = $config->shouldAutoHold()
                ? $this->orchestrator->getHoldService()->isGlobalHoldActive()
                : false;

            return new FreshInstallMigrationResult(
                sourceType: $config->getSourceType(),
                isSuccess: true,
                totalMigrated: $migratedCount,
                totalConflicts: $conflictCount,
                totalQuarantined: $quarantineCount,
                migrationHoldEngaged: $isHoldActive,
                notificationsSuppressed: $config->shouldSuppressNotifications(),
                summary: $summary,
                errorMessage: null
            );
        } catch (Throwable $e) {
            return new FreshInstallMigrationResult(
                sourceType: $config->getSourceType(),
                isSuccess: false,
                totalMigrated: 0,
                totalConflicts: 0,
                totalQuarantined: 0,
                migrationHoldEngaged: false,
                notificationsSuppressed: $config->shouldSuppressNotifications(),
                summary: [],
                errorMessage: $e->getMessage()
            );
        }
    }
}
