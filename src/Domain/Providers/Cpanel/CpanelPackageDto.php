<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Cpanel;

final class CpanelPackageDto
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        private string $name,
        private int $quotaMb,
        private int $bandwidthMb,
        private ?int $maxFtp = null,
        private ?int $maxSql = null,
        private ?int $maxPop = null,
        private ?int $maxSub = null,
        private ?int $maxPark = null,
        private ?int $maxAddon = null,
        private bool $cgiAccess = true,
        private bool $shellAccess = false,
        private bool $dedicatedIp = false,
        private ?string $cpanelTheme = 'jupiter',
        private ?string $featureList = 'default',
        private array $raw = []
    ) {
    }

    /**
     * Parse raw WHM listpkgs item into typed CpanelPackageDto.
     *
     * @param array<string, mixed> $pkg
     */
    public static function fromWhmArray(array $pkg): self
    {
        $name = (string)($pkg['name'] ?? $pkg['NAME'] ?? '');

        $parseQuota = static function (mixed $val): int {
            if ($val === null || $val === '' || strtolower((string)$val) === 'unlimited') {
                return 0; // 0 denotes unlimited in Coleza
            }
            return max(0, (int)$val);
        };

        $parseIntLimit = static function (mixed $val): ?int {
            if ($val === null || $val === '' || strtolower((string)$val) === 'unlimited') {
                return null;
            }
            return (int)$val;
        };

        $parseBool = static function (mixed $val): bool {
            if (is_bool($val)) {
                return $val;
            }
            $lower = strtolower(trim((string)$val));
            return $lower === 'y' || $lower === 'yes' || $lower === '1' || $lower === 'true';
        };

        $quotaMb = $parseQuota($pkg['QUOTA'] ?? $pkg['quota'] ?? null);
        $bandwidthMb = $parseQuota($pkg['BWLIMIT'] ?? $pkg['bwlimit'] ?? null);

        $maxFtp = $parseIntLimit($pkg['MAXFTP'] ?? $pkg['maxftp'] ?? null);
        $maxSql = $parseIntLimit($pkg['MAXSQL'] ?? $pkg['maxsql'] ?? null);
        $maxPop = $parseIntLimit($pkg['MAXPOP'] ?? $pkg['maxpop'] ?? null);
        $maxSub = $parseIntLimit($pkg['MAXSUB'] ?? $pkg['maxsub'] ?? null);
        $maxPark = $parseIntLimit($pkg['MAXPARK'] ?? $pkg['maxpark'] ?? null);
        $maxAddon = $parseIntLimit($pkg['MAXADDON'] ?? $pkg['maxaddon'] ?? null);

        $cgi = $parseBool($pkg['HASCGI'] ?? $pkg['cgi'] ?? true);
        $shell = $parseBool($pkg['HASHELL'] ?? $pkg['shell'] ?? false);
        $ip = $parseBool($pkg['IP'] ?? $pkg['ip'] ?? false);

        $theme = isset($pkg['CPMOD']) ? (string)$pkg['CPMOD'] : (isset($pkg['cpmod']) ? (string)$pkg['cpmod'] : 'jupiter');
        $features = isset($pkg['FEATURELIST']) ? (string)$pkg['FEATURELIST'] : (isset($pkg['featurelist']) ? (string)$pkg['featurelist'] : 'default');

        return new self(
            name: $name,
            quotaMb: $quotaMb,
            bandwidthMb: $bandwidthMb,
            maxFtp: $maxFtp,
            maxSql: $maxSql,
            maxPop: $maxPop,
            maxSub: $maxSub,
            maxPark: $maxPark,
            maxAddon: $maxAddon,
            cgiAccess: $cgi,
            shellAccess: $shell,
            dedicatedIp: $ip,
            cpanelTheme: $theme,
            featureList: $features,
            raw: $pkg
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQuotaMb(): int
    {
        return $this->quotaMb;
    }

    public function isUnlimitedQuota(): bool
    {
        return $this->quotaMb === 0;
    }

    public function getBandwidthMb(): int
    {
        return $this->bandwidthMb;
    }

    public function isUnlimitedBandwidth(): bool
    {
        return $this->bandwidthMb === 0;
    }

    public function getMaxFtp(): ?int
    {
        return $this->maxFtp;
    }

    public function getMaxSql(): ?int
    {
        return $this->maxSql;
    }

    public function getMaxPop(): ?int
    {
        return $this->maxPop;
    }

    public function getMaxSub(): ?int
    {
        return $this->maxSub;
    }

    public function getMaxPark(): ?int
    {
        return $this->maxPark;
    }

    public function getMaxAddon(): ?int
    {
        return $this->maxAddon;
    }

    public function hasCgiAccess(): bool
    {
        return $this->cgiAccess;
    }

    public function hasShellAccess(): bool
    {
        return $this->shellAccess;
    }

    public function hasDedicatedIp(): bool
    {
        return $this->dedicatedIp;
    }

    public function getCpanelTheme(): ?string
    {
        return $this->cpanelTheme;
    }

    public function getFeatureList(): ?string
    {
        return $this->featureList;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'quota_mb' => $this->quotaMb,
            'is_unlimited_quota' => $this->isUnlimitedQuota(),
            'bandwidth_mb' => $this->bandwidthMb,
            'is_unlimited_bandwidth' => $this->isUnlimitedBandwidth(),
            'max_ftp' => $this->maxFtp,
            'max_sql' => $this->maxSql,
            'max_pop' => $this->maxPop,
            'max_sub' => $this->maxSub,
            'max_park' => $this->maxPark,
            'max_addon' => $this->maxAddon,
            'cgi_access' => $this->cgiAccess,
            'shell_access' => $this->shellAccess,
            'dedicated_ip' => $this->dedicatedIp,
            'cpanel_theme' => $this->cpanelTheme,
            'feature_list' => $this->featureList,
        ];
    }
}
