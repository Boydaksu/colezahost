<?php
declare(strict_types=1);
namespace Coleza\Application\Commerce\Orders;
use Coleza\Domain\Commerce\CommerceAmounts as Amounts;
use Coleza\Domain\Commerce\Orders\{Order,OrderService};
use Coleza\Domain\Catalog\Services\CatalogService;
use Coleza\Domain\Pricing\Services\PricingService;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\{Connection,PdoSchema};
use Coleza\Foundation\Exceptions\ValidationException;
final class CatalogOrderPricing
{
    public function __construct(private Connection $db,private string $countryCode='TR'){}
    public function create(CreateOrderCommand $command): Order
    {
        if(!preg_match('/^[A-Z]{3}$/D',$command->currencyCode)||$command->items===[]){throw new ValidationException(['order'=>['Currency and product selection required.']]);}
        $requirements=['products'=>['id','group_id','name','is_active'],'product_groups'=>['id','is_active'],'configurable_options'=>['id','product_id','group_id','is_required','is_active'],'configurable_option_subs'=>['id','option_id','is_active'],'product_addons'=>['id','is_active','applicable_products_json'],'product_availability'=>['product_id','status','stock_quantity'],'price_points'=>['id','target_type','target_id','currency_code','cycle','price_minor','setup_fee_minor','is_active'],'price_overrides'=>['id','scope','scope_id','target_type','target_id','override_type','override_value','currency_code','cycle','is_active'],'tax_classes'=>['id','is_default'],'tax_zones'=>['id','country_codes_json'],'tax_rates'=>['id','tax_class_id','tax_zone_id','is_active'],'tax_exemptions'=>['id','customer_id','is_exempt']];
        if(!PdoSchema::useInstalledSchema($this->db,$requirements)){throw new ValidationException(['configuration'=>['Catalog, pricing and tax configuration must be installed.']]);}
        if($this->db->getDriverName()==='mysql'){foreach(array_keys($requirements)as$table){$row=$this->db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table]);if(strcasecmp((string)$row['ENGINE'],'InnoDB')!==0){throw new \LogicException('Transactional purchase configuration required.');}}}
        $catalog=new CatalogService($this->db);$pricing=new PricingService($this->db,null,$catalog);$tax=new TaxService($this->db);
        return $this->db->transaction(function()use($command,$catalog,$pricing,$tax):Order{
            $items=[];$quantities=[];
            foreach($command->items as$item){if(!is_array($item)||!array_is_list($command->items)){throw new ValidationException(['items'=>['A list of product selections is required.']]);}
                $unknown=array_diff(array_keys($item),['product_id','cycle','billing_cycle','quantity','option_sub_ids','addon_ids','domain']);if($unknown!==[]){throw new ValidationException(['items'=>['Only product, cycle, quantity, options, addons and domain may be submitted.']]);}
                $product=Amounts::integer($item['product_id']??0,'product_id',1);$qty=Amounts::integer($item['quantity']??1,'quantity',1);$quantities[$product]=Amounts::add($quantities[$product]??0,$qty);
            }
            // Stable product lock ordering prevents cross-product checkout deadlocks.
            $catalog->lockPurchaseProducts(array_keys($quantities));
            $tax->requireDefaultTaxClass();
            foreach($command->items as$item){
                $product=Amounts::integer($item['product_id'],'product_id',1);$qty=Amounts::integer($item['quantity']??1,'quantity',1);
                $cycle=$item['cycle']??$item['billing_cycle']??'monthly';if(!is_string($cycle)||!PriceCycle::isValid($cycle)||(isset($item['cycle'],$item['billing_cycle'])&&$item['cycle']!==$item['billing_cycle'])){throw new ValidationException(['cycle'=>['A valid unambiguous billing cycle is required.']]);}
                $subs=$this->ids($item['option_sub_ids']??[]);$addons=$this->ids($item['addon_ids']??[]);$selection=$catalog->purchaseSelection($product,$subs,$addons,$quantities[$product]);
                $quote=$pricing->calculateQuote($product,$command->currencyCode,$cycle,$subs,$addons,customerId:$command->organizationId,userId:$command->organizationId===null?$command->userId:null,strictSelection:true);
                $metadata=['commerce_price_snapshot'=>$quote->toArray()];if(isset($item['domain'])){if(!is_string($item['domain'])||strlen($item['domain'])>253||str_contains($item['domain'],"\0")){throw new ValidationException(['domain'=>['Invalid domain.']]);}$metadata['domain']=$item['domain'];}
                $items[]=['product_id'=>$product,'product_name'=>$selection['name'],'cycle'=>$cycle,'quantity'=>$qty,'unit_price_minor'=>$quote->getRecurringTotalMinor(),'unit_setup_fee_minor'=>$quote->getSetupFeeMinor(),'option_sub_ids'=>$subs,'addon_ids'=>$addons,'metadata'=>$metadata];
            }
            return (new OrderService($this->db,null,$tax,$catalog))->createOrder(['user_id'=>$command->userId,'organization_id'=>$command->organizationId,'currency_code'=>$command->currencyCode,'country_code'=>$this->countryCode,'tax_customer_id'=>$command->organizationId],$items);
        });
    }
    private function ids(mixed $ids): array
    {
        if(!is_array($ids)||!array_is_list($ids)){throw new ValidationException(['selection'=>['Selection must be a list.']]);}$out=[];foreach($ids as$id){$id=Amounts::integer($id,'selection',1);if(in_array($id,$out,true)){throw new ValidationException(['selection'=>['Duplicate selections are invalid.']]);}$out[]=$id;}sort($out);return $out;
    }
}
