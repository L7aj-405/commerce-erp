<?php

namespace Database\Seeders\Development;

use App\Actions\Inventory\OpeningStockAction;
use App\Actions\Inventory\PlanShowroomReplenishmentAction;
use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Actions\Procurement\ReceiveProcurementAction;
use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\CreateQuotationAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Quotations\DecideQuotationAction;
use App\Actions\Quotations\IssueQuotationAction;
use App\Actions\Quotations\RecordQuotationSupplierAvailabilityAction;
use App\Actions\Quotations\SaveQuotationLineAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderSource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentOverride;
use App\Models\WarehouseReplenishmentSetting;
use App\Services\OrganizationCreator;
use App\Services\ShowroomReplenishmentPlanner;
use App\Services\StoreCreator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * LOCAL VERIFICATION DATA ONLY — never called by DatabaseSeeder.
 *
 * Builds a realistic, repeatable ERP world through the canonical Actions (the
 * same code paths web / POS / MCP use), then asserts the Shure BETA 58A
 * customer-priority scenario. Refuses to run outside a local database.
 *
 *   DB_DATABASE=commerce_erp_dev php artisan migrate:fresh
 *   DB_DATABASE=commerce_erp_dev php artisan db:seed --class="Database\Seeders\Development\LocalVerificationSeeder"
 *
 * Logins (password "password"): owner@erp.local, admin@erp.local, vendeuse@erp.local
 */
class LocalVerificationSeeder extends Seeder
{
    public const ORGANIZATION_NAME = '10xScale Démo (local)';

    private User $owner;

    private Organization $org;

    private Store $store;

    private Warehouse $showroom;

    private Warehouse $depot;

    private TaxRate $tva;

    /** @var array<string, ProductVariant> */
    private array $v = [];

    public function run(): void
    {
        $this->guard();

        // ── Organization, users, store, warehouses ─────────────────────────
        $this->owner = $this->user('Propriétaire Démo', 'owner@erp.local');
        $this->org = app(OrganizationCreator::class)->create($this->owner, self::ORGANIZATION_NAME);
        $this->store = app(StoreCreator::class)->create($this->owner, $this->org, 'Showroom Casablanca', 'CASA');
        $this->activate($this->owner);

        $this->showroom = $this->warehouse('Showroom Casablanca', 'SHOW', storeLinked: true);
        $this->depot = $this->warehouse('Dépôt central (SP)', 'DEPOT');
        $this->store->default_warehouse_id = $this->showroom->id;
        $this->store->save();

        $this->member($this->user('Responsable Admin', 'admin@erp.local'), 'admin');
        $seller = $this->member($this->user('Vendeuse Showroom', 'vendeuse@erp.local'), 'sales-employee');

        // ── Catalogue: products / variants with varied stock ───────────────
        $this->tva = $this->taxRate();
        $shure = $this->brand('Shure');
        $senn = $this->brand('Sennheiser');
        $mackie = $this->brand('Mackie');
        $cordial = $this->brand('Cordial');
        $mics = $this->category('Microphones');
        $mixers = $this->category('Consoles de mixage');
        $cables = $this->category('Câbles');

        //                key            name                     sku            brand    category  price     cost    showroom depot
        $this->product('beta58a', 'Shure BETA 58A', 'SHU-BETA58A', $shure, $mics, '1650.0000', '980.0000', '1', '4');
        $this->product('sm58', 'Shure SM58', 'SHU-SM58', $shure, $mics, '1190.0000', '690.0000', '6', '10');
        $this->product('e835', 'Sennheiser e835', 'SEN-E835', $senn, $mics, '990.0000', '560.0000', '0', '8');
        $this->product('profx10', 'Mackie ProFX10v3', 'MAC-PFX10', $mackie, $mixers, '3490.0000', '2150.0000', '2', '3');
        $this->product('akg214', 'AKG C214', 'AKG-C214', null, $mics, '3990.0000', '2600.0000', '0', '0');
        $xlr = $this->product('xlr3', 'Câble XLR Cordial', 'COR-XLR-3M', $cordial, $cables, '120.0000', '55.0000', '12', '40', '3 m');
        $this->variant($xlr->product, 'xlr6', 'COR-XLR-6M', '6 m', '160.0000', '75.0000', '3', '25');
        $this->variant($xlr->product, 'xlr10', 'COR-XLR-10M', '10 m', '220.0000', '105.0000', '0', '0');

        // ── Minimum showroom stock rules ───────────────────────────────────
        $setting = new WarehouseReplenishmentSetting;
        $setting->organization_id = $this->org->id;
        $setting->warehouse_id = $this->showroom->id;
        $setting->auto_replenish = true;
        $setting->default_minimum_quantity = '0.0000';
        $setting->save();
        foreach (['beta58a' => '2', 'sm58' => '4', 'e835' => '3', 'profx10' => '1', 'xlr3' => '10', 'xlr6' => '5'] as $key => $min) {
            $override = new WarehouseReplenishmentOverride;
            $override->organization_id = $this->org->id;
            $override->warehouse_id = $this->showroom->id;
            $override->product_variant_id = $this->v[$key]->id;
            $override->minimum_quantity = $min.'.0000';
            $override->save();
        }

        // ── Customers & suppliers ──────────────────────────────────────────
        $karim = $this->customer('Karim Benali', 'karim.benali@example.test', '+212600000001');
        $studio = $this->customer('Studio Atlas SARL', 'contact@studio-atlas.example.test', '+212522000002', 'company');
        $events = $this->customer('Events Maroc', 'achats@events-maroc.example.test', '+212522000003', 'company');
        $mifa = $this->supplier('MIFA Distribution', 'commandes@mifa.example.test');
        $this->supplier('Audio Pro Import', 'pro@audiopro.example.test');

        // ── General replenishment (minimum sweep) ──────────────────────────
        // BETA 58A: showroom 1, minimum 2 → 1 unit requested from the depot.
        app(PlanShowroomReplenishmentAction::class)->execute($this->owner, $this->org, $this->showroom);
        $generalBeta = $this->activeQty('beta58a', generalOnly: true);
        $this->expect($generalBeta === 1.0, "General replenishment for BETA 58A must be 1 unit, got {$generalBeta}.");

        // ── Confirmed customer orders with reservations (non-POS) ──────────
        $order = $this->draftOrder($studio, SalesOrderSource::Manual, $seller);
        $this->line($order, 'sm58', $this->showroom, '2');
        $this->line($order, 'xlr3', $this->showroom, '4');
        app(ConfirmSalesOrderAction::class)->execute($seller, $order->fresh());

        $order = $this->draftOrder($events, SalesOrderSource::Manual);
        $this->line($order, 'profx10', $this->depot, '2');
        $this->line($order, 'xlr6', $this->depot, '10');
        app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh());

