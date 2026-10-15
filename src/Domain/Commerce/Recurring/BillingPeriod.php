<?php
declare(strict_types=1);
namespace Coleza\Domain\Commerce\Recurring;

use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class BillingPeriod
{
    public static function date(string $date): DateTimeImmutable
    {
        $value=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if($value===false||$value->format('Y-m-d')!==$date){throw new ValidationException(['date'=>['A valid YYYY-MM-DD date is required.']]);}
        return $value;
    }

    public static function calculateNextDueDate(string $baseDate,string $cycle): string
    {
        $date=self::date($baseDate);
        if(!PriceCycle::isValid($cycle)){throw new ValidationException(['cycle'=>['Invalid billing cycle.']]);}
        $months=PriceCycle::getMonths($cycle);
        if($months<=0){return $baseDate;}
        $target=$date->modify('first day of this month')->modify("+{$months} months");
        $day=min((int)$date->format('d'),(int)$target->format('t'));
        return $target->setDate((int)$target->format('Y'),(int)$target->format('m'),$day)->format('Y-m-d');
    }

    public static function computePeriod(string $startDate,string $cycle): array
    {
        self::date($startDate);
        return ['start'=>$startDate,'end'=>self::calculateNextDueDate($startDate,$cycle)];
    }

    public static function isDueForRenewal(string $nextDueDate,string $referenceDate,int $leadDays=14): bool
    {
        if($leadDays<0){throw new ValidationException(['lead_days'=>['Lead days cannot be negative.']]);}
        return self::date($referenceDate)>=self::date($nextDueDate)->modify("-{$leadDays} days");
    }
}
