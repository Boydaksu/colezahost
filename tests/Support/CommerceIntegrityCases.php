<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Foundation\Exceptions\ValidationException;
trait CommerceIntegrityCases
{
    private function commerceFixture(): array
    {
        $db=$this->db;$sessions=new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($db);
        (new \Coleza\Domain\Identity\Auth\AuthService($db,new \Coleza\Domain\Identity\Security\PasswordHasher(),$sessions))->ensureTables();
        $db->insert('users',['id'=>2,'email'=>'buyer@example.test','password_hash'=>'unused','name'=>'Buyer','is_active'=>1]);
        $catalog=new \Coleza\Domain\Catalog\Services\CatalogService($db);$catalog->ensureTables();$group=$catalog->createProductGroup(['name'=>'Hosting','slug'=>'hosting']);$product=$catalog->createProduct(['group_id'=>$group->getId(),'name'=>'Server price','slug'=>'server-price']);
        $pricing=new \Coleza\Domain\Pricing\Services\PricingService($db,null,$catalog);$pricing->ensureTables();$pricing->setPricePoint(['target_id'=>$product->getId(),'currency_code'=>'TRY','cycle'=>'monthly','price_minor'=>10000]);
        $tax=new \Coleza\Domain\Tax\Services\TaxService($db);$tax->ensureTables();$tax->createTaxClass(['code'=>'standard','name'=>'Standard','is_default'=>true]);
        $orders=new \Coleza\Domain\Commerce\Orders\OrderService($db,$pricing,$tax,$catalog);$orders->ensureTables();$invoices=new \Coleza\Domain\Commerce\Invoices\InvoiceService($db);$invoices->ensureTables();
        return [$orders,$invoices,$catalog,$pricing,$tax,(int)$product->getId()];
    }
    public function testCustomerCannotSupplyOrderPrice(): void
    {
        [$orders, , , , ,$product]=$this->commerceFixture();$this->expectException(ValidationException::class);
        (new \Coleza\Application\Commerce\Orders\OrderApplicationService($orders))->createOrder(new \Coleza\Application\Commerce\Orders\CreateOrderCommand(2,[['product_id'=>$product,'unit_price_minor'=>1]]),2);
    }
    public function testAbsentPricingCannotSilentlyProduceFreeOrder(): void
    {
        $this->commerceFixture();$orders=new \Coleza\Domain\Commerce\Orders\OrderService($this->db);$this->expectException(ValidationException::class);
        $orders->createOrder(['user_id'=>2],[['product_id'=>1]]);
    }
    public function testNegativeInvoiceAmountIsRejected(): void
    {
        [, $invoices]=$this->commerceFixture();$this->expectException(ValidationException::class);$invoices->createInvoice(['user_id'=>2],[['description'=>'Invalid','unit_amount_minor'=>-10000]]);
    }
    public function testInconsistentInvoiceLineTotalIsRejected(): void
    {
        [, $invoices]=$this->commerceFixture();$this->expectException(ValidationException::class);$invoices->createInvoice(['user_id'=>2],[['description'=>'Invalid','unit_amount_minor'=>1000,'total_minor'=>1]]);
    }
    public function testOrderInvoiceTaxRoundingPreservesWholeAmount(): void
    {
        [$orders,$invoices, , ,$tax]=$this->commerceFixture();$zone=$tax->createTaxZone(['code'=>'TR','name'=>'Turkey','country_codes'=>['TR']]);$tax->createTaxRate(['tax_class_id'=>$tax->getDefaultTaxClass()->getId(),'tax_zone_id'=>$zone->getId(),'name'=>'VAT','rate_percent'=>20]);
        $order=$orders->createOrder(['user_id'=>2],[['product_id'=>1,'unit_price_minor'=>1],['product_id'=>1,'unit_price_minor'=>1],['product_id'=>1,'unit_price_minor'=>1]]);
        self::assertSame(1,$order->getTaxTotalMinor());self::assertSame($order->getTotalMinor(),$invoices->createInvoiceFromOrder($order)->getTotalMinor());
    }
    private function purchase(array $items): \Coleza\Domain\Commerce\Orders\Order
    {
        return (new \Coleza\Application\Commerce\Orders\OrderApplicationService(new \Coleza\Domain\Commerce\Orders\OrderService($this->db)))
            ->createOrder(new \Coleza\Application\Commerce\Orders\CreateOrderCommand(2,$items),2);
    }

