<?php

declare(strict_types=1);

namespace Coleza\Domain\Module;

use Coleza\Foundation\Exceptions\ValidationException;

final class ModuleManifest
{
    public const VALID_TYPES = ['payment_gateway', 'server_provisioner', 'domain_registrar', 'notification_channel', 'integration'];

    /**
     * @param array<int, string> $capabilities
     * @param array<int, string> $permissions
     * @param array<string, string> $dependencies
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $type,
        public string $minCoreVersion,
        public array $capabilities = [],
        public array $permissions = [],
        public array $dependencies = [],
        public ?string $description = null,
        public ?string $author = null
    ) {
    }

    /**
     * Validate and create manifest from raw array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        $id = trim((string) ($data['id'] ?? ''));
        if (!preg_match('/^[a-z0-9_\-]+$/', $id)) {
            $errors['id'][] = 'Module ID must contain only lowercase alphanumeric characters, dashes, and underscores.';
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'][] = 'Module name is required.';
        }

        $version = trim((string) ($data['version'] ?? ''));
        if ($version === '') {
            $errors['version'][] = 'Module version is required.';
        }

        $type = trim((string) ($data['type'] ?? ''));
        if (!in_array($type, self::VALID_TYPES, true)) {
            $errors['type'][] = sprintf('Module type must be one of: %s.', implode(', ', self::VALID_TYPES));
        }

        $minCoreVersion = trim((string) ($data['min_core_version'] ?? '1.0.0'));

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Invalid module manifest.');
        }

        return new self(
            id: $id,
            name: $name,
            version: $version,
            type: $type,
            minCoreVersion: $minCoreVersion,
            capabilities: is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [],
            permissions: is_array($data['permissions'] ?? null) ? $data['permissions'] : [],
            dependencies: is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            description: isset($data['description']) ? (string) $data['description'] : null,
            author: isset($data['author']) ? (string) $data['author'] : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'type' => $this->type,
            'min_core_version' => $this->minCoreVersion,
            'capabilities' => $this->capabilities,
            'permissions' => $this->permissions,
            'dependencies' => $this->dependencies,
            'description' => $this->description,
            'author' => $this->author,
        ];
    }
}
