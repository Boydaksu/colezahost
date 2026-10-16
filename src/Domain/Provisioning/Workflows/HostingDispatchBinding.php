<?php
declare(strict_types=1);
namespace Coleza\Domain\Provisioning\Workflows;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use RuntimeException;
/** Durable identity snapshots exclude counters changed by unrelated valid reservations. */
final class HostingDispatchBinding
{
    private static function digest(Connection $db,string $table,int $id,array $keys):string
    {
        $row=$db->selectOne('SELECT * FROM '.$table.' WHERE id=?'.$db->forUpdate(),[$id]);if($row===null){throw new RuntimeException('Dispatch target no longer exists.');}
        $values=[];foreach($keys as $key){$values[$key]=isset($row[$key])?(string)$row[$key]:null;}ksort($values);return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }
    public static function service(Connection $db,int $id):string {return self::digest($db,'services',$id,['id','user_id','organization_id','order_id','order_item_id','product_id','status','domain','username','metadata_json','lock_version']);}
    public static function server(Connection $db,int $id):string {return self::digest($db,'servers',$id,['id','hostname','ip_address','provider_slug','server_pool_id','location_id','status','port','secure','auth_type','auth_secret_encrypted','metadata_json']);}
    public static function assertCurrent(Connection $db,ProvisioningOperation $op,CapacityReservationService $capacity):void
    {
        if(!$db->inTransaction()){throw new \LogicException('Dispatch postconditions require a local transaction.');}
        $payload=$op->getPayload();$proof=$payload['_hosting_dispatch']??null;
        if(!is_array($proof)||($proof['version']??null)!==1||!is_string($proof['service_sha256']??null)||!is_string($proof['server_sha256']??null)||!is_string($proof['reservation_token']??null)
            ||!hash_equals($proof['service_sha256'],self::service($db,$op->getServiceId()))||!hash_equals($proof['server_sha256'],self::server($db,$op->getServerId()??0))){throw new RuntimeException('Hosting dispatch identity changed; refresh reconciliation.');}
        if($capacity->getConnection()!==$db){throw new RuntimeException('Capacity connection differs from dispatch connection.');}
        $reservation=$db->selectOne('SELECT * FROM capacity_reservations WHERE reservation_token=?'.$db->forUpdate(),[$proof['reservation_token']]);
        if($reservation===null||$reservation['status']!=='committed'||(int)$reservation['service_id']!==$op->getServiceId()||(int)$reservation['server_id']!==$op->getServerId()
            ||(int)$reservation['disk_mb']!==($payload['disk_limit_mb']??null)||(int)$reservation['bandwidth_mb']!==($payload['bandwidth_limit_mb']??null)||(int)$reservation['accounts_count']!==1){throw new RuntimeException('Bound durable hosting capacity is missing or inconsistent.');}
        $placement=$db->selectOne('SELECT * FROM service_placements WHERE service_id=? ORDER BY id DESC LIMIT 1'.$db->forUpdate(),[$op->getServiceId()]);
        if($placement===null||$placement['status']!=='reserved'||$placement['released_at']!==null||(int)$placement['server_id']!==$op->getServerId()
            ||$placement['package_identifier']!==$payload['package']){throw new RuntimeException('Bound hosting placement changed.');}
    }
}
