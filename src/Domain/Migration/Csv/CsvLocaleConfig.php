<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Csv;

final class CsvLocaleConfig
{
    public function __construct(
        private ?string $delimiter = null, // null means auto-detect from first lines
        private string $enclosure = '"',
        private string $escape = '\\',
        private string $decimalSeparator = '.',
        private string $thousandsSeparator = ',',
        private LocaleDateFormat $dateFormat = LocaleDateFormat::AUTO_DETECT,
        private string $encoding = 'UTF-8'
    ) {
    }

    public static function standardUs(): self
    {
        return new self(
            delimiter: ',',
            enclosure: '"',
            escape: '\\',
            decimalSeparator: '.',
            thousandsSeparator: ',',
            dateFormat: LocaleDateFormat::MDY
        );
    }

    public static function europeanContinental(): self
    {
        return new self(
            delimiter: ';',
            enclosure: '"',
            escape: '\\',
            decimalSeparator: ',',
            thousandsSeparator: '.',
            dateFormat: LocaleDateFormat::DMY
        );
    }

    public static function autoDetect(): self
    {
        return new self(
            delimiter: null,
            enclosure: '"',
            escape: '\\',
            decimalSeparator: '.',
            thousandsSeparator: ',',
            dateFormat: LocaleDateFormat::AUTO_DETECT
        );
    }

    public function getDelimiter(): ?string
    {
        return $this->delimiter;
    }

    public function getEnclosure(): string
    {
        return $this->enclosure;
    }

    public function getEscape(): string
    {
        return $this->escape;
    }

    public function getDecimalSeparator(): string
    {
        return $this->decimalSeparator;
    }

    public function getThousandsSeparator(): string
    {
        return $this->thousandsSeparator;
    }

    public function getDateFormat(): LocaleDateFormat
    {
        return $this->dateFormat;
    }

    public function getEncoding(): string
    {
        return $this->encoding;
    }
}
