<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\Impersonation;

use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Foundation\Exceptions\ValidationException;

final class ImpersonationService
{
    public const BLOCKED_HIGH_RISK_OPERATIONS = [
        'password.change',
        'two_factor.disable',
        'api_key.create',
        'payment_method.delete',
        'account.delete',
        'vault.read_secret',
    ];

    public function __construct(
        private DatabaseSessionHandler $sessionHandler,
        private RbacService $rbac,
        private AuditLogger $auditLogger
    ) {
    }

    /**
     * Start impersonating target user.
     * Requires 'users.impersonate' permission and admin status.
     * Prevents nesting impersonations.
     */
    public function startImpersonation(
        string $sessionId,
        int $adminUserId,
        int $targetUserId,
        ?string $reason = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        // 1. Authorize admin
        $this->rbac->authorize($adminUserId, 'users.impersonate');

        // 2. Prevent impersonating self
        if ($adminUserId === $targetUserId) {
            throw new ValidationException(['impersonation' => ['Cannot impersonate yourself.']]);
        }

        // 3. Read session and verify not already impersonating
        $session = $this->sessionHandler->read($sessionId);
        if (isset($session['impersonator_id'])) {
            throw new ValidationException(['impersonation' => ['Nested impersonation is forbidden.']]);
        }

        // 4. Update session to switch effective user while retaining real impersonator identity
        $session['original_user_id'] = $adminUserId;
        $session['impersonator_id'] = $adminUserId;
        $session['impersonation_started_at'] = time();
        $session['impersonation_reason'] = $reason;

        // Effective user becomes target user
        $this->sessionHandler->write($sessionId, $session, userId: $targetUserId, ipAddress: $ip, userAgent: $userAgent);

        // 5. Immutable security audit record
        $this->auditLogger->log(
            actorUserId: $targetUserId,
            eventType: 'IMPERSONATION_STARTED',
            targetResource: 'user:' . $targetUserId,
            impersonatorUserId: $adminUserId,
            ip: $ip,
            userAgent: $userAgent,
            payload: ['reason' => $reason]
        );
    }

    /**
     * Stop impersonation and restore original admin session.
     */
    public function stopImpersonation(string $sessionId, ?string $ip = null, ?string $userAgent = null): void
    {
        $session = $this->sessionHandler->read($sessionId);
        if (!isset($session['impersonator_id']) || !isset($session['original_user_id'])) {
            throw new ValidationException(['impersonation' => ['No active impersonation session found.']]);
        }

        $adminUserId = (int) $session['original_user_id'];
        $targetUserId = (int) ($session['user_id'] ?? 0);

        unset($session['impersonator_id'], $session['original_user_id'], $session['impersonation_started_at'], $session['impersonation_reason']);

        // Restore admin session
        $this->sessionHandler->write($sessionId, $session, userId: $adminUserId, ipAddress: $ip, userAgent: $userAgent);

        // Audit stop
        $this->auditLogger->log(
            actorUserId: $adminUserId,
            eventType: 'IMPERSONATION_STOPPED',
            targetResource: 'user:' . $targetUserId,
            impersonatorUserId: $adminUserId,
            ip: $ip,
            userAgent: $userAgent
        );
    }

    /**
     * Guard high-risk operation during impersonation.
     * In accordance with Security Constitution, high-risk security operations are strictly forbidden while impersonating.
     */
    public function guardOperation(string $sessionId, string $operation): void
    {
        $session = $this->sessionHandler->read($sessionId);
        if (isset($session['impersonator_id']) && in_array($operation, self::BLOCKED_HIGH_RISK_OPERATIONS, true)) {
            $adminUserId = (int) $session['impersonator_id'];
            $targetUserId = (int) ($session['user_id'] ?? 0);

            // Audit blocked security breach attempt
            $this->auditLogger->log(
                actorUserId: $targetUserId,
                eventType: 'IMPERSONATION_OPERATION_BLOCKED',
                targetResource: $operation,
                impersonatorUserId: $adminUserId,
                payload: ['attempted_operation' => $operation]
            );

            throw new ValidationException(
                ['security' => [sprintf('Operation [%s] is prohibited while impersonating a customer.', $operation)]],
                'High-risk action blocked during impersonation.'
            );
        }
    }
}
