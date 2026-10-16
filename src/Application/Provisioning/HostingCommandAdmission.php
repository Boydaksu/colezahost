<?php
declare(strict_types=1);
namespace Coleza\Application\Provisioning;
use Coleza\Application\Identity\CommandAuthorizer;
use Coleza\Domain\Commerce\Services\ProviderServiceEntitlement;
use Coleza\Domain\Fraud\Review\RiskReviewDecisionSnapshot;
use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Provisioning\Workflows\{HostingAdmission,HostingProvisioningRequest};
use Coleza\Foundation\Database\Connection;
use RuntimeException;
/** Actor IDs must originate in an authenticated host context. No caller supplied authorization flags. */
final class HostingCommandAdmission implements HostingAdmission
{
    public function __construct(private Connection $db,private int $actorId) {}
    public function connection():Connection{return $this->db;}
    public function request(int $serviceId):HostingProvisioningRequest
    {
        $facts=(new ProviderServiceEntitlement($this->db))->current($serviceId,true);
        $map=$this->configuration((int)$facts['service']['product_id']);
        $owner=(new CommandAuthorizer($this->db))->actor((int)$facts['service']['user_id']);
        return new HostingProvisioningRequest($serviceId,domain:$facts['service']['domain'],packageIdentifier:$map['package'],
            requiredDiskMb:$map['disk_mb'],requiredBandwidthMb:$map['bandwidth_mb'],requireDedicatedIp:$map['dedicated_ip']??false,
            serverPoolId:$map['server_pool_id']??null,locationId:$map['location_id']??null,preferredServerId:$map['server_id']??null,providerSlug:'cpanel',contactEmail:$owner['email']);
    }
    private function configuration(int $productId):array
    {
        $product=$this->db->selectOne('SELECT p.*,g.is_active AS group_active FROM products p JOIN product_groups g ON g.id=p.group_id WHERE p.id=?'.$this->db->forUpdate(),[$productId]);
        $metadata=json_decode($product['metadata_json']??'{}',true,flags:JSON_THROW_ON_ERROR);$map=$metadata['hosting']??null;
        if($product===null||(int)$product['is_active']!==1||(int)$product['group_active']!==1||$product['type']!=='hosting'||!is_array($map)
            ||($map['provider']??null)!=='cpanel'||!is_string($map['package']??null)||!preg_match('/^[A-Za-z0-9_.-]{1,128}$/D',$map['package'])
            ||array_diff(array_keys($map),['provider','package','disk_mb','bandwidth_mb','dedicated_ip','server_id','server_pool_id','location_id'])!==[]){throw new RuntimeException('Explicit active hosting product configuration required.');}
        foreach(['disk_mb','bandwidth_mb']as$key){if(!is_int($map[$key]??null)||$map[$key]<0||$map[$key]>2147483647){throw new RuntimeException('Invalid hosting resource mapping.');}}
        foreach(['server_id','server_pool_id','location_id']as$key){if(isset($map[$key])&&(!is_int($map[$key])||$map[$key]<1)){throw new RuntimeException('Invalid hosting placement mapping.');}}
        if(isset($map['dedicated_ip'])&&!is_bool($map['dedicated_ip'])){throw new RuntimeException('Invalid dedicated IP mapping.');}
        return $map;
    }
    public function approve(int $serviceId,string $action,?HostingProvisioningRequest $request=null):array
    {
        if(!$this->db->inTransaction()){throw new \LogicException('Admission requires a local transaction.');}
        $permission=match($action){'create_account'=>'services.provision','suspend_account'=>'services.suspend','unsuspend_account'=>'services.unsuspend','terminate_account'=>'services.terminate',default=>throw new RuntimeException('Unsupported hosting command.')};
        $authority=new CommandAuthorizer($this->db);$authority->requireSystem($this->actorId,$permission);
        $paid=in_array($action,['create_account','unsuspend_account'],true);
        $facts=(new ProviderServiceEntitlement($this->db))->current($serviceId,$paid);$service=$facts['service'];$order=$facts['order'];$risk=null;
        if($paid){
            $owner=$authority->actor((int)$service['user_id']);
            if($service['organization_id']!==null&&$authority->membership((int)$service['user_id'],(int)$service['organization_id'])===null){throw new RuntimeException('Current service owner organization membership required.');}
            $risk=$this->risk($order,$owner['email'],$authority);
        }
        $map=null;
        if($action==='create_account'){
            $map=$this->configuration((int)$service['product_id']);
            if($request!==null&&$request->toArray()!==$this->request($serviceId)->toArray()){throw new RuntimeException('Hosting request differs from current paid catalog entitlement.');}
        }
        $scope=['service_id'=>$serviceId,'owner_id'=>(int)$service['user_id'],'organization_id'=>$service['organization_id'],'order_id'=>(int)$order['id'],
            'order_sha256'=>(new ProviderServiceEntitlement($this->db))->orderFingerprint((int)$order['id']),'invoice'=>$facts['invoice']===null?null:ProviderServiceEntitlement::fingerprint($facts['invoice']),'risk'=>$risk,'hosting'=>$map];
        return ['version'=>1,'actor_id'=>$this->actorId,'scope_sha256'=>ProviderServiceEntitlement::fingerprint($scope)];
    }
    private function risk(array $order,string $email,CommandAuthorizer $authority):array
    {
        $lock=$this->db->forUpdate();$risk=$this->db->selectOne("SELECT * FROM fraud_risk_evaluations WHERE entity_type='order' AND entity_id=? ORDER BY id DESC LIMIT 1".$lock,[$order['id']]);
        if($risk===null||(int)$risk['user_id']!==(int)$order['user_id']||$risk['organization_id']!==$order['organization_id']||!in_array($risk['decision'],['accept','review','reject'],true)){throw new RuntimeException('Current order risk evaluation required.');}
        $context=json_decode($risk['context_snapshot_json']??'{}',true,flags:JSON_THROW_ON_ERROR);$attributes=$context['custom_attributes']??[];
        if(($context['entity_type']??null)!=='order'||($context['entity_id']??null)!==(int)$order['id']||($context['user_id']??null)!==(int)$order['user_id']||($context['organization_id']??null)!==($order['organization_id']===null?null:(int)$order['organization_id'])||($context['email']??null)!==$email||($context['currency']??null)!==$order['currency_code']||($attributes['order_total_minor']??null)!==(int)$order['total_minor']
            ||($attributes['order_fingerprint']??null)!==(new ProviderServiceEntitlement($this->db))->orderFingerprint((int)$order['id'])){throw new RuntimeException('Risk evaluation no longer binds the current order.');}
        $reviews=$this->db->select('SELECT * FROM fraud_risk_reviews WHERE evaluation_id=?'.$lock,[$risk['id']]);
        if($reviews===[]){if($risk['decision']!=='accept'){throw new RuntimeException('Risk review is required.');}}
        else{
            if(count($reviews)!==1||$reviews[0]['status']!=='approved'){throw new RuntimeException('Risk review is not approved.');}
            $review=$reviews[0];
            if($review['entity_type']!=='order'||(int)$review['entity_id']!==(int)$order['id']||(int)$review['user_id']!==(int)$order['user_id']||$review['organization_id']!==$order['organization_id']){throw new RuntimeException('Risk review owner binding invalid.');}
            $snapshots=$this->db->select('SELECT * FROM fraud_risk_review_decisions WHERE review_id=?'.$lock,[$review['id']]);$snapshot=$snapshots[0]??null;
            if(count($snapshots)!==1||(int)$snapshot['evaluation_id']!==(int)$risk['id']||$snapshot['original_decision']!==$risk['decision']||$snapshot['final_decision']!=='approved'
                ||(int)$snapshot['decided_by_staff_id']!==(int)$review['resolved_by']||$snapshot['decided_at']!==$review['resolved_at']
                ||(bool)$snapshot['is_override']!==($risk['decision']!=='accept')||trim($snapshot['override_reason'])===''){throw new RuntimeException('Risk review decision binding invalid.');}
            $checksum=RiskReviewDecisionSnapshot::calculateChecksum((int)$review['id'],(int)$risk['id'],$snapshot['original_decision'],$snapshot['final_decision'],(bool)$snapshot['is_override'],$snapshot['override_reason'],(int)$snapshot['decided_by_staff_id'],new \DateTimeImmutable($snapshot['decided_at']));
            if(!hash_equals($checksum,$snapshot['snapshot_checksum'])){throw new RuntimeException('Risk review integrity invalid.');}
            $authority->requireSystem((int)$review['resolved_by'],'fraud.review');
        }
        return ['evaluation'=>ProviderServiceEntitlement::fingerprint($risk),'reviews'=>ProviderServiceEntitlement::fingerprint($reviews),'snapshots'=>ProviderServiceEntitlement::fingerprint($snapshots??[])];
    }
    public function record(int $serviceId,string $action,string $operationUuid,string $phase):void
    {
        (new AuditLogger($this->db))->log($this->actorId,'hosting.'.$action.'.'.$phase,'service:'.$serviceId,payload:['operation_uuid'=>$operationUuid]);
    }
}
