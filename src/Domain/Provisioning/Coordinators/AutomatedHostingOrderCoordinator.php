<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Coordinators;

use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Notifications\Channel\NotificationChannel;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningRequest;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningResult;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningWorkflow;

final class AutomatedHostingOrderCoordinator
{
    public function __construct(
        private ServiceService $serviceService,
        private HostingProvisioningWorkflow $workflow,
        private NotificationEngine $notificationEngine,
        private ?OrderService $orderService = null
    ) {
    }

    /**
     * Coordinate full automated provisioning and customer notification for a paid order.
     *
     * @param Order $order
     * @param string $customerEmail
     * @param string $customerName
     * @param string $customerLocale
     * @return array{
     *     order_id: int,
     *     services_created: int,
     *     provisioned_services: array<array{service_id: int, result: HostingProvisioningResult, notification_sent: bool}>,
     *     all_successful: bool
     * }
     */
    public function processPaidOrder(
        Order $order,
        string $customerEmail,
        string $customerName = 'Valued Customer',
        string $customerLocale = 'en'
    ): array {
        // 1. Create services from order items
        $services = $this->serviceService->createServicesFromOrder($order);
        $provisionedResults = [];
        $allSuccess = true;

        foreach ($services as $service) {
            $metadata = $service->getMetadata();
            $domain = $service->getDomain() ?? ($metadata['domain'] ?? null);
            $package = (string)($metadata['package_identifier'] ?? ($metadata['package'] ?? 'starter'));
            $diskLimitMb = (int)($metadata['disk_limit_mb'] ?? 5120);
            $bwLimitMb = (int)($metadata['bandwidth_limit_mb'] ?? 51200);

            $req = new HostingProvisioningRequest(
                serviceId: (int)$service->getId(),
                domain: $domain,
                username: $service->getUsername() ?? ($metadata['username'] ?? null),
                packageIdentifier: $package,
                requiredDiskMb: $diskLimitMb,
                requiredBandwidthMb: $bwLimitMb,
                contactEmail: $customerEmail,
                metadata: $metadata,
                correlationId: 'ORD-' . $order->getOrderNumber()
            );

            // 2. Execute automated 6-step provisioning workflow
            $res = $this->workflow->execute($req);

            $notificationSent = false;
            if ($res->isSuccess()) {
                // 3. Dispatch welcome email with hosting credentials and server details
                $delivery = $this->notificationEngine->sendNotification(
                    templateKey: 'hosting_account_welcome',
                    data: [
                        'customer_name' => $customerName,
                        'domain' => $domain ?? 'yourdomain.com',
                        'package' => $package,
                        'username' => $res->getRemoteIdentifier() ?? '',
                        'server_name' => $res->getData()['server_name'] ?? 'Production Node',
                        'server_ip' => $res->getRemoteIp() ?? '',
                        'nameservers' => implode(', ', $res->getNameservers()),
                        'action_url' => 'https://' . ($res->getData()['server_hostname'] ?? 'node.colezahost.com') . ':2083',
                        'action_text' => 'Log in to cPanel',
                    ],
                    recipientEmail: $customerEmail,
                    recipientLocale: $customerLocale,
                    recipientName: $customerName,
                    recipientUserId: $order->getUserId(),
                    channel: NotificationChannel::EMAIL
                );

                $notificationSent = $delivery->isSuccessful();
            } else {
                $allSuccess = false;
            }

            $provisionedResults[] = [
                'service_id' => (int)$service->getId(),
                'result' => $res,
                'notification_sent' => $notificationSent,
            ];
        }

        return [
            'order_id' => (int)$order->getId(),
            'services_created' => count($services),
            'provisioned_services' => $provisionedResults,
            'all_successful' => $allSuccess,
        ];
    }
}
