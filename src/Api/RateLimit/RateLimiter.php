<?php
declare(strict_types=1);
namespace Coleza\Api\RateLimit;
/** API compatibility facade over the shared durable admission budget. */
final class RateLimiter extends \Coleza\Foundation\Security\RequestBudget {}
