<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Data transfer object for regional locale, timezone, and currency defaults.
 */
final class LocaleSetupDto implements JsonSerializable
{
    private const ALLOWED_LOCALES = ['en', 'tr'];
    private const ALLOWED_CURRENCIES = ['USD', 'EUR', 'TRY', 'GBP'];

    public function __construct(
        private string $defaultLocale = 'en',
        private string $timezone = 'UTC',
        private string $defaultCurrency = 'USD',
        private string $dateFormat = 'Y-m-d'
    ) {
        $this->defaultLocale = strtolower(trim($this->defaultLocale));
        $this->timezone = trim($this->timezone);
        $this->defaultCurrency = strtoupper(trim($this->defaultCurrency));

        $this->validate();
    }

    private function validate(): void
    {
        if (!in_array($this->defaultLocale, self::ALLOWED_LOCALES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid locale [%s]. Allowed locales: %s.',
                $this->defaultLocale,
                implode(', ', self::ALLOWED_LOCALES)
            ));
        }

        if (!in_array($this->timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid timezone identifier [%s].', $this->timezone));
        }

        if (!in_array($this->defaultCurrency, self::ALLOWED_CURRENCIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid currency [%s]. Allowed currencies: %s.',
                $this->defaultCurrency,
                implode(', ', self::ALLOWED_CURRENCIES)
            ));
        }
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getDefaultCurrency(): string
    {
        return $this->defaultCurrency;
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'default_locale' => $this->defaultLocale,
            'timezone' => $this->timezone,
            'default_currency' => $this->defaultCurrency,
            'date_format' => $this->dateFormat,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
