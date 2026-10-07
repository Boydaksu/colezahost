<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Exceptions;

use Coleza\Foundation\Exceptions\AppException;
use Coleza\Foundation\Exceptions\AuthenticationException;
use Coleza\Foundation\Exceptions\AuthorizationException;
use Coleza\Foundation\Exceptions\ConflictException;
use Coleza\Foundation\Exceptions\ResourceNotFoundException;
use Coleza\Foundation\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class AppExceptionTest extends TestCase
{
    public function testBaseAppExceptionStructure(): void
    {
        $ex = new AppException('Custom error', 'CUSTOM_ERR_CODE', 400, ['field' => 'val']);

        $this->assertSame('Custom error', $ex->getMessage());
        $this->assertSame('CUSTOM_ERR_CODE', $ex->getErrorCode());
        $this->assertSame(400, $ex->getHttpStatusCode());
        $this->assertSame(['field' => 'val'], $ex->getContext());

        $safePayload = $ex->toResponseArray(false);
        $this->assertSame(['error' => 'Custom error', 'code' => 'CUSTOM_ERR_CODE'], $safePayload);

        $detailedPayload = $ex->toResponseArray(true);
        $this->assertSame([
            'error' => 'Custom error',
            'code' => 'CUSTOM_ERR_CODE',
            'details' => ['field' => 'val'],
        ], $detailedPayload);
    }

    public function testStandardDerivedExceptions(): void
    {
        $auth = new AuthenticationException();
        $this->assertSame(401, $auth->getHttpStatusCode());
        $this->assertSame('UNAUTHENTICATED', $auth->getErrorCode());

        $forbidden = new AuthorizationException();
        $this->assertSame(403, $forbidden->getHttpStatusCode());
        $this->assertSame('PERMISSION_DENIED', $forbidden->getErrorCode());

        $notFound = new ResourceNotFoundException('Order not found');
        $this->assertSame(404, $notFound->getHttpStatusCode());
        $this->assertSame('RESOURCE_NOT_FOUND', $notFound->getErrorCode());

        $conflict = new ConflictException('Invoice already paid');
        $this->assertSame(409, $conflict->getHttpStatusCode());
        $this->assertSame('STATE_CONFLICT', $conflict->getErrorCode());
    }

    public function testValidationExceptionFormatting(): void
    {
        $valEx = new ValidationException(['email' => ['Invalid email']]);

        $this->assertSame(422, $valEx->getHttpStatusCode());
        $this->assertSame('VALIDATION_FAILED', $valEx->getErrorCode());
        $this->assertSame(['email' => ['Invalid email']], $valEx->getErrors());

        $resp = $valEx->toResponseArray();
        $this->assertSame('VALIDATION_FAILED', $resp['code']);
        $this->assertArrayHasKey('errors', $resp);
    }
}
