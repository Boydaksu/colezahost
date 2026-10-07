<?php

declare(strict_types=1);

namespace Coleza\Foundation\Validation;

use Coleza\Foundation\Exceptions\ValidationException;

final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $rules
     * @return array<string, array<int, string>> Map of field name to array of error messages
     */
    public function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleSet) {
            $ruleList = is_string($ruleSet) ? explode('|', $ruleSet) : $ruleSet;
            $value = $data[$field] ?? null;

            foreach ($ruleList as $rule) {
                $rule = trim($rule);
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $errorMessage = $this->checkRule($field, $value, $rule, $params);
                if ($errorMessage !== null) {
                    $errors[$field][] = $errorMessage;
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $rules
     * @throws ValidationException
     */
    public function validateOrThrow(array $data, array $rules): void
    {
        $errors = $this->validate($data, $rules);
        if (count($errors) > 0) {
            throw new ValidationException($errors);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $rules
     */
    public function passes(array $data, array $rules): bool
    {
        return count($this->validate($data, $rules)) === 0;
    }

    /**
     * @param array<int, string> $params
     */
    private function checkRule(string $field, mixed $value, string $rule, array $params): ?string
    {
        // Handle "required" rule
        if ($rule === 'required') {
            if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
                return sprintf('The %s field is required.', $field);
            }
            return null;
        }

        // For other rules, if value is null or empty, skip validation (unless required)
        if ($value === null || $value === '') {
            return null;
        }

        return match ($rule) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? sprintf('The %s must be a valid email address.', $field)
                : null,

            'string' => !is_string($value)
                ? sprintf('The %s must be a string.', $field)
                : null,

            'numeric' => !is_numeric($value)
                ? sprintf('The %s must be a number.', $field)
                : null,

            'integer' => filter_var($value, FILTER_VALIDATE_INT) === false
                ? sprintf('The %s must be an integer.', $field)
                : null,

            'boolean' => !in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)
                ? sprintf('The %s field must be true or false.', $field)
                : null,

            'min' => $this->checkMin($field, $value, (float) ($params[0] ?? 0)),

            'max' => $this->checkMax($field, $value, (float) ($params[0] ?? 0)),

            'in' => !in_array((string) $value, $params, true)
                ? sprintf('The selected %s is invalid.', $field)
                : null,

            default => null,
        };
    }

    private function checkMin(string $field, mixed $value, float $min): ?string
    {
        if (is_numeric($value)) {
            if ((float) $value < $min) {
                return sprintf('The %s must be at least %s.', $field, $min);
            }
        } elseif (is_string($value)) {
            if (mb_strlen($value) < $min) {
                return sprintf('The %s must be at least %d characters.', $field, (int) $min);
            }
        }
        return null;
    }

    private function checkMax(string $field, mixed $value, float $max): ?string
    {
        if (is_numeric($value)) {
            if ((float) $value > $max) {
                return sprintf('The %s must not be greater than %s.', $field, $max);
            }
        } elseif (is_string($value)) {
            if (mb_strlen($value) > $max) {
                return sprintf('The %s must not exceed %d characters.', $field, (int) $max);
            }
        }
        return null;
    }
}