    private function rejectPurchase(array $items): void
    {
        $before=$this->db->select('SELECT * FROM orders');
        try {$this->purchase($items);self::fail('Invalid purchase accepted');}
        catch (ValidationException) {self::assertSame($before,$this->db->select('SELECT * FROM orders'));}
    }

    public function testCatalogPriceAndTaxAreFrozenUntilInvoice(): void
    {
        [, $invoices, ,$pricing,$tax,$product]=$this->commerceFixture();
        $zone=$tax->createTaxZone(['code'=>'TR','name'=>'Turkey','country_codes'=>['TR']]);
        $tax->createTaxRate(['tax_class_id'=>$tax->getDefaultTaxClass()->getId(),'tax_zone_id'=>$zone->getId(),'name'=>'VAT','rate_percent'=>20]);
        $order=$this->purchase([['product_id'=>$product,'quantity'=>2]]);
        self::assertSame(20000,$order->getSubtotalMinor());self::assertSame(24000,$order->getTotalMinor());
        $pricing->setPricePoint(['target_id'=>$product,'currency_code'=>'TRY','cycle'=>'monthly','price_minor'=>99999]);
        $this->db->statement('UPDATE tax_rates SET rate_percent=99');
        $invoice=$invoices->createInvoiceFromOrder($order);
        self::assertSame(24000,$invoice->getTotalMinor());self::assertSame(4000,$invoice->getTaxTotalMinor());
        self::assertSame($invoice->getId(),$invoices->createInvoiceFromOrder($order)->getId());
        self::assertCount(1,$this->db->select('SELECT * FROM invoices'));
    }

    public function testEveryClientMoneyAliasAndSnapshotInjectionIsRejected(): void
    {
        [, , , , ,$product]=$this->commerceFixture();
        foreach(['price_minor','unit_price_minor','unit_setup_fee_minor','total_minor','tax_amount_minor','currency_code','product_name','metadata'] as $field){
            $this->rejectPurchase([['product_id'=>$product,$field=>1]]);
        }
    }

    public function testInvalidQuantitiesAndOverflowLeaveNoOrder(): void
    {
        [, $invoices, , , ,$product]=$this->commerceFixture();
        foreach([0,-1,1.5,'1.5',true,2147483647,'999999999999999999999999999'] as $qty){$this->rejectPurchase([['product_id'=>$product,'quantity'=>$qty]]);}
        foreach([['unit_amount_minor'=>2147483647,'quantity'=>2],['unit_amount_minor'=>1.5],['unit_amount_minor'=>1,'tax_amount_minor'=>2147483647]] as $line){
            try{$invoices->createInvoice(['user_id'=>2],[$line]);self::fail('Unrepresentable invoice accepted');}catch(ValidationException){self::assertCount(0,$this->db->select('SELECT * FROM invoices'));}
        }
    }

    public function testInactiveUnknownAndUnavailableProductsAreRejected(): void
    {
        [, ,$catalog, , ,$product]=$this->commerceFixture();
        $this->rejectPurchase([['product_id'=>999]]);
        $this->db->statement('UPDATE products SET is_active=0');$this->rejectPurchase([['product_id'=>$product]]);
        $this->db->statement('UPDATE products SET is_active=1');$this->db->statement('UPDATE product_groups SET is_active=0');$this->rejectPurchase([['product_id'=>$product]]);
        $this->db->statement('UPDATE product_groups SET is_active=1');
        $catalog->setProductAvailability($product,['stock_tracking_enabled'=>true,'stock_quantity'=>1]);
        $this->rejectPurchase([['product_id'=>$product],['product_id'=>$product]]);
        $this->db->statement('UPDATE price_points SET is_active=0');$this->rejectPurchase([['product_id'=>$product]]);
    }

