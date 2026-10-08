<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Settings;

final class ProviderSettingSchema
{
    /** @var array<string, ProviderSettingDefinition> */
    private array $definitions = [];

    /**
     * @param array<ProviderSettingDefinition> $definitions
     */
    public function __construct(
        private string $providerSlug,
        array $definitions = []
    ) {
        foreach ($definitions as $def) {
            $this->add($def);
        }
    }

    public function getProviderSlug(): string
    {
        return $this->providerSlug;
    }

    public function add(ProviderSettingDefinition $definition): self
    {
        $this->definitions[$definition->getKey()] = $definition;
        return $this;
    }

    public function get(string $key): ?ProviderSettingDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function isSecret(string $key): bool
    {
        return isset($this->definitions[$key]) && $this->definitions[$key]->isSecret();
    }

    /**
     * @return array<string, ProviderSettingDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        $defaults = [];
        foreach ($this->definitions as $key => $def) {
            if ($def->getDefaultValue() !== null) {
                $defaults[$key] = $def->getDefaultValue();
            }
        }
        return $defaults;
    }

    /**
     * Standard cPanel provider module schema.
     */
    public static function forCpanel(): self
    {
        return (new self('cpanel'))
            ->add(new ProviderSettingDefinition(
                key: 'api_token',
                label: 'WHM API Token',
                type: ProviderSettingDefinition::TYPE_SECRET,
                isRequired: true,
                description: 'API Token generated in WHM Manage API Tokens'
            ))
            ->add(new ProviderSettingDefinition(
                key: 'username',
                label: 'WHM Username',
                type: ProviderSettingDefinition::TYPE_STRING,
                isRequired: true,
                defaultValue: 'root'
            ))
            ->add(new ProviderSettingDefinition(
                key: 'port',
                label: 'Port',
                type: ProviderSettingDefinition::TYPE_INT,
                isRequired: false,
                defaultValue: 2087
            ))
            ->add(new ProviderSettingDefinition(
                key: 'use_ssl',
                label: 'Use SSL',
                type: ProviderSettingDefinition::TYPE_BOOL,
                defaultValue: true
            ))
            ->add(new ProviderSettingDefinition(
                key: 'timeout_seconds',
                label: 'API Timeout (Seconds)',
                type: ProviderSettingDefinition::TYPE_INT,
                defaultValue: 30
            ));
    }

    /**
     * Standard DirectAdmin provider module schema.
     */
    public static function forDirectAdmin(): self
    {
        return (new self('directadmin'))
            ->add(new ProviderSettingDefinition(
                key: 'login_key',
                label: 'DirectAdmin Login Key / Password',
                type: ProviderSettingDefinition::TYPE_SECRET,
                isRequired: true
            ))
            ->add(new ProviderSettingDefinition(
                key: 'username',
                label: 'Admin Username',
                type: ProviderSettingDefinition::TYPE_STRING,
                isRequired: true,
                defaultValue: 'admin'
            ))
            ->add(new ProviderSettingDefinition(
                key: 'port',
                label: 'Port',
                type: ProviderSettingDefinition::TYPE_INT,
                defaultValue: 2222
            ))
            ->add(new ProviderSettingDefinition(
                key: 'use_ssl',
                label: 'Use SSL',
                type: ProviderSettingDefinition::TYPE_BOOL,
                defaultValue: true
            ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $defs = [];
        foreach ($this->definitions as $key => $def) {
            $defs[$key] = $def->toArray();
        }

        return [
            'provider_slug' => $this->providerSlug,
            'definitions' => $defs,
        ];
    }
}
