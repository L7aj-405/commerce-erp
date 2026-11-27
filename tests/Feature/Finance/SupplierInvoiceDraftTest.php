<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SupplierInvoiceTestCase;

/** Phase 2 Sprint 1 (PR5): supplier invoice DRAFT lifecycle over HTTP (create, update, idempotency, duplicates, access). */
class SupplierInvoiceDraftTest extends SupplierInvoiceTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Supplier $supplier;

    private TaxRate $tva;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['supplier_invoices.enabled' => true, 'inertia.testing.ensure_pages_exist' => false]);
        [$this->owner, $this->org, $this->store, $this->supplier] = $this->invoiceFixture();
        $this->tva = $this->createTaxRate($this->org, 'TVA 20', '20.0000');
        $this->variant = $this->createProduct($this->org, 'Micro', 'MIC-1')->variants->firstOrFail();
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'FA-2026/001',
            'invoice_date' => '2026-11-20',
            'currency_code' => 'MAD',
            'rounding_adjustment' => '0',
            'control_total_ttc' => '120.00',
            'lines' => [[
                'cost_nature' => 'inventory', 'product_variant_id' => $this->variant->id, 'description' => 'Micro',
                'quantity' => '2', 'unit_price_ht' => '50.00', 'tax_rate_id' => $this->tva->id,
            ]],
            'installments' => [['due_date' => '2026-12-20', 'amount' => '120.00']],
        ], $overrides);
    }

    private function create(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson(route('finance.supplier-invoices.store'), $this->payload($overrides));
    }

    private function member(array $permissions, ?Store $store = null, string $role = 'Role'): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->org, $user, $permissions, roleName: $role.Str::random(4));
        $this->addStoreMember($store ?? $this->store, $user);
        $this->activate($user, $this->org, $store ?? $this->store);

        return $user;
    }

    public function test_creating_a_draft_computes_totals_numbers_it_audits_it_and_creates_no_payable(): void
    {
        $response = $this->create();

        $invoice = SupplierInvoice::query()->sole();
        $response->assertRedirect();
        $this->assertSame('draft', $invoice->status->value);
        $this->assertSame('FAF-2026-000001', $invoice->internal_number);
        $this->assertSame('100.0000', (string) $invoice->subtotal_ht);
        $this->assertSame('20.0000', (string) $invoice->total_tva);
        $this->assertSame('120.0000', (string) $invoice->total_ttc);
        $this->assertSame('120.0000', (string) $invoice->control_total_ttc);
        $this->assertSame('FA2026001|2026', $invoice->dedupe_key);
        $this->assertSame(1, $invoice->row_version);
        $this->assertSame($this->owner->id, $invoice->created_by_user_id);
        $line = $invoice->lines->sole();
        $this->assertSame('20.0000', (string) $line->tax_rate_percent);
        $this->assertSame('TVA 20', $line->tax_name_snapshot);
        $this->assertSame('120.0000', (string) $line->line_ttc);
        $this->assertSame('2026-12-20', $invoice->installments->sole()->due_date->toDateString());
        $this->assertDatabaseHas('audit_logs', ['event' => 'supplier_invoice.draft_created', 'auditable_id' => $invoice->id]);
        $this->assertDatabaseCount('finance_obligations', 0);
        $this->assertDatabaseCount('finance_outgoing_payments', 0);
    }

    public function test_float_json_numbers_are_accepted_as_decimal_text(): void
    {
        $payload = $this->payload();
        $payload['lines'][0]['quantity'] = 2.5;
        $payload['lines'][0]['unit_price_ht'] = 10;
        $payload['control_total_ttc'] = null;
        $payload['installments'] = [];

        $this->actingAs($this->owner)->postJson(route('finance.supplier-invoices.store'), $payload)->assertRedirect();

        $this->assertSame('30.0000', (string) SupplierInvoice::query()->sole()->total_ttc);
    }

    public function test_the_tax_rate_is_snapshotted_on_the_line(): void
    {
        $this->create();
        $this->tva->rate = '7.0000';
        $this->tva->save();

        $this->assertSame('20.0000', (string) SupplierInvoice::query()->sole()->lines->sole()->tax_rate_percent);
    }

    public function test_a_retried_client_operation_returns_the_same_draft_and_authorizes_first(): void
    {
        $op = (string) Str::uuid();
        $this->create(['client_operation_id' => $op])->assertRedirect();
        $this->create(['client_operation_id' => $op])->assertRedirect();
        $this->assertDatabaseCount('supplier_invoices', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'supplier_invoice.draft_created')->count());

        // Another authorized user cannot reuse it; an unauthorized user is refused before the shortcut.
        $other = $this->member(['supplier_invoices.view', 'supplier_invoices.manage']);
        $this->create(['client_operation_id' => $op], $other)->assertStatus(422)->assertJsonValidationErrors('client_operation_id');
        $stranger = $this->member(['supplier_invoices.view']);
        $this->create(['client_operation_id' => $op], $stranger)->assertForbidden();
        $this->assertDatabaseCount('supplier_invoices', 1);
    }

    public function test_update_replaces_lines_bumps_the_version_and_rejects_a_stale_version(): void
    {
        $this->create()->assertRedirect();
        $invoice = SupplierInvoice::query()->sole();
        $update = $this->payload(['row_version' => 1, 'control_total_ttc' => '240.00',
            'lines' => [[
                'cost_nature' => 'expense', 'description' => 'Transport', 'quantity' => '1', 'unit_price_ht' => '200.00', 'tax_rate_id' => $this->tva->id,
            ]],
            'installments' => [['due_date' => '2026-12-01', 'amount' => '100.00'], ['due_date' => '2026-12-31', 'amount' => '140.00']],
        ]);

        $this->actingAs($this->owner)->putJson(route('finance.supplier-invoices.update', $invoice), $update)->assertRedirect();

        $invoice->refresh();
        $this->assertSame(2, $invoice->row_version);
        $this->assertSame('240.0000', (string) $invoice->total_ttc);
        $this->assertSame(1, $invoice->lines()->count());
        $this->assertSame(['Transport'], $invoice->lines->pluck('description')->all());
        $this->assertSame(['2026-12-01', '2026-12-31'], $invoice->installments->map(fn ($i) => $i->due_date->toDateString())->all());
        $this->assertSame($this->owner->id, $invoice->last_saved_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supplier_invoice.draft_updated', 'auditable_id' => $invoice->id]);

        $this->actingAs($this->owner)->putJson(route('finance.supplier-invoices.update', $invoice), $update)
            ->assertStatus(422)->assertJsonValidationErrors('row_version');
        $this->assertSame(2, $invoice->fresh()->row_version);
    }

    public function test_only_a_draft_is_editable(): void
    {
        $this->create()->assertRedirect();
        $invoice = $this->forceApproved(SupplierInvoice::query()->sole());

        $this->actingAs($this->owner)->putJson(route('finance.supplier-invoices.update', $invoice), $this->payload(['row_version' => 1]))->assertForbidden();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.edit', $invoice))->assertForbidden();
    }

    public function test_hard_duplicate_same_supplier_normalized_number_and_year_is_refused(): void
    {
        $this->create()->assertRedirect();

        foreach (['fa 2026-001', ' FA-2026/001 ', 'fa2026001'] as $variant) {
            $this->create(['invoice_number' => $variant])->assertStatus(422)->assertJsonValidationErrors('invoice_number');
        }
        $this->assertDatabaseCount('supplier_invoices', 1);

        // Another supplier or another calendar year is not a hard duplicate.
        $other = $this->makeSupplier($this->org, 'Autre');
        $this->create(['supplier_id' => $other->id])->assertRedirect();
        $this->create(['invoice_date' => '2027-01-02', 'installments' => [['due_date' => '2027-01-30', 'amount' => '120.00']]])->assertRedirect();
        $this->assertDatabaseCount('supplier_invoices', 3);
    }

    public function test_a_voided_invoice_frees_its_number(): void
    {
        $this->create()->assertRedirect();
        DB::table('supplier_invoices')->update(['status' => 'void', 'dedupe_key' => null]);

        $this->create()->assertRedirect();
    }

    public function test_soft_warnings_are_returned_not_blocking(): void
    {
        $this->create()->assertRedirect();   // FA-2026/001, 120.00, 2026-11-20
        $this->create(['invoice_number' => 'ZZ-9', 'invoice_date' => '2026-11-22', 'installments' => [['due_date' => '2026-12-22', 'amount' => '120.00']]])->assertRedirect();
        $second = SupplierInvoice::query()->where('invoice_number', 'ZZ-9')->firstOrFail();

        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.show', $second))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Finance/SupplierInvoices/Show')
            ->where('warnings.0.code', 'same_total_nearby'));

        // Same number in another year and leading zeros.
        $this->create(['invoice_number' => '00123'])->assertRedirect();
        $this->create(['invoice_number' => '123', 'invoice_date' => '2027-02-01', 'installments' => [['due_date' => '2027-03-01', 'amount' => '120.00']]])->assertRedirect();
        $third = SupplierInvoice::query()->where('invoice_number', '123')->firstOrFail();
        $codes = collect($this->actingAs($this->owner)->get(route('finance.supplier-invoices.show', $third))->viewData('page')['props']['warnings'])->pluck('code')->all();
        $this->assertContains('same_number_leading_zeros', $codes);
    }

    public function test_references_must_be_consistent(): void
    {
        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner, 'Foreign');
        $foreignStore = $this->createStore($foreignOrg, $foreignOwner);
        $foreignSupplier = $this->makeSupplier($foreignOrg);
        $foreignTax = $this->createTaxRate($foreignOrg, 'TVA', '20.0000');
        $foreignVariant = $this->createProduct($foreignOrg, 'X', 'X-1')->variants->firstOrFail();
        $inactive = $this->makeSupplier($this->org, 'Inactif');
        $inactive->active = false;
        $inactive->save();
        $line = fn (array $o) => [array_replace($this->payload()['lines'][0], $o)];

        $this->create(['supplier_id' => $foreignSupplier->id])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
        $this->create(['supplier_id' => $inactive->id])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
        $this->create(['store_id' => $foreignStore->id])->assertStatus(422)->assertJsonValidationErrors('store_id');
        $this->create(['lines' => $line(['tax_rate_id' => $foreignTax->id])])->assertStatus(422)->assertJsonValidationErrors('lines.0.tax_rate_id');
        $this->create(['lines' => $line(['product_variant_id' => $foreignVariant->id])])->assertStatus(422)->assertJsonValidationErrors('lines.0.product_variant_id');
        $this->create(['lines' => $line(['cost_nature' => 'expense'])])->assertStatus(422)->assertJsonValidationErrors('lines.0.product_variant_id');
        $this->create(['lines' => $line(['product_variant_id' => null])])->assertStatus(422)->assertJsonValidationErrors('lines.0.product_variant_id');
        $this->create(['lines' => $line(['purchase_order_line_id' => 999999])])->assertStatus(422)->assertJsonValidationErrors('lines.0.purchase_order_line_id');
        $this->create(['rounding_adjustment' => '0.06'])->assertStatus(422)->assertJsonValidationErrors('rounding_adjustment');
        $this->create(['installments' => [['due_date' => '2026-12-20', 'amount' => '119.99']]])->assertStatus(422)->assertJsonValidationErrors('installments');
        $this->create(['installments' => [['due_date' => '2026-11-19', 'amount' => '120.00']]])->assertStatus(422)->assertJsonValidationErrors('installments.0.due_date');
        $this->create(['installments' => [['due_date' => '2026-12-20', 'amount' => '120.00', 'preferred_financial_account_id' => 999999]]])->assertStatus(422)->assertJsonValidationErrors('installments');
        $this->create(['lines' => []])->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->create(['invoice_number' => str_repeat('A', 65)])->assertStatus(422)->assertJsonValidationErrors('invoice_number');
        $this->create(['invoice_number' => '---'])->assertStatus(422)->assertJsonValidationErrors('invoice_number');
        $this->create(['purchase_order_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');
        $this->assertDatabaseCount('supplier_invoices', 0);
    }

    public function test_purchase_order_must_match_supplier_store_status_and_currency(): void
    {
        $po = fn (array $o = []) => $this->purchaseOrder($o);

        $this->create(['purchase_order_id' => $po(['supplier_id' => $this->makeSupplier($this->org, 'Autre')->id])])->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');
        $this->create(['purchase_order_id' => $po(['status' => 'cancelled'])])->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');
        $this->create(['purchase_order_id' => $po(['status' => 'draft'])])->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');
        $this->create(['purchase_order_id' => $po(['currency_code' => 'EUR'])])->assertStatus(422)->assertJsonValidationErrors('currency_code');
        $this->assertDatabaseCount('supplier_invoices', 0);

        $good = $po();
        $this->create(['purchase_order_id' => $good])->assertRedirect();
        $this->assertSame($good, SupplierInvoice::query()->sole()->purchase_order_id);
    }

    /** @param array<string,mixed> $o */
    private function purchaseOrder(array $o = []): int
    {
        $po = new PurchaseOrder;
        $po->organization_id = $this->org->id;
        $po->store_id = $o['store_id'] ?? $this->store->id;
        $po->supplier_id = $o['supplier_id'] ?? $this->supplier->id;
        $po->purchase_order_number = 'BCF-'.Str::random(6);
        $po->status = $o['status'] ?? 'ordered';
        $po->currency_code = $o['currency_code'] ?? 'MAD';
        $po->order_date = '2026-11-01';
        $po->save();

        return $po->id;
    }

    public function test_authorization_matrix(): void
    {
        $manager = $this->member(['supplier_invoices.view', 'supplier_invoices.manage']);
        $viewer = $this->member(['supplier_invoices.view']);
        $none = $this->member([]);
        $this->create(as: $manager)->assertRedirect();
        $invoice = SupplierInvoice::query()->sole();

        // No permission at all.
        $this->actingAs($none)->get(route('finance.supplier-invoices.index'))->assertForbidden();
        $this->actingAs($none)->get(route('finance.supplier-invoices.show', $invoice))->assertForbidden();
        $this->create(as: $none)->assertForbidden();
        // View only: can read, cannot create/edit.
        $this->actingAs($viewer)->get(route('finance.supplier-invoices.index'))->assertOk();
        $this->actingAs($viewer)->get(route('finance.supplier-invoices.show', $invoice))->assertOk();
        $this->create(['invoice_number' => 'V-1'], $viewer)->assertForbidden();
        $this->actingAs($viewer)->putJson(route('finance.supplier-invoices.update', $invoice), $this->payload(['row_version' => 1]))->assertForbidden();
        $this->actingAs($viewer)->get(route('finance.supplier-invoices.create'))->assertForbidden();
        $this->assertDatabaseCount('supplier_invoices', 1);
    }

    public function test_store_membership_is_required_and_an_owner_gets_no_bypass(): void
    {
        $otherCreator = User::factory()->create();
        $this->addOrganizationMember($this->org, $otherCreator, ['supplier_invoices.view', 'supplier_invoices.manage', 'stores.create'], roleName: 'Creator');
        $this->activate($otherCreator, $this->org);
        $otherStore = $this->createStore($this->org, $otherCreator, 'Other');   // the OWNER is not a member of it
        $this->assertDatabaseMissing('store_memberships', ['store_id' => $otherStore->id, 'user_id' => $this->owner->id]);

        $this->create(['store_id' => $otherStore->id])->assertForbidden();            // org owner, no membership -> denied

        $this->create(['store_id' => $otherStore->id], $otherCreator)->assertRedirect();
        $hidden = SupplierInvoice::query()->sole();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.show', $hidden))->assertForbidden();
        $this->actingAs($this->owner)->putJson(route('finance.supplier-invoices.update', $hidden), $this->payload(['store_id' => $otherStore->id, 'row_version' => 1]))->assertForbidden();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
        $this->actingAs($otherCreator)->get(route('finance.supplier-invoices.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1));
    }

    public function test_an_inactive_store_refuses_new_drafts_and_edits_but_stays_readable(): void
    {
        $this->create()->assertRedirect();
        $invoice = SupplierInvoice::query()->sole();
        $this->store->status = 'inactive';
        $this->store->save();

        $this->create(['invoice_number' => 'N-2'])->assertForbidden();
        $this->actingAs($this->owner)->putJson(route('finance.supplier-invoices.update', $invoice), $this->payload(['row_version' => 1]))->assertForbidden();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.show', $invoice))->assertOk();
    }

    public function test_cross_organization_invoice_ids_are_not_found(): void
    {
        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner, 'Foreign');
        $foreignStore = $this->createStore($foreignOrg, $foreignOwner);
        $foreign = $this->makeInvoice($foreignOrg, $foreignStore, $this->makeSupplier($foreignOrg));

        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.show', $foreign))->assertNotFound();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.edit', $foreign))->assertNotFound();
        $this->actingAs($this->owner)->putJson(route('finance.supplier-invoices.update', $foreign), $this->payload(['row_version' => 1]))->assertNotFound();
    }

    public function test_the_kill_switch_hides_every_route(): void
    {
        $this->create()->assertRedirect();
        $invoice = SupplierInvoice::query()->sole();
        config(['supplier_invoices.enabled' => false]);

        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.index'))->assertNotFound();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.create'))->assertNotFound();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.show', $invoice))->assertNotFound();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.edit', $invoice))->assertNotFound();
        $this->create(['invoice_number' => 'K-1'])->assertNotFound();
        $this->actingAs($this->owner)->putJson(route('finance.supplier-invoices.update', $invoice), $this->payload(['row_version' => 1]))->assertNotFound();
        $this->assertDatabaseCount('supplier_invoices', 1);
    }

    public function test_test_mode_denies_supplier_invoice_routes(): void
    {
        $this->create()->assertRedirect();
        $invoice = SupplierInvoice::query()->sole();
        $this->actingAs($this->owner)->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();

        $this->get(route('finance.supplier-invoices.index'))->assertRedirect('/test-mode');
        $this->get(route('finance.supplier-invoices.show', $invoice))->assertRedirect('/test-mode');
        $this->postJson(route('finance.supplier-invoices.store'), $this->payload(['invoice_number' => 'T-1']))
            ->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        $this->putJson(route('finance.supplier-invoices.update', $invoice), $this->payload(['row_version' => 1]))
            ->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
        $this->assertDatabaseCount('supplier_invoices', 1);
    }

    public function test_index_show_and_form_props_follow_the_contract(): void
    {
        $this->create()->assertRedirect();
        $invoice = SupplierInvoice::query()->sole();

        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Finance/SupplierInvoices/Index')
            ->has('invoices.data', 1)
            ->where('invoices.data.0.internal_number', 'FAF-2026-000001')
            ->where('invoices.data.0.total_ttc', '120.0000')
            ->where('invoices.data.0.status', 'draft')
            ->where('invoices.data.0.match_status', 'no_po')
            ->where('invoices.data.0.has_attachments', false)
            ->where('invoices.data.0.warnings_count', 0)
            ->where('invoices.data.0.supplier.name', 'Fournisseur')
            ->has('invoices.data.0.store.code')
            ->has('filters')->has('stores')->has('suppliers')
            ->where('can.create', true)->has('can.approve')->has('can.void'));

        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.show', $invoice))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Finance/SupplierInvoices/Show')
            ->where('invoice.total_ttc', '120.0000')
            ->where('invoice.lines.0.quantity', '2.0000')
            ->where('invoice.lines.0.line_ttc', '120.0000')
            ->where('invoice.lines.0.tax_name', 'TVA 20')
            ->where('invoice.lines.0.product_variant.sku', 'MIC-1')
            ->where('invoice.installments.0.amount', '120.0000')
            ->where('invoice.installments.0.obligation', null)
            ->where('invoice.attachments', [])
            ->where('invoice.approval', null)->where('invoice.void', null)
            ->where('matching.status', 'no_po')->has('matching.lines', 1)
            ->where('warnings', [])
            ->where('can.edit', true)->where('can.approve', true)->where('can.void', true)->where('can.attach', true));

        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Finance/SupplierInvoices/Form')->where('invoice', null)
            ->has('suppliers')->has('stores')->has('taxRates', 1)->has('purchaseOrders')->has('accounts')
            ->where('settings.rounding_tolerance', '0.0500')->where('settings.require_distinct_approver', false)->where('settings.money_scale', 2)
            ->where('row_version', null));
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.edit', $invoice))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Finance/SupplierInvoices/Form')->where('invoice.id', $invoice->id)->where('row_version', 1));
    }

    public function test_index_filters(): void
    {
        $this->create()->assertRedirect();
        $other = $this->makeSupplier($this->org, 'Zeta');
        $this->create(['supplier_id' => $other->id, 'invoice_number' => 'Q-1'])->assertRedirect();

        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.index', ['supplier_id' => $other->id]))
            ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1)->where('invoices.data.0.invoice_number', 'Q-1'));
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.index', ['search' => 'zeta']))
            ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1));
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.index', ['status' => 'approved']))
            ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
        $this->actingAs($this->owner)->getJson(route('finance.supplier-invoices.index', ['status' => 'bogus']))->assertStatus(422);
    }
}
