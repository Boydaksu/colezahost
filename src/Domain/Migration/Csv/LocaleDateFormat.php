<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Csv;

enum LocaleDateFormat: string
{
    case AUTO_DETECT = 'auto';
    case DMY = 'dmy'; // Day/Month/Year (UK, Europe, TR, Australia: 15/10/2026 or 15.10.2026)
    case MDY = 'mdy'; // Month/Day/Year (US: 10/15/2026)
    case YMD = 'ymd'; // Year-Month-Day (ISO: 2026-10-15 or 2026/10/15)
}
