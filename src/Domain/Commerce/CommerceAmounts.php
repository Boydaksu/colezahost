<?php
declare(strict_types=1);
namespace Coleza\Domain\Commerce;
use Coleza\Foundation\Exceptions\ValidationException;
/** Values fit every existing signed INT money column; reject truncation and float promotion. */
final class CommerceAmounts
{
    public const MAX=2147483647;
    public const MAX_ORDER_UNITS=1000;
    public static function integer(mixed $value,string $field,int $min=0): int
    {
        if(is_string($value)&&preg_match('/^(0|[1-9][0-9]*)$/D',$value)&&strlen($value)<=10&&strcmp(str_pad($value,10,'0',STR_PAD_LEFT),(string)self::MAX)<=0){$value=(int)$value;}
        if(!is_int($value)||$value<$min||$value>self::MAX){throw new ValidationException([$field=>['A bounded whole nonnegative amount or positive quantity is required.']]);}return $value;
    }
    public static function add(int $a,int $b): int{self::integer($a,'amount');self::integer($b,'amount');if($a>self::MAX-$b){throw new ValidationException(['amount'=>['Amount exceeds storage range.']]);}return $a+$b;}
    public static function multiply(int $a,int $b): int{self::integer($a,'amount');self::integer($b,'quantity',1);if($a>intdiv(self::MAX,$b)){throw new ValidationException(['amount'=>['Amount exceeds storage range.']]);}return $a*$b;}
    /** Largest remainder, stable input order. Products of bounded operands fit PHP's signed 64-bit INT. */
    public static function allocate(int $total,array $weights): array
    {
        self::integer($total,'amount');$weights=array_values($weights);$sum=0;foreach($weights as$weight){$sum=self::add($sum,self::integer($weight,'weight'));}$result=array_fill(0,count($weights),0);if($sum===0){if($total!==0){throw new ValidationException(['amount'=>['Cannot allocate tax without a taxable base.']]);}return $result;}
        $remainders=[];$used=0;foreach($weights as$i=>$weight){self::integer($weight,'weight');$numerator=$total*$weight;$result[$i]=intdiv($numerator,$sum);$remainders[$i]=$numerator%$sum;$used+=$result[$i];}
        arsort($remainders,SORT_NUMERIC);foreach(array_keys($remainders)as$i){if($used===$total){break;}$result[$i]++;$used++;}return $result;
    }
}
