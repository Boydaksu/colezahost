<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Actions;

use Coleza\Domain\Automation\Triggers\TriggerContext;

final class ActionDefinition implements ActionInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private readonly string $id,
        private readonly string $type,
        private readonly array $parameters = []
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function resolveParameters(TriggerContext $context): array
    {
        return $this->interpolateArray($this->parameters, $context);
    }

    /**
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private function interpolateArray(array $array, TriggerContext $context): array
    {
        $resolved = [];
        foreach ($array as $key => $value) {
            if (is_string($value)) {
                $resolved[$key] = $this->interpolateString($value, $context);
            } elseif (is_array($value)) {
                $resolved[$key] = $this->interpolateArray($value, $context);
            } else {
                $resolved[$key] = $value;
            }
        }
        return $resolved;
    }

    private function interpolateString(string $template, TriggerContext $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($matches) use ($context) {
            $key = $matches[1];
            $val = $context->get($key);
            if ($val === null) {
                return '';
            }
            if (is_scalar($val) || $val instanceof \Stringable) {
                return (string) $val;
            }
            return json_encode($val);
        }, $template);
    }
}
