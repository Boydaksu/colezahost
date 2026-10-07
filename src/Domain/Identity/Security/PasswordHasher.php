<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\Security;

use InvalidArgumentException;

final class PasswordHasher
{
    private string $algorithm;
    /** @var array<string, int> */
    private array $options;

    public function __construct(string $algorithm = PASSWORD_ARGON2ID, array $options = [])
    {
        // Fallback to BCRYPT if ARGON2ID is unavailable in runtime
        if ($algorithm === PASSWORD_ARGON2ID && !defined('PASSWORD_ARGON2ID')) {
            $this->algorithm = PASSWORD_BCRYPT;
            $this->options = $options ?: ['cost' => 12];
        } else {
            $this->algorithm = $algorithm;
            $this->options = $options ?: [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 1,
            ];
        }
    }

    /**
     * Hash a cleartext password.
     */
    public function hash(string $password): string
    {
        if (trim($password) === '') {
            throw new InvalidArgumentException('Password cannot be empty.');
        }

        $hash = password_hash($password, $this->algorithm, $this->options);
        if ($hash === false) {
            throw new InvalidArgumentException('Password hashing failed.');
        }

        return $hash;
    }

    /**
     * Verify a cleartext password against a hash.
     */
    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if a hash needs to be rehashed based on current options.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }
}
