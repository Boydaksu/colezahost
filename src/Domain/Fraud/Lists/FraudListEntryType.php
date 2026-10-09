<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Lists;

enum FraudListEntryType: string
{
    case IP = 'ip';
    case EMAIL = 'email';
    case COUNTRY = 'country';
    case USER_ID = 'user_id';
    case FINGERPRINT = 'fingerprint';
    case CARD_BIN = 'card_bin';
}
