<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Validation;

use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Foundation\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    public function testPassesValidData(): void
    {
        $data = [
            'name' => 'Coleza Admin',
            'email' => 'admin@colezahost.com',
            'role' => 'administrator',
            'age' => 25,
            'is_active' => true,
        ];

        $rules = [
            'name' => 'required|string|min:3|max:50',
            'email' => 'required|email',
            'role' => 'required|in:administrator,client,support',
            'age' => 'required|numeric|min:18',
            'is_active' => 'required|boolean',
        ];

        $this->assertTrue($this->validator->passes($data, $rules));
        $this->assertEmpty($this->validator->validate($data, $rules));
    }

    public function testFailsInvalidData(): void
    {
        $data = [
            'name' => 'A',
            'email' => 'not-an-email',
            'role' => 'superman',
            'age' => 15,
        ];

        $rules = [
            'name' => 'required|min:3',
            'email' => 'required|email',
            'role' => 'required|in:admin,client',
            'age' => 'required|numeric|min:18',
        ];

        $errors = $this->validator->validate($data, $rules);

        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('role', $errors);
        $this->assertArrayHasKey('age', $errors);
    }

    public function testValidateOrThrowThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validateOrThrow(['email' => 'invalid'], ['email' => 'required|email']);
    }
}