    public function testRequiredOptionApplicabilityAndMissingSelectionPrice(): void
    {
        [, ,$catalog,$pricing, ,$product]=$this->commerceFixture();
        $parent=$catalog->createConfigurableOption(['product_id'=>$product,'name'=>'Disk','is_required'=>true]);
        $sub=$catalog->createConfigurableOptionSub(['option_id'=>$parent->getId(),'name'=>'Extra disk']);
        $this->rejectPurchase([['product_id'=>$product]]);
        $this->rejectPurchase([['product_id'=>$product,'option_sub_ids'=>[$sub->getId()]]]);
        $pricing->setPricePoint(['target_type'=>'option_sub','target_id'=>$sub->getId(),'currency_code'=>'TRY','cycle'=>'monthly','price_minor'=>500]);
        self::assertSame(10500,$this->purchase([['product_id'=>$product,'option_sub_ids'=>[$sub->getId()]]])->getTotalMinor());
        $this->rejectPurchase([['product_id'=>$product,'option_sub_ids'=>[$sub->getId(),$sub->getId()]]]);
        $this->db->statement('UPDATE configurable_option_subs SET is_active=0');$this->rejectPurchase([['product_id'=>$product,'option_sub_ids'=>[$sub->getId()]]]);
    }

    public function testOneTimeAddonIsChargedOnceAndCannotTargetAnotherProduct(): void
    {
        [, ,$catalog,$pricing, ,$product]=$this->commerceFixture();
        $addon=$catalog->createProductAddon(['name'=>'Install','applicable_product_ids'=>[$product]]);
        $pricing->setPricePoint(['target_type'=>'addon','target_id'=>$addon->getId(),'currency_code'=>'TRY','cycle'=>'one_time','price_minor'=>900]);
        $order=$this->purchase([['product_id'=>$product,'addon_ids'=>[$addon->getId()]]]);
        self::assertSame(10000,$order->getItems()[0]->getUnitPriceMinor());self::assertSame(900,$order->getItems()[0]->getUnitSetupFeeMinor());
        $this->db->statement('UPDATE product_addons SET applicable_products_json=?',['[999]']);
        $this->rejectPurchase([['product_id'=>$product,'addon_ids'=>[$addon->getId()]]]);
    }

    public function testOrganizationPriceAndTaxExemptionCannotLeakToSameNumberedPersonalUser(): void
    {
        [, , ,$pricing,$tax,$product]=$this->commerceFixture();
        $pricing->createPriceOverride(['scope'=>'customer','scope_id'=>2,'target_id'=>$product,'override_value'=>1]);
        $tax->setCustomerExemption(2,true,reason:'Organization exemption');
        $zone=$tax->createTaxZone(['code'=>'TR','name'=>'Turkey','country_codes'=>['TR']]);
        $tax->createTaxRate(['tax_class_id'=>$tax->getDefaultTaxClass()->getId(),'tax_zone_id'=>$zone->getId(),'name'=>'VAT','rate_percent'=>20]);
        self::assertSame(12000,$this->purchase([['product_id'=>$product]])->getTotalMinor());
        $pricing->createPriceOverride(['scope'=>'user','scope_id'=>2,'target_id'=>$product,'override_value'=>5000]);
        self::assertSame(6000,$this->purchase([['product_id'=>$product]])->getTotalMinor());
    }

    public function testCustomerCannotCreateManualInvoiceOrManualPriceOrder(): void
    {
        [$orders,$invoices]=$this->commerceFixture();
        try{(new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService($invoices))->createInvoice(new \Coleza\Application\Commerce\Invoices\CreateInvoiceCommand(2,items:[['unit_amount_minor'=>1]]),2);self::fail('Customer manual invoice accepted');}
        catch(\Coleza\Foundation\Exceptions\AuthorizationException){self::assertCount(0,$this->db->select('SELECT * FROM invoices'));}
        $this->expectException(\Coleza\Foundation\Exceptions\AuthorizationException::class);
        (new \Coleza\Application\Commerce\Orders\OrderApplicationService($orders))->createManualOrder(new \Coleza\Application\Commerce\Orders\CreateOrderCommand(2,[['unit_price_minor'=>1]]),'Override',2);
    }

