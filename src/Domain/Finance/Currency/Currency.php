<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Currency;

final class Currency
{
    public function __construct(
        private string $code, // ISO 4217 code (e.g. TRY, USD, EUR, GBP)
        private string $name,
        private string $symbol,
        private string $format = '{symbol}{amount}', // or '{amount} {symbol}'
        private int $decimalPlaces = 2,
        private bool $isDefault = false,
        private bool $isActive = true,
        private ?string $createdAt = null
    ) {
    }

    public function getCode(): string
    {
        return strtoupper($this->code);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getDecimalPlaces(): int
    {
        return $this->decimalPlaces;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function formatAmount(int $amountInMinorUnits): string
    {
        $divisor = 10 ** $this->decimalPlaces;
        $formattedNumber = number_format(
            $amountInMinorUnits / $divisor,
            $this->decimalPlaces,
            '.',
            ','
        );

        return str_replace(
            ['{symbol}', '{amount}'],
            [$this->symbol, $formattedNumber],
            $this->format
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->getCode(),
            'name' => $this->name,
            'symbol' => $this->symbol,
            'format' => $this->format,
            'decimal_places' => $this->decimalPlaces,
            'is_default' => $this->isDefault,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
        ];
    }
}