        // ── Shure BETA 58A scenario: customer-linked transfer request ──────
        // POS pickup at the showroom, 3 units: 1 from showroom + 2 from depot.
        $pos = $this->draftOrder($karim, SalesOrderSource::Pos);
        $pos->pos_warehouse_id = $this->showroom->id;
        $pos->pos_global_discount_type = 'none';
        $pos->pos_global_discount_value = '0.0000';
        $pos->pos_fulfillment_mode = 'pickup';
        $pos->pos_shipping_fee = '0.0000';
        $pos->save();
        $this->line($pos, 'beta58a', $this->showroom, '3');
        $pos = app(ConfirmSalesOrderAction::class)->execute($this->owner, $pos->fresh());
        $this->assertShureScenario($pos);

        // ── Procurement: quotation → supplier → PO → partial receipt ───────
        $this->procurementFlow($studio, $mifa);

        $this->command?->info('Local verification data seeded into '.DB::connection()->getDatabaseName().'.');
        $this->command?->line('  Logins (password "password"): owner@erp.local, admin@erp.local, vendeuse@erp.local');
        $this->command?->line('  Shure BETA 58A request: '.$this->orderRequest($pos)->request_number.' → 2 customer + 2 replenishment (4 from depot, no duplicate).');
    }

    private function guard(): void
    {
        $connection = DB::connection();
        $host = (string) $connection->getConfig('host');
        $database = $connection->getDatabaseName();

        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalVerificationSeeder only runs with APP_ENV=local or testing.');
        }
        if ($connection->getDriverName() !== 'sqlite' && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException("LocalVerificationSeeder refuses non-local database host [{$host}].");
        }
        if (Organization::query()->where('name', self::ORGANIZATION_NAME)->exists()) {
            throw new RuntimeException("[{$database}] is already seeded. Re-create it with migrate:fresh on the local database first.");
        }
    }

    private function assertShureScenario(SalesOrder $order): void
    {
        $request = $this->orderRequest($order);
        $byReason = $request->lines->where('product_variant_id', $this->v['beta58a']->id)
            ->mapWithKeys(fn ($l) => [$l->reason->value => $l->quantity])->sortKeys()->all();

        $this->expect($byReason === ['minimum_replenishment' => '2.0000', 'order_fulfillment' => '2.0000'],
            'BETA 58A customer request must be 2 customer + 2 replenishment, got '.json_encode($byReason).'.');
        $this->expect($this->activeQty('beta58a') === 4.0, 'Total BETA 58A to move from the depot must be 4 (no duplicate commitment).');
        $this->expect($this->activeQty('beta58a', generalOnly: true) === 0.0, 'The general BETA 58A unit must have been moved, not duplicated.');
        $shortfalls = app(ShowroomReplenishmentPlanner::class)->promiseShortfalls($this->org->id, $this->depot->id, [$this->v['beta58a']->id]);
        $this->expect($shortfalls === [], 'The depot must not over-promise BETA 58A: '.json_encode($shortfalls));
    }

    private function procurementFlow(Customer $customer, Supplier $supplier): void
    {
        $quotation = app(CreateQuotationAction::class)->execute($this->owner, $this->org, $this->store, [
            'customer_id' => $customer->id, 'currency_code' => 'MAD', 'quotation_date' => now()->toDateString(),
        ]);
        $this->quotationLine($quotation, 'sm58', '1');
        $line = $this->quotationLine($quotation, 'akg214', '3');
        $request = app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation->fresh(), $line, ['supplier_id' => $supplier->id]);
        app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation->fresh(), $request, ['outcome' => 'available', 'confirmed_quantity' => '3']);
        $issued = app(IssueQuotationAction::class)->execute($this->owner, $quotation->fresh(), false)->fresh(['lines']);
        $accepted = app(DecideQuotationAction::class)->accept($this->owner, $issued)->fresh(['lines']);
        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($this->owner, $accepted, ['warehouse_id' => $this->showroom->id])['order'];

        $requirement = SalesOrderProcurement::query()->where('sales_order_id', $order->id)->sole();
        $purchaseOrder = app(CreatePurchaseOrderFromProcurementRequirementsAction::class)
            ->execute($this->owner, $this->org, $supplier, [$requirement->id], ['expected_delivery_date' => now()->addDays(7)->toDateString()]);

        // Partial supplier receipt (1 of 3) → purchase order `partially_received`.
        app(ReceiveProcurementAction::class)->execute($this->owner, $requirement->fresh(), $this->showroom, [
            'quantity' => '1', 'purchase_order_line_id' => $purchaseOrder->lines->sole()->id, 'supplier_delivery_reference' => 'BL-MIFA-0001',
        ]);
        $this->expect($purchaseOrder->fresh()->status === PurchaseOrderStatus::PartiallyReceived, 'Purchase order must be partially received.');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function expect(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException('Seed verification failed: '.$message);
        }
    }

    private function orderRequest(SalesOrder $order): TransferRequest
    {
        return TransferRequest::query()->where('sales_order_id', $order->id)
            ->where('source_warehouse_id', $this->depot->id)->with('lines')->firstOrFail();
    }

    private function activeQty(string $key, bool $generalOnly = false): float
    {
        return (float) TransferRequest::query()->where('organization_id', $this->org->id)
            ->where('source_warehouse_id', $this->depot->id)
            ->whereIn('status', ShowroomReplenishmentPlanner::ACTIVE_STATUSES)
            ->when($generalOnly, fn ($q) => $q->whereNull('sales_order_id'))
            ->with('lines')->get()
            ->flatMap->lines->where('product_variant_id', $this->v[$key]->id)->sum(fn ($l) => (float) $l->quantity);
    }

    private function user(string $name, string $email): User
    {
        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = Hash::make('password');
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function member(User $user, string $roleSlug): User
    {
        $membership = new OrganizationMembership;
        $membership->organization_id = $this->org->id;
        $membership->user_id = $user->id;
        $membership->role_id = $this->org->roles()->where('slug', $roleSlug)->firstOrFail()->id;
        $membership->status = 'active';
        $membership->save();

        $storeMembership = new StoreMembership;
        $storeMembership->organization_id = $this->org->id;
        $storeMembership->store_id = $this->store->id;
        $storeMembership->user_id = $user->id;
        $storeMembership->save();

        $this->activate($user);

        return $user;
    }

    private function activate(User $user): void
    {
        $user->active_organization_id = $this->org->id;
        $user->active_store_id = $this->store->id;
        $user->save();
    }

    private function warehouse(string $name, string $code, bool $storeLinked = false): Warehouse
    {
        $warehouse = new Warehouse;
        $warehouse->organization_id = $this->org->id;
        $warehouse->store_id = $storeLinked ? $this->store->id : null;
        $warehouse->allow_direct_sales = $storeLinked;
        $warehouse->name = $name;
        $warehouse->code = $code;
        $warehouse->description = null;
        $warehouse->status = 'active';
        $warehouse->save();

        return $warehouse;
    }

    private function taxRate(): TaxRate
    {
        $tax = new TaxRate;
        $tax->organization_id = $this->org->id;
        $tax->name = 'TVA 20 %';
        $tax->rate = '20.0000';
        $tax->status = 'active';
        $tax->save();

        return $tax;
    }

    private function brand(string $name): Brand
    {
        $brand = new Brand;
        $brand->organization_id = $this->org->id;
        $brand->name = $name;
        $brand->slug = Str::slug($name);
        $brand->status = 'active';
        $brand->save();

        return $brand;
    }

    private function category(string $name): Category
    {
        $category = new Category;
        $category->organization_id = $this->org->id;
        $category->name = $name;
        $category->slug = Str::slug($name);
        $category->status = 'active';
        $category->save();

        return $category;
    }

    private function product(string $key, string $name, string $sku, ?Brand $brand, Category $category, string $price, string $cost, string $showroomQty, string $depotQty, ?string $label = null): ProductVariant
    {
        $product = new Product;
        $product->organization_id = $this->org->id;
        $product->name = $name;
        $product->brand_id = $brand?->id;
        $product->default_category_id = $category->id;
        $product->status = 'active';
        $product->save();

        return $this->variant($product, $key, $sku, $label, $price, $cost, $showroomQty, $depotQty);
    }

    private function variant(Product $product, string $key, string $sku, ?string $label, string $price, string $cost, string $showroomQty, string $depotQty): ProductVariant
    {
        $variant = new ProductVariant;
        $variant->organization_id = $this->org->id;
        $variant->product_id = $product->id;
        $variant->label = $label;
        $variant->sku = $sku;
        $variant->purchase_price = $cost;
        $variant->default_sale_price = $price;
        $variant->regular_sale_price = $price;
        $variant->unit_price_ht = $price;
        $variant->tax_rate_id = $this->tva->id;
        $variant->status = 'active';
        $variant->save();

        foreach ([[$this->showroom, $showroomQty], [$this->depot, $depotQty]] as [$warehouse, $qty]) {
            if ((float) $qty > 0) {
                app(OpeningStockAction::class)->execute($this->owner, $this->org, $warehouse, $variant, $qty.'.0000', 'Stock initial (données locales)');
            }
        }

        return $this->v[$key] = $variant->setRelation('product', $product);
    }

    private function customer(string $name, string $email, string $phone, string $type = 'individual'): Customer
    {
        $customer = new Customer;
        $customer->organization_id = $this->org->id;
        $customer->type = $type;
        $customer->display_name = $name;
        $customer->company_name = $type === 'company' ? $name : null;
        $customer->email = $email;
        $customer->phone = $phone;
        $customer->status = 'active';
        $customer->save();

        return $customer;
    }

    private function supplier(string $name, string $email): Supplier
    {
        $supplier = new Supplier;
        $supplier->organization_id = $this->org->id;
        $supplier->name = $name;
        $supplier->email = $email;
        $supplier->active = true;
        $supplier->save();

        return $supplier;
    }

    private function draftOrder(Customer $customer, SalesOrderSource $source, ?User $actor = null): SalesOrder
    {
        return app(CreateSalesOrderAction::class)->execute($actor ?? $this->owner, $this->org, $this->store, [
            'customer_id' => $customer->id, 'sale_date' => now()->toDateString(), 'currency_code' => 'MAD', 'notes' => null,
        ], $source);
    }

    private function line(SalesOrder $order, string $key, Warehouse $warehouse, string $qty): void
    {
        app(SaveSalesOrderLineAction::class)->execute($this->owner, $order->fresh(), [
            'line_type' => 'catalog', 'product_variant_id' => $this->v[$key]->id, 'warehouse_id' => $warehouse->id,
            'quantity' => $qty.'.0000', 'discount_type' => 'none', 'discount_value' => '0.0000',
        ]);
    }

    private function quotationLine($quotation, string $key, string $qty)
    {
        return app(SaveQuotationLineAction::class)->execute($this->owner, $quotation, [
            'line_type' => 'catalog', 'product_variant_id' => $this->v[$key]->id, 'quantity' => $qty, 'discount_type' => 'none', 'discount_value' => '0',
        ]);
    }
}
