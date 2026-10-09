<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs;

use JsonSerializable;

final class WhmcsVersionInfo implements JsonSerializable
{
    public function __construct(
        private string $rawVersion,
        private int $major,
        private int $minor,
        private int $patch = 0,
        private ?string $build = null
    ) {
    }

    public static function fromRawVersion(string $rawVersion): self
    {
        $clean = trim($rawVersion);
        // Strips any release candidates or git tags, e.g. "8.8.0-release.1" -> "8.8.0"
        $parts = explode('-', $clean)[0];
        $segments = explode('.', $parts);

        $major = (int) ($segments[0] ?? 0);
        $minor = (int) ($segments[1] ?? 0);
        $patch = (int) ($segments[2] ?? 0);

        return new self(
            rawVersion: $clean,
            major: $major,
            minor: $minor,
            patch: $patch,
            build: str_contains($clean, '-') ? explode('-', $clean, 2)[1] : null
        );
    }

    public function getRawVersion(): string
    {
        return $this->rawVersion;
    }

    public function getMajor(): int
    {
        return $this->major;
    }

    public function getMinor(): int
    {
        return $this->minor;
    }

    public function getPatch(): int
    {
        return $this->patch;
    }

    public function getBuild(): ?string
    {
        return $this->build;
    }

    public function isV8OrHigher(): bool
    {
        return $this->major >= 8;
    }

    public function isV7(): bool
    {
        return $this->major === 7;
    }

    public function supportsUserAccountsArchitecture(): bool
    {
        // WHMCS introduced tblusers and tblusers_clients in v8.0.0
        return $this->major >= 8;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'raw_version' => $this->rawVersion,
            'major' => $this->major,
            'minor' => $this->minor,
            'patch' => $this->patch,
            'build' => $this->build,
            'is_v8_or_higher' => $this->isV8OrHigher(),
            'supports_user_accounts' => $this->supportsUserAccountsArchitecture(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
