<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk\Rules;

use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskSignal;

interface RiskRuleInterface
{
    public function getRuleCode(): string;

    public function getRuleName(): string;

    public function getDefaultWeight(): int;

    public function evaluate(RiskContext $context): ?RiskSignal;
}
