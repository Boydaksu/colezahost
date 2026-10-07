<?php

declare(strict_types=1);

return [
    'name' => 'Coleza Host',
    'env' => getenv('APP_ENV') ?: 'production',
    'debug' => in_array(strtolower(getenv('APP_DEBUG') ?: 'false'), ['true', '1', 'yes'], true),
    'url' => getenv('APP_URL') ?: 'http://localhost',
    'timezone' => 'UTC',
    'locale' => 'tr_TR',
    'fallback_locale' => 'en_US',
    'supported_locales' => ['tr_TR', 'en_US'],
];
