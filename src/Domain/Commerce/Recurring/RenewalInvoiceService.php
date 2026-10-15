<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Recurring;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class RenewalInvoiceService
{
    private string $renewalRecordsTable = 'renewal_records';

    public function __construct(
        private Connection $db,
        private InvoiceService $invoiceService,
        private ?TaxService $taxService = null
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                service_id INT NOT NULL,
                user_id INT NOT NULL,
                organization_id INT NULL,
                billing_cycle VARCHAR(30) NOT NULL,
                period_start VARCHAR(20) NOT NULL,
                period_end VARCHAR(20) NOT NULL,
                invoice_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (service_id, period_start)
            )',
            $this->renewalRecordsTable,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Check if a renewal invoice was already generated for this service and period.
     */
    public function isRenewalAlreadyGenerated(int $serviceId, string $periodStart): bool
    {
        $row = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE service_id = ? AND period_start = ?', $this->renewalRecordsTable) . $this->db->forUpdate(),
            [$serviceId, $periodStart]
        );
        return $row !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRenewalRecord(int $serviceId, string $periodStart): ?array
    {
        $rows=$this->db->select(sprintf('SELECT * FROM %s WHERE service_id = ? AND period_start = ?', $this->renewalRecordsTable) . $this->db->forUpdate(),[$serviceId,$periodStart]);
        if(count($rows)>1){throw new ValidationException(['renewal'=>['Duplicate legacy period records require reconciliation.']]);}
        return $rows[0]??null;
    }

    private function assertStorage(): void
    {
        $tables=['invoices'=>['id','user_id','organization_id','currency_code','status','total_minor','paid_amount_minor'],'invoice_items'=>['id','invoice_id','service_id','metadata_json'],'services'=>['id','user_id','organization_id','status','auto_renew','billing_cycle','next_due_date','recurring_amount_minor','currency_code','lock_version'], 'renewal_records'=>['id','service_id','user_id','organization_id','billing_cycle','period_start','period_end','invoice_id']];
        if($this->taxService!==null){$tables+=['tax_classes'=>['id','is_default'],'tax_zones'=>['id','country_codes_json'],'tax_rates'=>['id','tax_class_id','tax_zone_id','is_active'],'tax_exemptions'=>['id','customer_id','is_exempt']];}
        if(!\Coleza\Foundation\Database\PdoSchema::useInstalledSchema($this->db,$tables)){throw new \LogicException('Install renewal and service storage first.');}
        if($this->db->getDriverName()==='mysql'){
            foreach(array_keys($tables)as$table){$row=$this->db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table]);if(strcasecmp((string)$row['ENGINE'],'InnoDB')!==0){throw new \LogicException('Transactional renewal storage required.');}}
        }
        if($this->invoiceService->connection()->getPdo()!==$this->db->getPdo()){throw new \LogicException('Renewal and invoice must use the same connection.');}
    }

    /** Only the service ID and requested period are selectors; money and ownership come from storage. */
    public function generateRenewalInvoice(array $serviceData,?string $invoiceDueDate=null,bool $allowDisabledAutoRenew=false): Invoice
    {
        $this->assertStorage();
        $id=\Coleza\Domain\Commerce\CommerceAmounts::integer($serviceData['service_id']??$serviceData['id']??0,'service_id',1);
        if($invoiceDueDate!==null){BillingPeriod::date($invoiceDueDate);}
        return $this->atomic(function()use($id,$serviceData,$invoiceDueDate,$allowDisabledAutoRenew):Invoice{
            $service=$this->db->selectOne('SELECT * FROM services WHERE id=?'.$this->db->forUpdate(),[$id]);
            if($service===null){throw new ValidationException(['service'=>['Persisted service required.']]);}
            $cycle=(string)$service['billing_cycle'];
            foreach(['user_id','organization_id','currency_code','billing_cycle']as$field){
                if(array_key_exists($field,$serviceData)&&($serviceData[$field]===null?null:(string)$serviceData[$field])!==($service[$field]===null?null:(string)$service[$field])){throw new ValidationException(['service'=>['Service ownership, currency or cycle changed. Reload the service.']]);}
            }
            $period=BillingPeriod::computePeriod((string)($serviceData['next_due_date']??$service['next_due_date']),$cycle);
            if($period['end']<=$period['start']){throw new ValidationException(['cycle'=>['One-time services cannot be renewed.']]);}
            $existing=$this->findRenewalRecord($id,$period['start']);
            if($existing!==null){
                $invoice=$this->invoiceService->findInvoiceById((int)$existing['invoice_id']);
                if($invoice===null||!$this->matchesInvoice($invoice,$service,$existing)){throw new ValidationException(['renewal'=>['Existing renewal requires reconciliation.']]);}
                return $invoice;
            }
            if($period['start']!==$service['next_due_date']||!in_array($service['status'],['active','suspended'],true)||(!$allowDisabledAutoRenew&&(int)$service['auto_renew']!==1)){throw new ValidationException(['service'=>['Current renewable service period required.']]);}
            $amount=\Coleza\Domain\Commerce\CommerceAmounts::integer((int)$service['recurring_amount_minor'],'recurring_amount_minor');
            $net=$amount;$taxAmount=0;$taxSnapshot=[];
            if($this->taxService!==null){
                $class=$this->taxService->requireDefaultTaxClass();
                $metadata=json_decode((string)($service['metadata_json']??'{}'),true,flags:JSON_THROW_ON_ERROR);
                $tax=$this->taxService->calculateTax($amount,$class->getId(),(string)($metadata['billing_country_code']??'TR'),customerId:$service['organization_id']===null?null:(int)$service['organization_id']);
                $net=$tax->getSubtotalMinor();$taxAmount=$tax->getTaxTotalMinor();$taxSnapshot=$tax->toArray();
            }
            $total=\Coleza\Domain\Commerce\CommerceAmounts::add($net,$taxAmount);
            $invoice=$this->invoiceService->createInvoice(['user_id'=>(int)$service['user_id'],'organization_id'=>$service['organization_id']===null?null:(int)$service['organization_id'],'currency_code'=>$service['currency_code'],'due_date'=>$invoiceDueDate??$period['start'],'notes'=>"Automated renewal invoice for service #{$id}"],[['description'=>sprintf('Service Renewal #%s (%s to %s)',$service['service_number'],$period['start'],$period['end']),'quantity'=>1,'unit_amount_minor'=>$net,'subtotal_minor'=>$net,'tax_amount_minor'=>$taxAmount,'total_minor'=>$total,'service_id'=>$id,'tax_snapshot'=>$taxSnapshot,'metadata'=>['service_id'=>$id,'billing_cycle'=>$cycle,'period_start'=>$period['start'],'period_end'=>$period['end'],'recurring_basis_minor'=>$amount]]]);
            $this->db->statement('INSERT INTO renewal_records (service_id,user_id,organization_id,billing_cycle,period_start,period_end,invoice_id) VALUES (?,?,?,?,?,?,?)',[$id,$service['user_id'],$service['organization_id'],$cycle,$period['start'],$period['end'],$invoice->getId()]);
            return $invoice;
        });
    }

    /** A deadlock can be retried only when this command owns the entire transaction. */
    private function atomic(callable $operation): mixed
    {
        $owns=!$this->db->getPdo()->inTransaction();
        for($attempt=0;;$attempt++){
            try{return $this->db->transaction($operation);}
            catch(\PDOException $error){if(!$owns||$attempt>=2||($error->errorInfo[0]??$error->getCode())!=='40001'){throw $error;}}
        }
    }

    private function matchesInvoice(Invoice $invoice,array $service,array $record): bool
    {
        $org=$service['organization_id']===null?null:(int)$service['organization_id'];
        return $invoice->getUserId()===(int)$service['user_id']&&$invoice->getOrganizationId()===$org&&$invoice->getCurrencyCode()===$service['currency_code']&&(int)$record['user_id']===(int)$service['user_id']&&($record['organization_id']===null?null:(int)$record['organization_id'])===$org&&$record['billing_cycle']===$service['billing_cycle']&&BillingPeriod::computePeriod($record['period_start'],$record['billing_cycle'])['end']===$record['period_end'];
    }

    /** Apply the recorded paid period exactly once. Unrelated invoice service links confer no renewal right. */
    public function settlePaidInvoice(int $invoiceId,bool $allowReactivation=true,?int $expectedServiceId=null): array
    {
        $this->assertStorage();
        return $this->atomic(function()use($invoiceId,$allowReactivation,$expectedServiceId):array{
            $ids=array_column($this->db->select('SELECT service_id FROM renewal_records WHERE invoice_id=? ORDER BY service_id'.$this->db->forUpdate(),[$invoiceId]),'service_id');
            if($expectedServiceId!==null&&array_map('intval',$ids)!==[$expectedServiceId]){throw new ValidationException(['renewal'=>['Invoice must belong to the requested renewal service.']]);}
            if($ids===[]){return [];}
            $services=[];foreach($ids as$id){$services[(int)$id]=$this->db->selectOne('SELECT * FROM services WHERE id=?'.$this->db->forUpdate(),[$id]);}
            $invoice=$this->invoiceService->findInvoiceById($invoiceId);
            if($invoice===null||!$invoice->isPaid()||$invoice->getBalanceDueMinor()!==0){return [];}
            $records=$this->db->select('SELECT * FROM renewal_records WHERE invoice_id=? ORDER BY service_id'.$this->db->forUpdate(),[$invoiceId]);
            $actions=[];
            foreach($records as$record){
                $id=(int)$record['service_id'];$service=$services[$id]??null;
                if($service===null||!$this->matchesInvoice($invoice,$service,$record)){throw new ValidationException(['renewal'=>['Paid renewal owner/currency/period mismatch.']]);}
                $items=array_values(array_filter($invoice->getItems(),static fn($item):bool=>$item->getServiceId()===$id));
                if(count($items)!==1||($items[0]->getMetadata()['period_start']??null)!==$record['period_start']||($items[0]->getMetadata()['period_end']??null)!==$record['period_end']){throw new ValidationException(['renewal'=>['Invoice line does not match the renewal period.']]);}
                $metadata=$items[0]->getMetadata();
                $receipt=['invoice_id'=>$invoiceId,'service_id'=>$id,'period_start'=>$record['period_start'],'period_end'=>$record['period_end']];
                if(array_key_exists('renewal_settlement',$metadata)){
                    if(!is_array($metadata['renewal_settlement'])||array_intersect_key($metadata['renewal_settlement'],$receipt)!==$receipt){throw new ValidationException(['renewal'=>['Settlement receipt does not match the paid period.']]);}
                    continue;
                }
                if($service['next_due_date']>=$record['period_end']){
                    $metadata['renewal_settlement']=$receipt+['result'=>'already_advanced','recorded_at'=>date('Y-m-d H:i:s')];
                    $this->db->statement('UPDATE invoice_items SET metadata_json=? WHERE id=?',[json_encode($metadata,JSON_THROW_ON_ERROR),$items[0]->getId()]);
                    continue;
                }
                if($service['next_due_date']!==$record['period_start']||!in_array($service['status'],['active','suspended'],true)){throw new ValidationException(['renewal'=>['Service state or due period requires reconciliation.']]);}
                $reactivate=$service['status']==='suspended'&&$allowReactivation;
                $status=$reactivate?'active':$service['status'];
                $this->db->statement('UPDATE services SET next_due_date=?,status=?,suspension_reason=?,lock_version=lock_version+1 WHERE id=?',[$record['period_end'],$status,$reactivate?null:$service['suspension_reason'],$id]);
                $metadata['renewal_settlement']=$receipt+['result'=>'applied','recorded_at'=>date('Y-m-d H:i:s')];
                $this->db->statement('UPDATE invoice_items SET metadata_json=? WHERE id=?',[json_encode($metadata,JSON_THROW_ON_ERROR),$items[0]->getId()]);
                if($reactivate){$actions[]=['service_id'=>$id,'action'=>'unsuspended','status'=>$status];}
                $actions[]=['service_id'=>$id,'action'=>'renewed','status'=>$status,'next_due_date'=>$record['period_end']];
            }
            return $actions;
        });
    }

    /**
     * Batch process multiple candidate services, generating invoices only for those due within leadDays.
     *
     * @param array<array<string, mixed>> $candidates
     * @return array<Invoice>
     */
    public function processBatchRenewals(array $candidates, string $asOfDate, int $leadDays = 14): array
    {
        $invoices = [];
        foreach ($candidates as $candidate) {
            $nextDue = (string)($candidate['next_due_date'] ?? '');
            if ($nextDue === '') {
                continue;
            }

            if (BillingPeriod::isDueForRenewal($nextDue, $asOfDate, $leadDays)) {
                $invoices[] = $this->generateRenewalInvoice($candidate);
            }
        }
        return $invoices;
    }

    /**
     * Advance next due date by cycle length upon invoice settlement.
     */
    public static function advanceNextDueDate(string $currentDueDate, string $cycle): string
    {
        return BillingPeriod::calculateNextDueDate($currentDueDate, $cycle);
    }
}
