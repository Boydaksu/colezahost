<?php
declare(strict_types=1);
namespace Coleza\Domain\Identity\Session;
use Coleza\Foundation\Database\Connection;
interface AuthenticatedSessionVerifier
{
    public function usesConnection(Connection $db): bool;
    public function prepare(): void;
    public function sessionUser(string $sessionId): array;
    public function consumeStepUp(string $sessionId,string $operation,string $token): void;
}
