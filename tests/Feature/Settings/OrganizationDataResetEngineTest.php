<?php

namespace Tests\Feature\Settings;

use App\Actions\Quotations\CreateQuotationAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\FinanceRecurringObligationTemplate;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\OrganizationDataReset\OrganizationDataResetException;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetOperation;
use App\Services\OrganizationDataReset\OrganizationDataResetOperationQuery;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\DocumentTestCase;

class OrganizationDataResetEngineTest extends DocumentTestCase
{
    public function test_sales_and_documents_reset_removes_the_complete_commercial_graph_only(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner, 'Commercial reset');
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $customer = $this->createCustomer($organization, 'Preserved customer');
        $product = $this->createProduct($organization, 'Preserved product');
        $account = $this->createFinancialAccount($organization);
        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $line = $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '100.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $payment = $this->recordPayment($owner, $order, $account, '100.0000');
        $allocationId = (int) DB::table('payment_allocations')->where('payment_id', $payment->id)->value('id');
        $invoice = $this->createInvoice($owner, $order);
        $deliveryNote = $this->createDeliveryNote($owner, $order);
        $quotation = app(CreateQuotationAction::class)->execute($owner, $organization, $store, [
            'currency_code' => 'MAD',
            'quotation_date' => now()->toDateString(),
        ]);

