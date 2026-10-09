<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Context;

use DateTimeImmutable;
use DateTimeZone;

final class TimezoneAnalyticsContext
{
    private DateTimeZone $reportingTimezone;
    private DateTimeZone $utcTimezone;

    public function __construct(string $timezoneIdentifier = 'UTC')
    {
        $this->reportingTimezone = new DateTimeZone($timezoneIdentifier);
        $this->utcTimezone = new DateTimeZone('UTC');
    }

    public function getReportingTimezone(): DateTimeZone
    {
        return $this->reportingTimezone;
    }

    /**
     * Converts a local reporting calendar day (YYYY-MM-DD) into canonical UTC start and end bounds.
     *
     * @return array{start: string, end: string}
     */
    public function getUtcWindowForDate(string $date): array
    {
        $localStart = new DateTimeImmutable($date . ' 00:00:00', $this->reportingTimezone);
        $localEnd = new DateTimeImmutable($date . ' 23:59:59', $this->reportingTimezone);

        $utcStart = $localStart->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');
        $utcEnd = $localEnd->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');

        return [
            'start' => $utcStart,
            'end' => $utcEnd,
        ];
    }

    /**
     * Converts a local reporting date range into canonical UTC bounds.
     *
     * @return array{start: string, end: string}
     */
    public function getUtcWindowForRange(string $startDate, string $endDate): array
    {
        $localStart = new DateTimeImmutable($startDate . ' 00:00:00', $this->reportingTimezone);
        $localEnd = new DateTimeImmutable($endDate . ' 23:59:59', $this->reportingTimezone);

        $utcStart = $localStart->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');
        $utcEnd = $localEnd->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');

        return [
            'start' => $utcStart,
            'end' => $utcEnd,
        ];
    }

    /**
     * Formats a canonical UTC database timestamp in the reporting timezone.
     */
    public function formatInReportingTimezone(string $utcTimestamp, string $format = 'Y-m-d H:i:s'): string
    {
        $dt = new DateTimeImmutable($utcTimestamp, $this->utcTimezone);
        return $dt->setTimezone($this->reportingTimezone)->format($format);
    }
}
