<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Data transfer object for the primary administrator account created during installation.
 */
final class AdminSetupDto implements JsonSerializable
{
    public function __construct(
        private string $email,
        private string $password,
        private string $firstName,
        private string $lastName
    ) {
        $this->email = strtolower(trim($this->email));
        $this->firstName = trim($this->firstName);
        $this->lastName = trim($this->lastName);

        $this->validate();
    }

    private function validate(): void
    {
        if ($this->email === '' || !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(sprintf('Invalid administrator email address [%s].', $this->email));
        }

        if (strlen($this->password) < 10) {
            throw new InvalidArgumentException('Administrator password must be at least 10 characters long.');
        }

        if (!preg_match('/[A-Z]/', $this->password) || !preg_match('/[a-z]/', $this->password) || !preg_match('/[0-9]/', $this->password)) {
            throw new InvalidArgumentException('Administrator password must contain uppercase, lowercase, and numeric characters.');
        }

        if ($this->firstName === '') {
            throw new InvalidArgumentException('Administrator first name cannot be empty.');
        }

        if ($this->lastName === '') {
            throw new InvalidArgumentException('Administrator last name cannot be empty.');
        }
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->getFullName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
