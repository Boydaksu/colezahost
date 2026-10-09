<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Security;

use Coleza\Foundation\Exceptions\AuthorizationException;

final class AnalyticsDataScopeService
{
    /**
     * Enforce strict tenant barrier: user must belong to the requested organization,
     * unless the user has global platform SuperAdmin privileges.
     */
    public function enforceOrganizationAccess(int $targetOrganizationId, AnalyticsUserContext $user): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        if ($user->getOrganizationId() === null || $user->getOrganizationId() !== $targetOrganizationId) {
            throw new AuthorizationException(
                sprintf('Access denied: Unauthorized access to organization [%d] analytics data.', $targetOrganizationId),
                'TENANT_ACCESS_DENIED',
                [
                    'target_organization_id' => $targetOrganizationId,
                    'user_organization_id' => $user->getOrganizationId(),
                    'user_id' => $user->getUserId(),
                ]
            );
        }
    }

    /**
     * Generates SQL WHERE clause and bindings ensuring tenant isolation in analytical queries.
     *
     * @return array{sql: string, params: array<string, mixed>}
     */
    public function buildOrganizationScopeFilter(
        AnalyticsUserContext $user,
        string $orgColumn = 'organization_id'
    ): array {
        if ($user->isSuperAdmin()) {
            // Superadmin has global visibility by default
            return [
                'sql' => '1 = 1',
                'params' => [],
            ];
        }

        $orgId = $user->getOrganizationId();
        if ($orgId === null) {
            // Unassigned non-staff user: cannot see any organization analytics
            return [
                'sql' => '1 = 0',
                'params' => [],
            ];
        }

        return [
            'sql' => sprintf('%s = :scoped_org_id', $orgColumn),
            'params' => ['scoped_org_id' => $orgId],
        ];
    }

    /**
     * Redacts sensitive financial figures from analytical datasets if user lacks financial viewing permissions.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function redactSensitiveMetrics(array $data, AnalyticsUserContext $user): array
    {
        if ($user->isSuperAdmin() || $user->hasPermission('analytics.view_financial')) {
            return $data;
        }

        $redactedFields = [
            'total_mrr',
            'annual_run_rate',
            'net_cash_flow',
            'gross_invoiced',
            'net_invoiced',
            'tax_liability',
            'aged_receivables_total',
            'gateway_fees_total',
            'net_contribution_margin_pct',
            'new_order_volume',
            'cash_collected',
            'refunds_issued',
        ];

        $result = $data;
        foreach ($redactedFields as $field) {
            if (array_key_exists($field, $result)) {
                $result[$field] = '[REDACTED]';
            }
        }

        return $result;
    }
}
