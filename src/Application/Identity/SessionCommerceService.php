<?php
declare(strict_types=1);
namespace Coleza\Application\Identity;
use Coleza\Domain\Identity\Impersonation\ImpersonationService;
use Coleza\Domain\Identity\Session\SessionRevocationSchema;
use Coleza\Foundation\Database\Connection;
/** Session entry point uses the effective customer, never the real administrator's permissions. */
final class SessionCommerceService
{
    public function __construct(private Connection $db,private AuthenticationService $authentication,private ImpersonationService $impersonation){if(!$authentication->usesConnection($db)||!$impersonation->usesConnection($db)){throw new \InvalidArgumentException('Shared connection required.');}}
    public function invoice(string $session,int $id): \Coleza\Domain\Commerce\Invoices\Invoice
    {
        return $this->execute($session,'invoices.view',fn(int $actor)=>(new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService(new \Coleza\Domain\Commerce\Invoices\InvoiceService($this->db)))->getInvoice($id,$actor));
    }
    public function order(string $session,int $id): \Coleza\Domain\Commerce\Orders\Order
    {
        return $this->execute($session,'orders.view',fn(int $actor)=>(new \Coleza\Application\Commerce\Orders\OrderApplicationService(new \Coleza\Domain\Commerce\Orders\OrderService($this->db)))->getOrder($id,$actor));
    }
    public function quote(string $session,int $id): \Coleza\Domain\Documents\Quotes\Quote
    {
        return $this->execute($session,'quotes.view',fn(int $actor)=>(new \Coleza\Application\Commerce\Quotes\QuoteApplicationService(new \Coleza\Domain\Documents\Quotes\QuoteService($this->db->getPdo())))->getQuote($id,$actor));
    }
    private function execute(string $session,string $operation,callable $callback): mixed
    {
        $this->authentication->prepare();
        return SessionRevocationSchema::run($this->db,function()use($session,$operation,$callback):mixed{
            $this->impersonation->guardOperation($session,$operation);$principal=$this->impersonation->effectiveUser($session);
            return $callback($principal['user_id']);
        });
    }
}
