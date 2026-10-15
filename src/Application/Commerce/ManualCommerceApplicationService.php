<?php
declare(strict_types=1);
namespace Coleza\Application\Commerce;

use Coleza\Application\Identity\CommandAuthorizer;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Payments\{Payment,PaymentService};
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Services\{Service,ServiceService};
use Coleza\Foundation\Database\{Connection,PdoSchema};
use Coleza\Foundation\Exceptions\{ResourceNotFoundException,ValidationException};

/** Local bank-payment/manual-service vertical. Provider fulfillment has its own workflow. */
final class ManualCommerceApplicationService
{
    private CommandAuthorizer $authorization;
    private InvoiceService $invoices;
    private OrderService $orders;
    private PaymentService $payments;
    private ServiceService $services;
    public function __construct(private Connection $db)
    {
        $this->authorization=new CommandAuthorizer($db);
        $this->invoices=new InvoiceService($db);$this->orders=new OrderService($db);
        $this->payments=new PaymentService($db,$this->invoices,$this->orders);$this->services=new ServiceService($db);
    }
    private function storage(): void
    {
        $tables=['payments'=>['id','invoice_id','user_id','organization_id','status','amount_minor'],'payment_allocations'=>['id','payment_id','invoice_id'],'invoices'=>['id','status','paid_amount_minor'],'invoice_items'=>['id','invoice_id','service_id'],'renewal_records'=>['id','service_id','invoice_id'],'services'=>['id','order_id','lock_version'],'service_placements'=>['id','service_id']];
        if(!PdoSchema::useInstalledSchema($this->db,$tables)){throw new ValidationException(['installation'=>['Install manual commerce storage first.']]);}
        if($this->db->getDriverName()==='mysql'){foreach(array_keys($tables)as$table){$row=$this->db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table]);if(strcasecmp((string)$row['ENGINE'],'InnoDB')!==0){throw new \LogicException('Transactional commerce storage required.');}}}
    }
    public function approvePayment(int $paymentId,int $actor): Payment
    {
        $this->storage();
        return $this->authorization->run($actor,function()use($paymentId,$actor):Payment{
            $this->authorization->requireSystem($actor,'payments.approve');
            $payment=$this->payments->findPaymentById($paymentId)??throw new ResourceNotFoundException('Payment not found.');
            if(!in_array($payment->getPaymentMethod(),['bank_transfer','manual'],true)||$payment->getInvoiceId()===null){throw new ValidationException(['payment'=>['A manual invoice payment is required.']]);}
            $invoice=$this->invoices->findInvoiceById($payment->getInvoiceId())??throw new ResourceNotFoundException('Invoice not found.');
            if($payment->getUserId()!==$invoice->getUserId()||$payment->getOrganizationId()!==$invoice->getOrganizationId()||$payment->getCurrencyCode()!==$invoice->getCurrencyCode()){throw new ValidationException(['payment'=>['Payment and invoice ownership or currency mismatch.']]);}
            foreach($invoice->getItems()as$item){if($item->getServiceId()!==null){$this->services->requireManualService($item->getServiceId());}}
            if($payment->getStatus()!==Payment::STATUS_COMPLETED){
                if($payment->getAmountMinor()>$invoice->getBalanceDueMinor()){throw new ValidationException(['amount'=>['Payment exceeds the remaining invoice balance.']]);}
                $payment=$this->payments->approveManualPayment($paymentId);
            }
            (new RenewalInvoiceService($this->db,$this->invoices))->settlePaidInvoice($invoice->getId());
            return $payment;
        },'command.manual_payment_approved');
    }
    public function fulfillOrder(int $orderId,int $actor): array
    {
        $this->storage();
        return $this->authorization->run($actor,function()use($orderId,$actor):array{
            $this->authorization->requireSystem($actor,'services.manage');
            $order=$this->orders->findOrderById($orderId)??throw new ResourceNotFoundException('Order not found.');
            return $this->services->createServicesFromOrder($order);
        },'command.manual_order_fulfilled');
    }
    public function activateService(int $serviceId,int $actor): Service
    {
        $this->storage();
        return $this->authorization->run($actor,function()use($serviceId,$actor):Service{
            $this->authorization->requireSystem($actor,'services.manage');
            $service=$this->services->requireManualService($serviceId);
            if($service->getOrderId()===null){throw new ValidationException(['service'=>['A paid order service is required.']]);}
            $order=$this->orders->findOrderById($service->getOrderId())??throw new ResourceNotFoundException('Order not found.');
            $this->services->createServicesFromOrder($order);
            if($service->getStatus()==='active'){return $service;}
            if($service->getStatus()!=='pending'){throw new ValidationException(['service'=>['Only pending manual services can be activated.']]);}
            return $this->services->activateService($serviceId,expectedLockVersion:$service->getLockVersion());
        },'command.manual_service_activated');
    }
}