    public function testExplicitStaffOverrideRequiresReasonAndActualPermission(): void
    {
        [$orders]=$this->commerceFixture();$rbac=new \Coleza\Domain\Identity\Rbac\RbacService($this->db);$rbac->ensureTables();$role=$rbac->findOrCreateRole('pricing');$rbac->grantPermission($role,'orders.price_override');$rbac->assignRole(2,'pricing');
        $app=new \Coleza\Application\Commerce\Orders\OrderApplicationService($orders);$command=new \Coleza\Application\Commerce\Orders\CreateOrderCommand(2,[['unit_price_minor'=>123]]);
        try{$app->createManualOrder($command,'',2);self::fail('Reason omitted');}catch(ValidationException){}
        self::assertSame(123,$app->createManualOrder($command,'Negotiated quote',2)->getTotalMinor());
        self::assertCount(1,$this->db->select("SELECT * FROM security_audit_logs WHERE event_type='command.manual_order_pricing'"));
    }

    public function testInclusiveAndMixedTaxDoesNotDoubleChargeOrLoseRemainder(): void
    {
        [$orders,$invoices, , ,$tax]=$this->commerceFixture();$zone=$tax->createTaxZone(['code'=>'TR','name'=>'Turkey','country_codes'=>['TR']]);$class=$tax->getDefaultTaxClass()->getId();
        $tax->createTaxRate(['tax_class_id'=>$class,'tax_zone_id'=>$zone->getId(),'name'=>'VAT','rate_percent'=>20,'calculation_type'=>'inclusive']);
        $tax->createTaxRate(['tax_class_id'=>$class,'tax_zone_id'=>$zone->getId(),'name'=>'Other','rate_percent'=>10,'calculation_type'=>'inclusive']);
        $tax->createTaxRate(['tax_class_id'=>$class,'tax_zone_id'=>$zone->getId(),'name'=>'Extra','rate_percent'=>5]);
        $order=$orders->createOrder(['user_id'=>2],[['unit_price_minor'=>65],['unit_price_minor'=>65]]);
        self::assertSame(100,$order->getSubtotalMinor());self::assertSame(35,$order->getTaxTotalMinor());self::assertSame(135,$invoices->createInvoiceFromOrder($order)->getTotalMinor());
        $this->expectException(ValidationException::class);$tax->calculateTax(-1,$class,'UNKNOWN');
    }

    public function testCustomerOrderInvoiceUsesPersistedSnapshotAndChecksOwnership(): void
    {
        [, $invoices, , , ,$product]=$this->commerceFixture();$order=$this->purchase([['product_id'=>$product]]);
        $app=new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService($invoices);
        self::assertSame(10000,$app->createForOrder($order->getId(),2)->getTotalMinor());
        $this->db->insert('users',['id'=>3,'email'=>'other@example.test','password_hash'=>'unused','name'=>'Other','is_active'=>1]);
        $this->expectException(\Coleza\Foundation\Exceptions\AuthorizationException::class);$app->createForOrder($order->getId(),3);
    }

    public function testChildFailureRollsBackOrderAndPreservesRawAndManagedParent(): void
    {
        [$orders]=$this->commerceFixture();
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_order_child BEFORE INSERT ON order_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='child failure'":"CREATE TRIGGER reject_order_child BEFORE INSERT ON order_items BEGIN SELECT RAISE(ABORT,'child failure'); END");
        foreach(['raw','managed'] as $mode){
            $operation=function()use($orders):void{try{$orders->createOrder(['user_id'=>2],[['unit_price_minor'=>100]]);self::fail('Child failure swallowed');}catch(\PDOException){self::assertTrue($this->db->getPdo()->inTransaction());self::assertCount(0,$this->db->select('SELECT * FROM orders'));}};
            if($mode==='raw'){$this->db->getPdo()->beginTransaction();try{$operation();}finally{$this->db->getPdo()->rollBack();}}else{$this->db->transaction($operation);}
        }
    }

    public function testPurchaseAuditFailureRollsBackAllRows(): void
    {
        [, , , , ,$product]=$this->commerceFixture();
        (new \Coleza\Domain\Identity\Rbac\RbacService($this->db))->ensureTables();(new \Coleza\Domain\Identity\Audit\AuditLogger($this->db))->ensureTable();
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_purchase_audit BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit failure'":"CREATE TRIGGER reject_purchase_audit BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT,'audit failure'); END");
        try{$this->purchase([['product_id'=>$product]]);self::fail('Audit failure swallowed');}catch(\PDOException){self::assertCount(0,$this->db->select('SELECT * FROM orders'));self::assertCount(0,$this->db->select('SELECT * FROM order_items'));}
    }
}
