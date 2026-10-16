<?php
declare(strict_types=1);
namespace Coleza\Domain\Commerce\Services;
use Coleza\Foundation\Database\Connection;
use RuntimeException;
/** Reads current commercial facts without creating services or trusting cached entities. */
final class ProviderServiceEntitlement
{
    public function __construct(private Connection $db) {}
    public static function fingerprint(array $row): string
    {
        $values=[];foreach($row as $key=>$value){$values[$key]=is_array($value)?self::fingerprint($value):($value===null?null:(string)$value);}
        ksort($values);return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }
    public function orderFingerprint(int $orderId): string
    {
        $order=$this->db->selectOne('SELECT id,user_id,organization_id,currency_code,subtotal_minor,tax_total_minor,total_minor FROM orders WHERE id=?'.$this->db->forUpdate(),[$orderId]);
        if($order===null){throw new RuntimeException('Order missing.');}
        $order['items']=$this->db->select('SELECT * FROM order_items WHERE order_id=? ORDER BY id'.$this->db->forUpdate(),[$orderId]);
        return self::fingerprint($order);
    }
    public function current(int $serviceId,bool $requirePaid): array
    {
        $lock=$this->db->forUpdate();
        $service=$this->db->selectOne('SELECT * FROM services WHERE id=?'.$lock,[$serviceId]);
        if($service===null){throw new RuntimeException('Service missing.');}
        $order=$this->db->selectOne('SELECT * FROM orders WHERE id=?'.$lock,[$service['order_id']]);
        $item=$this->db->selectOne('SELECT * FROM order_items WHERE id=?'.$lock,[$service['order_item_id']]);
        if($order===null||$item===null||(int)$item['order_id']!==(int)$order['id']||(int)$item['product_id']!==(int)$service['product_id']
            ||(int)$service['user_id']!==(int)$order['user_id']||$service['organization_id']!==$order['organization_id']
            ||$service['currency_code']!==$order['currency_code']||$service['billing_cycle']!==$item['cycle']
            ||(int)$service['recurring_amount_minor']!==(int)$item['unit_price_minor']){throw new RuntimeException('Service/order ownership, product or price binding changed.');}
        $metadata=json_decode($item['metadata_json']??'{}',true,flags:JSON_THROW_ON_ERROR);
        if(($metadata['domain']??null)!==$service['domain']){throw new RuntimeException('Service domain differs from paid order.');}
        $units=$this->db->select('SELECT metadata_json FROM services WHERE order_item_id=? ORDER BY id'.$lock,[$item['id']]);$unitIds=[];
        foreach($units as $unit){$metadata=json_decode($unit['metadata_json']??'{}',true,flags:JSON_THROW_ON_ERROR);$unitIds[]=$metadata['commerce_order_unit']??((int)$item['quantity']===1?1:0);}
        sort($unitIds);if($unitIds!==range(1,(int)$item['quantity'])){throw new RuntimeException('Service unit bindings require reconciliation.');}
        $invoice=null;
        if($requirePaid){
            $invoices=$this->db->select('SELECT * FROM invoices WHERE order_id=?'.$lock,[$order['id']]);$invoice=$invoices[0]??null;
            if(!in_array($order['status'],['active','completed'],true)||count($invoices)!==1||$invoice['status']!=='paid'
                ||(int)$invoice['total_minor']!==(int)$order['total_minor']||(int)$invoice['paid_amount_minor']!==(int)$order['total_minor']
                ||(int)$invoice['user_id']!==(int)$order['user_id']||$invoice['organization_id']!==$order['organization_id']||$invoice['currency_code']!==$order['currency_code']){throw new RuntimeException('One current fully paid matching order invoice is required.');}
            // Renewal debt must be settled before restoring service as well.
            $debts=$this->db->select("SELECT i.status,i.total_minor,i.paid_amount_minor FROM invoices i JOIN invoice_items ii ON ii.invoice_id=i.id WHERE ii.service_id=?".$lock,[$serviceId]);
            foreach($debts as $debt){if(!in_array($debt['status'],['cancelled','paid'],true)||($debt['status']==='paid'&&(int)$debt['paid_amount_minor']!==(int)$debt['total_minor'])){throw new RuntimeException('Outstanding service invoice blocks restoration.');}}
        }
        return compact('service','order','item','invoice');
    }
}
