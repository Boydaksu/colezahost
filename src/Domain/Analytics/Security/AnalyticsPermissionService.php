<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Security;

use Coleza\Domain\Analytics\Dashboards\DashboardType;
use Coleza\Domain\Analytics\Metrics\MetricCategory;
use Coleza\Domain\Analytics\Metrics\MetricRegistry;
use Coleza\Foundation\Exceptions\AuthorizationException;

final class AnalyticsPermissionService
{
    public function __construct(
        private ?MetricRegistry $metricRegistry = null
    ) {
    }

    public function canViewDashboard(DashboardType $type, AnalyticsUserContext $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($type) {
            DashboardType::OWNER => $user->hasPermission('analytics.view_executive')
                || $user->hasPermission('analytics.view_owner'),
            DashboardType::FINANCE => $user->hasPermission('analytics.view_financial'),
            DashboardType::SALES => $user->hasPermission('analytics.view_sales'),
            DashboardType::OPERATIONS => $user->hasPermission('analytics.view_operations'),
            DashboardType::SUPPORT => $user->hasPermission('analytics.view_support'),
        };
    }

    public function enforceDashboardAccess(DashboardType $type, AnalyticsUserContext $user): void
    {
        if (!$this->canViewDashboard($type, $user)) {
            throw new AuthorizationException(
                sprintf('Access denied: User does not have permission to view %s dashboard.', $type->value),
                'PERMISSION_DENIED',
                ['dashboard' => $type->value, 'user_id' => $user->getUserId()]
            );
        }
    }

    public function canRebuildAnalytics(AnalyticsUserContext $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('analytics.rebuild');
    }

    public function enforceRebuildAccess(AnalyticsUserContext $user): void
    {
        if (!$this->canRebuildAnalytics($user)) {
            throw new AuthorizationException(
                'Access denied: User does not have permission to trigger analytics rebuild.',
                'PERMISSION_DENIED',
                ['user_id' => $user->getUserId()]
            );
        }
    }

    public function canExportAnalytics(AnalyticsUserContext $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('analytics.export');
    }

    public function enforceExportAccess(AnalyticsUserContext $user): void
    {
        if (!$this->canExportAnalytics($user)) {
            throw new AuthorizationException(
                'Access denied: User does not have permission to export analytics data.',
                'PERMISSION_DENIED',
                ['user_id' => $user->getUserId()]
            );
        }
    }

    public function canAccessMetric(string $metricKey, AnalyticsUserContext $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($this->metricRegistry !== null && $this->metricRegistry->has($metricKey)) {
            $def = $this->metricRegistry->get($metricKey);
            return match ($def->getCategory()) {
                MetricCategory::INVOICE,
                MetricCategory::CASH,
                MetricCategory::REVENUE => $user->hasPermission('analytics.view_financial'),
                MetricCategory::SUBSCRIPTION => $user->hasPermission('analytics.view_financial')
                    || $user->hasPermission('analytics.view_executive'),
                MetricCategory::OPERATIONS => $user->hasPermission('analytics.view_operations'),
                MetricCategory::SUPPORT => $user->hasPermission('analytics.view_support'),
            };
        }

        // Generic fallback when metric registry is not injected
        if (str_starts_with($metricKey, 'financial.') || str_starts_with($metricKey, 'revenue.')) {
            return $user->hasPermission('analytics.view_financial');
        }

        if (str_starts_with($metricKey, 'subscription.') || str_starts_with($metricKey, 'mrr.')) {
            return $user->hasPermission('analytics.view_financial') || $user->hasPermission('analytics.view_executive');
        }

        if (str_starts_with($metricKey, 'operations.') || str_starts_with($metricKey, 'infra.')) {
            return $user->hasPermission('analytics.view_operations');
        }

        if (str_starts_with($metricKey, 'support.') || str_starts_with($metricKey, 'tickets.')) {
            return $user->hasPermission('analytics.view_support');
        }

        if (str_starts_with($metricKey, 'sales.') || str_starts_with($metricKey, 'orders.')) {
            return $user->hasPermission('analytics.view_sales');
        }

        return false;
    }

    public function enforceMetricAccess(string $metricKey, AnalyticsUserContext $user): void
    {
        if (!$this->canAccessMetric($metricKey, $user)) {
            throw new AuthorizationException(
                sprintf('Access denied: User does not have permission to access metric [%s].', $metricKey),
                'PERMISSION_DENIED',
                ['metric_key' => $metricKey, 'user_id' => $user->getUserId()]
            );
        }
    }
}