        $returnId = DB::table('customer_returns')->insertGetId([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'sales_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'return_number' => 'RET-RESET-001',
            'client_operation_id' => (string) Str::uuid(),
            'status' => 'draft',
            'disposition' => 'restock',
            'reason' => 'Reset integration fixture',
            'currency_code' => 'MAD',
            'subtotal_excl_tax' => 10,
            'discount_total' => 0,
            'tax_total' => 0,
            'total_incl_tax' => 10,
            'policy_snapshot' => json_encode([]),
            'created_by_user_id' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $returnLineId = DB::table('customer_return_lines')->insertGetId([
            'organization_id' => $organization->id,
            'customer_return_id' => $returnId,
            'sales_order_line_id' => $line->id,
            'product_variant_id' => null,
            'position' => 1,
            'product_name' => 'Returned service',
            'quantity' => 1,
            'unit_price_excl_tax' => 10,
            'unit_price_incl_tax' => 10,
            'subtotal_excl_tax' => 10,
            'discount_amount' => 0,
            'taxable_amount' => 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total_incl_tax' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $creditNoteId = DB::table('credit_notes')->insertGetId([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'customer_return_id' => $returnId,
            'invoice_id' => $invoice->id,
            'sales_order_id' => $order->id,
            'status' => 'draft',
            'credit_note_date' => now()->toDateString(),
            'currency_code' => 'MAD',
            'subtotal_excl_tax' => 10,
            'discount_total' => 0,
            'tax_total' => 0,
            'total_incl_tax' => 10,
            'reason' => 'Reset fixture',
            'seller_snapshot' => json_encode([]),
            'customer_snapshot' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('credit_note_lines')->insert([
            'organization_id' => $organization->id,
            'credit_note_id' => $creditNoteId,
            'customer_return_line_id' => $returnLineId,
            'position' => 1,
            'description' => 'Returned service',
            'quantity' => 1,
            'unit_price_excl_tax' => 10,
            'unit_price_incl_tax' => 10,
            'subtotal_excl_tax' => 10,
            'discount_amount' => 0,
            'taxable_amount' => 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total_incl_tax' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_refunds')->insert([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'payment_id' => $payment->id,
            'sales_order_id' => $order->id,
            'customer_return_id' => $returnId,
            'financial_account_id' => $account->id,
            'refund_number' => 'RF-RESET-001',
            'method' => 'cash',
            'status' => 'posted',
            'amount' => 10,
            'currency_code' => 'MAD',
            'refund_date' => now()->toDateString(),
            'reason' => 'Reset fixture',
            'client_operation_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('customer_exchanges')->insert([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'sales_order_id' => $order->id,
            'customer_return_id' => $returnId,
            'exchange_number' => 'EX-RESET-001',
            'client_operation_id' => (string) Str::uuid(),
            'operation_hash' => hash('sha256', 'reset-exchange-'.$order->id),
            'status' => 'awaiting_return_receipt',
            'settlement_status' => 'pending',
            'replacement_items' => json_encode([]),
            'returned_total' => 10,
            'new_items_total' => 10,
            'difference_amount' => 0,
            'created_by_user_id' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $collectionId = DB::table('finance_expected_collections')->insertGetId([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'sales_order_id' => $order->id,
            'expected_date' => now()->toDateString(),
            'expected_amount' => 100,
            'currency_code' => 'MAD',
            'state' => 'planned',
            'created_by_user_id' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('finance_expected_collection_matches')->insert([
            'organization_id' => $organization->id,
            'expected_collection_id' => $collectionId,
            'payment_allocation_id' => $allocationId,
            'amount' => 100,
            'matched_by_user_id' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commission_entries')->insert([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'salesperson_id' => $owner->id,
            'sales_order_id' => $order->id,
            'sales_order_line_id' => $line->id,
            'entry_type' => 'sale',
            'status' => 'pending',
            'source_key' => 'reset-commission-'.$order->id,
            'quantity_snapshot' => 1,
            'revenue_ht_snapshot' => 100,
            'cost_total_snapshot' => 0,
            'margin_amount_snapshot' => 100,
            'margin_rate_snapshot' => 100,
            'commission_rate_snapshot' => 5,
            'commission_amount' => 5,
            'commission_rule_set_name_snapshot' => 'Fixture',
            'sale_date' => now()->toDateString(),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequenceBefore = DB::table('sales_order_sequences')->where('organization_id', $organization->id)->value('next_number');
        $plan = app(OrganizationDataResetPreviewService::class)->preview($organization, OrganizationDataResetScope::SalesAndDocuments);
        $this->assertTrue($plan->isExecutable(), json_encode($plan->blockers));
        $this->assertSame(1, $plan->counts['sales_and_documents.payment_refunds']);
        $this->assertSame(1, $plan->counts['sales_and_documents.finance_expected_collection_matches']);
        app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);

        foreach (['sales_orders', 'quotations', 'invoices', 'credit_notes', 'payments', 'payment_refunds', 'delivery_notes', 'customer_returns', 'customer_exchanges', 'commission_entries', 'finance_expected_collections'] as $table) {
            $this->assertSame(0, DB::table($table)->where('organization_id', $organization->id)->count(), $table.' was not reset.');
        }
        $this->assertDatabaseHas('products', ['id' => $product->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('financial_accounts', ['id' => $account->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('stores', ['id' => $store->id, 'organization_id' => $organization->id]);
        $this->assertSame($sequenceBefore, DB::table('sales_order_sequences')->where('organization_id', $organization->id)->value('next_number'));
        $this->assertDatabaseMissing('quotations', ['id' => $quotation->id]);
        $this->assertDatabaseMissing('delivery_notes', ['id' => $deliveryNote->id]);
    }

    public function test_transactional_finance_reset_blocks_sales_allocations_and_preserves_definitions(): void
    {
        [$owner, $organization, , $order, $account] = $this->paymentFixture();
        $this->recordPayment($owner, $order, $account, '25.0000');

        $blocked = app(OrganizationDataResetPreviewService::class)->preview($organization, OrganizationDataResetScope::TransactionalFinance);
        $this->assertFalse($blocked->isExecutable());
        $this->assertContains('finance.sales_dependencies', array_column($blocked->blockers, 'code'));

        DB::table('payment_allocations')->where('organization_id', $organization->id)->delete();
        DB::table('payments')->where('organization_id', $organization->id)->delete();
        DB::table('financial_account_opening_positions')->insert([
            'organization_id' => $organization->id,
            'financial_account_id' => $account->id,
            'effective_date' => now()->toDateString(),
            'amount' => 250,
            'currency_code' => 'MAD',
            'created_by_user_id' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $template = $this->recurringTemplateWithoutSupplier($organization, $owner);
        $ruleSetId = DB::table('commission_rule_sets')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Preserved commission rules',
            'status' => 'draft',
            'created_by_user_id' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commission_rule_tiers')->insert([
            'organization_id' => $organization->id,
            'commission_rule_set_id' => $ruleSetId,
            'commission_rate' => 5,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sequences = app(\App\Services\OrganizationDataReset\OrganizationDataResetSequenceGuard::class)->snapshot($organization);

        $plan = app(OrganizationDataResetPreviewService::class)->preview($organization, OrganizationDataResetScope::TransactionalFinance);
        $this->assertTrue($plan->isExecutable(), json_encode($plan->blockers));
        app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);

        $this->assertDatabaseMissing('financial_account_opening_positions', ['organization_id' => $organization->id]);
        $this->assertDatabaseHas('financial_accounts', ['id' => $account->id]);
        $this->assertDatabaseHas('finance_recurring_obligation_templates', ['id' => $template->id]);
        $this->assertDatabaseHas('commission_rule_sets', ['id' => $ruleSetId]);
        $this->assertDatabaseHas('commission_rule_tiers', ['commission_rule_set_id' => $ruleSetId]);
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id]);
        $this->assertSame($sequences, app(\App\Services\OrganizationDataReset\OrganizationDataResetSequenceGuard::class)->snapshot($organization));
    }

    public function test_catalog_reset_blocks_commercial_references_then_removes_inventory_and_preserves_infrastructure(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $unit = $this->createUnit($organization);
        $tax = $this->createTaxRate($organization);
        $product = $this->createProduct($organization, 'Stock item', attributes: ['unit_id' => $unit->id, 'tax_rate_id' => $tax->id]);
        $variant = $product->variants->firstOrFail();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '8.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);

        $blocked = app(OrganizationDataResetPreviewService::class)->preview($organization, OrganizationDataResetScope::CatalogAndStock);
        $this->assertFalse($blocked->isExecutable());
        $this->assertContains('catalog.sales_dependencies', array_column($blocked->blockers, 'code'));

        // Remove the deliberately-created cross-domain stock allocation so the
        // companion Sales reset can clear the commercial reference first.
        DB::table('sales_order_inventory_allocations')->where('organization_id', $organization->id)->delete();
        $salesPlan = app(OrganizationDataResetPreviewService::class)->preview($organization, OrganizationDataResetScope::SalesAndDocuments);
        $this->assertTrue($salesPlan->isExecutable(), json_encode($salesPlan->blockers));
        app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $salesPlan);
        $integrationId = DB::table('woocommerce_integrations')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Preserved integration',
            'store_url' => 'https://shop.example.test',
            'consumer_key' => 'ck_fixture',
            'consumer_secret' => encrypt('secret'),
            'default_warehouse_id' => $warehouse->id,
            'default_store_id' => $store->id,
            'sync_stock' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = app(OrganizationDataResetPreviewService::class)->preview($organization, OrganizationDataResetScope::CatalogAndStock);
        $this->assertTrue($plan->isExecutable(), json_encode($plan->blockers));
        app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('inventory_balances', ['organization_id' => $organization->id]);
        $this->assertDatabaseMissing('inventory_movements', ['organization_id' => $organization->id]);
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
        $this->assertDatabaseHas('units_of_measure', ['id' => $unit->id]);
        $this->assertDatabaseHas('tax_rates', ['id' => $tax->id]);
        $this->assertDatabaseHas('woocommerce_integrations', ['id' => $integrationId]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
    public function test_preview_counts_are_exact_and_tenant_scoped(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Tenant A');
        $organizationB = $this->createOrganization($ownerB, 'Tenant B');
        $customerA = $this->customer($organizationA, 'Customer A');
        $this->customer($organizationB, 'Customer B');
        $this->contact($organizationA, $customerA);

        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organizationA, OrganizationDataResetScope::Customers);

        $this->assertTrue($plan->isExecutable());
        $this->assertSame(1, $plan->counts['customers.delete']);
        $this->assertSame(1, $plan->counts['contacts.unlink_customer']);
        $this->assertSame(2, $plan->totalAffected());

        app(OrganizationDataResetExecutor::class)->execute($organizationA, $ownerA, $plan);

        $this->assertDatabaseMissing('customers', ['organization_id' => $organizationA->id]);
        $this->assertDatabaseHas('customers', ['organization_id' => $organizationB->id, 'display_name' => 'Customer B']);
        $this->assertDatabaseHas('organization_contacts', [
            'organization_id' => $organizationA->id,
            'customer_id' => null,
        ]);
    }

    public function test_cross_domain_dependency_is_reported_instead_of_silently_expanding_scope(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $customer = $this->customer($organization, 'Referenced customer');
        $this->order($organization, $store->id, $customer->id);

        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::Customers);

        $this->assertFalse($plan->isExecutable());
        $this->assertContains('customers.sales_dependencies', array_column($plan->blockers, 'code'));
        $this->assertSame([OrganizationDataResetScope::Customers->value], $plan->scopes);
    }

    public function test_dependency_graph_has_deterministic_child_before_parent_order(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::FullOrganization);
        $keys = array_column($plan->operations, 'key');
        $this->assertLessThan(array_search('sales_and_documents.credit_notes', $keys, true), array_search('sales_and_documents.credit_note_lines', $keys, true));
        $this->assertLessThan(array_search('sales_and_documents.invoices', $keys, true), array_search('sales_and_documents.invoice_lines', $keys, true));
        $this->assertLessThan(array_search('sales_and_documents.sales_orders', $keys, true), array_search('sales_and_documents.sales_order_lines', $keys, true));
        $this->assertLessThan(array_search('catalog_and_stock.products', $keys, true), array_search('catalog_and_stock.product_variants', $keys, true));
        $this->assertLessThan(
            array_search('sales_and_documents.sales_order_revisions', $keys, true),
            array_search('sales_orders.unlink_current_revision', $keys, true),
        );
    }

    public function test_users_access_reset_preserves_owner_password_two_factor_security_and_numbering(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $member = User::factory()->create();
        $this->addOrganizationMember($organization, $member, [], roleName: 'Temporary role');
        $this->addStoreMember($store, $member);
        $this->activate($member, $organization, $store);

        $owner->two_factor_secret = 'owner-2fa-secret';
        $owner->two_factor_recovery_codes = ['one', 'two'];
        $owner->two_factor_confirmed_at = now();
        $owner->save();
        $ownerRaw = $owner->fresh()->getRawOriginal();
        $security = $organization->securitySetting()->firstOrFail()->getRawOriginal();
        DB::table('sessions')->insert([
            'id' => 'owner-reset-session',
            'user_id' => $owner->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Reset integration test',
            'payload' => 'fixture',
            'last_activity' => time(),
        ]);
        $trustedDeviceId = (string) Str::uuid();
        DB::table('trusted_two_factor_devices')->insert([
            'id' => $trustedDeviceId,
            'user_id' => $owner->id,
            'token_hash' => hash('sha256', 'owner-reset-device'),
            'device_name' => 'Owner device',
            'expires_at' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sales_order_sequences')->insert([
            'organization_id' => $organization->id,
            'next_number' => 87,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::UsersAndAccess);
        app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);

        $ownerAfter = $owner->fresh()->getRawOriginal();
        foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
            $this->assertSame($ownerRaw[$column], $ownerAfter[$column]);
        }
        $this->assertSame($security, $organization->securitySetting()->firstOrFail()->getRawOriginal());
        $this->assertDatabaseHas('organization_memberships', ['organization_id' => $organization->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('store_memberships', ['organization_id' => $organization->id, 'user_id' => $owner->id]);
        $this->assertDatabaseMissing('organization_memberships', ['organization_id' => $organization->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('users', ['id' => $member->id, 'active_organization_id' => null, 'active_store_id' => null]);
        $this->assertDatabaseHas('sessions', ['id' => 'owner-reset-session', 'user_id' => $owner->id]);
        $this->assertDatabaseHas('trusted_two_factor_devices', ['id' => $trustedDeviceId, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('sales_order_sequences', ['organization_id' => $organization->id, 'next_number' => 87]);
    }

    public function test_failure_after_mutation_rolls_back_every_operation(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $customer = $this->customer($organization, 'Rollback customer');
        $contact = $this->contact($organization, $customer);
        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::Customers);

        $realQueries = new OrganizationDataResetOperationQuery;
        $calls = 0;
        $mock = Mockery::mock(OrganizationDataResetOperationQuery::class);
        $mock->shouldReceive('count')->andReturnUsing(
            fn (Organization $org, OrganizationDataResetOperation $operation) => $realQueries->count($org, $operation),
        );
        $mock->shouldReceive('stateFingerprint')->andReturnUsing(
            fn (Organization $org, OrganizationDataResetOperation $operation) => $realQueries->stateFingerprint($org, $operation),
        );
        $mock->shouldReceive('execute')->andReturnUsing(
            function (Organization $org, OrganizationDataResetOperation $operation) use ($realQueries, &$calls): int {
                $calls++;
                if ($calls === 2) {
                    throw new OrganizationDataResetException('Injected reset failure.');
                }

                return $realQueries->execute($org, $operation);
            },
        );
        $this->app->instance(OrganizationDataResetOperationQuery::class, $mock);

        try {
            $this->app->make(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);
            $this->fail('The injected reset failure was not thrown.');
        } catch (OrganizationDataResetException $exception) {
            $this->assertSame('Injected reset failure.', $exception->getMessage());
        }

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('organization_contacts', ['id' => $contact->id, 'customer_id' => $customer->id]);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'event' => 'organization.data_reset.completed']);
    }

    public function test_full_reset_preserves_infrastructure_credentials_audit_and_has_no_orphans(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $customer = $this->customer($organization, 'Disposable customer');
        $order = $this->order($organization, $store->id, $customer->id);
        $contact = $this->contact($organization, $customer);
        $product = $this->createProduct($organization, 'Disposable product');
        $this->openStock($owner, $organization, $warehouse, $product->variants->firstOrFail(), '4.0000');
        $account = $this->createFinancialAccount($organization);
        $mail = $this->configureOrganizationMail($organization);
        $integrationId = DB::table('woocommerce_integrations')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Protected WooCommerce',
            'store_url' => 'https://shop.example.test',
            'consumer_key' => 'ck_protected',
            'consumer_secret' => encrypt('protected-secret'),
            'default_warehouse_id' => $warehouse->id,
            'default_store_id' => $store->id,
            'sync_stock' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_sequences')->insert([
            'organization_id' => $organization->id,
            'year' => 2026,
            'next_number' => 932,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $auditCount = DB::table('audit_logs')->where('organization_id', $organization->id)->count();
        $storeCount = DB::table('stores')->where('organization_id', $organization->id)->count();

        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::FullOrganization);
        $this->assertTrue($plan->isExecutable());
        $result = app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);

        $this->assertSame(OrganizationDataResetScope::FullOrganization->value, $result->scope);
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'owner_id' => $owner->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertSame($storeCount, DB::table('stores')->where('organization_id', $organization->id)->count());
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('sales_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('inventory_balances', ['organization_id' => $organization->id]);
        $this->assertDatabaseHas('organization_contacts', ['id' => $contact->id, 'customer_id' => null]);
        $this->assertDatabaseHas('financial_accounts', ['id' => $account->id]);
        $this->assertDatabaseHas('organization_mail_settings', ['id' => $mail->id]);
        $this->assertDatabaseHas('woocommerce_integrations', ['id' => $integrationId]);
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
        $this->assertDatabaseHas('invoice_sequences', ['organization_id' => $organization->id, 'year' => 2026, 'next_number' => 932]);
        $this->assertSame($auditCount + 1, DB::table('audit_logs')->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('audit_logs', ['id' => $result->auditLogId, 'event' => 'organization.data_reset.completed']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertDatabaseHas('store_memberships', ['organization_id' => $organization->id, 'store_id' => $store->id, 'user_id' => $owner->id]);
        $this->activate($owner, $organization, $store);
        $this->actingAs($owner)->get('/platform')->assertOk();
    }

    public function test_stale_preview_is_rejected_before_mutation(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->customer($organization, 'First customer');
        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::Customers);
        $this->customer($organization, 'Late customer');

        $this->expectException(OrganizationDataResetException::class);
        $this->expectExceptionMessage('preview is stale');
        app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);
    }

    private function customer(Organization $organization, string $name): Customer
    {
        $customer = new Customer;
        $customer->organization_id = $organization->id;
        $customer->type = 'individual';
        $customer->display_name = $name;
        $customer->status = 'active';
        $customer->save();

        return $customer;
    }

    private function contact(Organization $organization, Customer $customer): OrganizationContact
    {
        $contact = new OrganizationContact;
        $contact->organization_id = $organization->id;
        $contact->full_name = 'Contact '.$customer->display_name;
        $contact->contact_type = 'client';
        $contact->customer_id = $customer->id;
        $contact->active = true;
        $contact->save();

        return $contact;
    }

    private function recurringTemplateWithoutSupplier(Organization $organization, User $owner): FinanceRecurringObligationTemplate
    {
        $template = new FinanceRecurringObligationTemplate;
        $template->organization_id = $organization->id;
        $template->template_number = 'REC-RESET-'.Str::upper(Str::random(6));
        $template->category = 'rent';
        $template->label = 'Preserved recurring definition';
        $template->planned_amount = '500.0000';
        $template->currency_code = 'MAD';
        $template->due_day_mode = 'fixed';
        $template->due_day = 15;
        $template->start_date = '2026-10-01';
        $template->active = true;
        $template->created_by_user_id = $owner->id;
        $template->save();

        return $template;
    }

    private function order(Organization $organization, int $storeId, int $customerId): SalesOrder
    {
        $order = new SalesOrder;
        $order->organization_id = $organization->id;
        $order->store_id = $storeId;
        $order->customer_id = $customerId;
        $order->order_number = 'SO-RESET-'.uniqid();
        $order->status = 'confirmed';
        $order->currency_code = 'MAD';
        $order->ordered_at = now();
        $order->sale_date = now()->toDateString();
        $order->save();

        return $order;
    }
}
