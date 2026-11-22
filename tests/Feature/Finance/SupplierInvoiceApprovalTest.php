<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Actions\Finance\ReverseFinanceOutgoingPaymentAction;
use App\Actions\SupplierInvoices\SaveSupplierInvoiceDraftAction;
use App\Models\AuditLog;
use App\Models\FinanceObligation;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Finance\FinanceObligationCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SupplierInvoiceTestCase;

/** Phase 2 Sprint 1 (PR8): approving a supplier invoice draft creates the payables exactly once. */
class SupplierInvoiceApprovalTest extends SupplierInvoiceTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Supplier $supplier;

    private TaxRate $tva;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['supplier_invoices.enabled' => true, 'inertia.testing.ensure_pages_exist' => false]);
        [$this->owner, $this->org, $this->store, $this->supplier] = $this->invoiceFixture();
        $this->tva = $this->createTaxRate($this->org, 'TVA 20', '20.0000');
        $this->variantId = $this->createProduct($this->org, 'Micro', 'MIC-1')->variants->firstOrFail()->id;
    }

    /** @param array<string,mixed> $overrides */
    private function draft(array $overrides = [], ?User $as = null): SupplierInvoice
    {
        $data = array_replace([
            'store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'invoice_number' => 'FA-2026/001',
            'invoice_date' => '2026-11-20', 'currency_code' => 'MAD', 'control_total_ttc' => '120.00',
            'lines' => [['cost_nature' => 'inventory', 'product_variant_id' => $this->variantId, 'description' => 'Micro', 'quantity' => '2', 'unit_price_ht' => '50.00', 'tax_rate_id' => $this->tva->id]],
            'installments' => [['due_date' => '2026-12-20', 'amount' => '120.00']],
        ], $overrides);

        return app(SaveSupplierInvoiceDraftAction::class)->execute($as ?? $this->owner, $this->org, $data)['invoice'];
    }

    /** @param array<string,mixed> $extra */
    private function approve(SupplierInvoice $invoice, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson(route('finance.supplier-invoices.approve', $invoice), array_replace([
            'control_total_ttc' => '120.00', 'acknowledge_unmatched' => true, 'match_note' => 'Sans bon de commande',
        ], $extra));
    }

    private function member(array $permissions, bool $store = true): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->org, $user, $permissions, roleName: 'R'.Str::random(5));
        $store && $this->addStoreMember($this->store, $user);
        $this->activate($user, $this->org, $this->store);

        return $user;
    }

    public function test_approval_generates_one_obligation_per_installment_in_due_date_order_across_a_year_boundary(): void
    {
        $invoice = $this->draft([
            'control_total_ttc' => '120.00', 'notes' => 'Livraison partielle',
            'installments' => [
                ['due_date' => '2027-01-15', 'amount' => '50.00', 'planned_payment_method' => 'cheque'],
                ['due_date' => '2026-12-31', 'amount' => '70.00', 'planned_payment_method' => 'bank_transfer'],
            ],
        ]);

        $this->approve($invoice)->assertRedirect();

        $invoice->refresh();
        $this->assertSame('approved', $invoice->status->value);
        $this->assertSame($this->owner->id, $invoice->approved_by_user_id);
        $this->assertSame('Fournisseur', $invoice->supplier_name_snapshot);
        $this->assertSame('no_po', $invoice->match_status_at_approval->value);
        $this->assertSame('Sans bon de commande', $invoice->match_note);
        $obligations = FinanceObligation::query()->orderBy('id')->get();
        $this->assertCount(2, $obligations);
        $this->assertSame(['OBL-2026-000001', 'OBL-2027-000001'], $obligations->pluck('obligation_number')->all());
        [$first, $second] = $obligations;
        $this->assertSame('2026-12-31', $first->due_date->toDateString());
        $this->assertSame('70.0000', (string) $first->planned_amount);
        $this->assertSame('bank_transfer', $first->planned_payment_method->value);
        $this->assertSame('2027-01-15', $second->due_date->toDateString());
        $this->assertSame('cheque', $second->planned_payment_method->value);
        foreach ($obligations as $i => $obligation) {
            $this->assertSame('supplier', $obligation->category->value);
            $this->assertSame($this->store->id, $obligation->store_id);
            $this->assertSame($this->supplier->id, $obligation->supplier_id);
            $this->assertSame('FA-2026/001', $obligation->reference);
            $this->assertSame('MAD', $obligation->currency_code);
            $this->assertSame('Facture Fournisseur FA-2026/001 '.($i + 1).'/2', $obligation->label);
            $this->assertSame('Livraison partielle', $obligation->notes);
            $this->assertSame($this->owner->id, $obligation->created_by_user_id);
            $this->assertSame($invoice->installments->sortBy('due_date')->values()[$i]->id, $obligation->supplier_invoice_installment_id);
        }
        $this->assertSame(2, AuditLog::query()->where('event', 'finance_obligation.created')->count());
        $audit = AuditLog::query()->where('event', 'supplier_invoice.approved')->sole();
        $this->assertSame($this->store->id, $audit->store_id);
        $this->assertSame(['OBL-2026-000001', 'OBL-2027-000001'], $audit->new_values['obligation_numbers']);
        // Nothing but obligations is written.
        $this->assertDatabaseCount('finance_outgoing_payments', 0);
        $this->assertDatabaseCount('finance_outgoing_payment_allocations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_approval_is_exactly_once(): void
    {
        $invoice = $this->draft();

        $this->approve($invoice)->assertRedirect();
        $this->approve($invoice)->assertRedirect();
        $this->approve($invoice, ['control_total_ttc' => '1.00'])->assertRedirect();   // already approved: unchanged, nothing recomputed

        $this->assertDatabaseCount('finance_obligations', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'supplier_invoice.approved')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'finance_obligation.created')->count());
        $this->assertSame(1, DB::table('finance_obligation_sequences')->where('year', 2026)->value('next_number') - 1);
    }

    public function test_a_default_schedule_uses_the_supplier_payment_term_or_is_refused(): void
    {
        $noSchedule = ['installments' => []];
        $withoutTerm = $this->draft($noSchedule);
        $this->approve($withoutTerm)->assertStatus(422)->assertJsonValidationErrors('installments');
        $this->assertDatabaseCount('finance_obligations', 0);
        $this->assertSame('draft', $withoutTerm->fresh()->status->value);
        $this->assertCount(0, $withoutTerm->fresh()->installments);

        $this->supplier->default_payment_term_days = 30;
        $this->supplier->save();
        $this->approve($withoutTerm)->assertRedirect();

        $obligation = FinanceObligation::query()->sole();
        $this->assertSame('2026-12-20', $obligation->due_date->toDateString());
        $this->assertSame('120.0000', (string) $obligation->planned_amount);
        $this->assertCount(1, $withoutTerm->fresh()->installments);
    }

    public function test_the_control_total_must_match_exactly(): void
    {
        $invoice = $this->draft();

        $this->approve($invoice, ['control_total_ttc' => '120.01'])->assertStatus(422)->assertJsonValidationErrors('control_total_ttc');
        $this->approve($invoice, ['control_total_ttc' => null])->assertStatus(422)->assertJsonValidationErrors('control_total_ttc');
        $this->assertDatabaseCount('finance_obligations', 0);
        $this->assertSame('draft', $invoice->fresh()->status->value);
    }

    public function test_a_closed_period_refuses_the_invoice_date_and_every_due_date(): void
    {
        $this->markClosed('2026-11');
        $invoice = $this->draft();
        $this->approve($invoice)->assertStatus(422)->assertJsonValidationErrors('invoice');
        $this->assertStringContainsString('clôturée', json_encode($this->approve($invoice)->json('errors'), JSON_UNESCAPED_UNICODE));

        DB::table('finance_month_reviews')->delete();
        $this->markClosed('2027-01');
        $late = $this->draft(['invoice_number' => 'FA-LATE', 'installments' => [['due_date' => '2026-12-01', 'amount' => '60.00'], ['due_date' => '2027-01-10', 'amount' => '60.00']]]);
        $this->approve($late)->assertStatus(422)->assertJsonValidationErrors('invoice');

        $this->assertDatabaseCount('finance_obligations', 0);
        $this->assertSame(['draft', 'draft'], SupplierInvoice::query()->orderBy('id')->get()->map(fn ($i) => $i->status->value)->all());
        $this->assertDatabaseCount('finance_obligation_sequences', 0);
    }

    private function markClosed(string $month): void
    {
        DB::table('finance_month_reviews')->insert([
            'organization_id' => $this->org->id, 'period' => $month.'-01', 'status' => 'closed',
            'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_unmatched_invoice_needs_an_explicit_acknowledgement_and_a_note(): void
    {
        $invoice = $this->draft();

        $this->approve($invoice, ['acknowledge_unmatched' => false])->assertStatus(422)->assertJsonValidationErrors('acknowledge_unmatched');
        $this->approve($invoice, ['acknowledge_unmatched' => null])->assertStatus(422)->assertJsonValidationErrors('acknowledge_unmatched');
        $this->approve($invoice, ['match_note' => '   '])->assertStatus(422)->assertJsonValidationErrors('match_note');
        $this->assertDatabaseCount('finance_obligations', 0);

        $this->approve($invoice)->assertRedirect();
        $this->assertSame('Sans bon de commande', $invoice->fresh()->match_note);
    }

    public function test_soft_warnings_must_be_acknowledged_by_code(): void
    {
        $this->approve($this->draft())->assertRedirect();
        $second = $this->draft(['invoice_number' => 'ZZ-9', 'invoice_date' => '2026-11-22', 'installments' => [['due_date' => '2026-12-22', 'amount' => '120.00']]]);

        $response = $this->approve($second)->assertStatus(422)->assertJsonValidationErrors('acknowledged_warnings');
        $this->assertStringContainsString('same_total_nearby', $response->json('errors.acknowledged_warnings.0'));
        $this->approve($second, ['acknowledged_warnings' => ['other']])->assertStatus(422)->assertJsonValidationErrors('acknowledged_warnings');
        $this->assertDatabaseCount('finance_obligations', 1);

        $this->approve($second, ['acknowledged_warnings' => ['same_total_nearby']])->assertRedirect();
        $this->assertDatabaseCount('finance_obligations', 2);
        $audit = AuditLog::query()->where('event', 'supplier_invoice.approved')->orderByDesc('id')->first();
        $this->assertSame(['same_total_nearby'], $audit->new_values['acknowledged_warnings']);
    }

    public function test_the_distinct_approver_rule_follows_the_setting(): void
    {
        $approver = $this->member(['supplier_invoices.view', 'supplier_invoices.approve']);

        // Off (default): the creator may approve.
        $this->approve($this->draft(['invoice_number' => 'OFF-1']))->assertRedirect();

        config(['supplier_invoices.require_distinct_approver' => true]);
        $invoice = $this->draft(['invoice_number' => 'ON-1', 'invoice_date' => '2026-03-01', 'installments' => [['due_date' => '2026-04-01', 'amount' => '120.00']]]);
        $this->approve($invoice, ['acknowledged_warnings' => ['same_total_nearby']])->assertStatus(422)->assertJsonValidationErrors('invoice');
        $this->assertSame('draft', $invoice->fresh()->status->value);
        $this->approve($invoice, ['acknowledged_warnings' => ['same_total_nearby']], $approver)->assertRedirect();
    }

    public function test_the_last_saver_and_the_creator_cannot_approve_but_a_third_person_can(): void
    {
        config(['supplier_invoices.require_distinct_approver' => true]);
        $editor = $this->member(['supplier_invoices.view', 'supplier_invoices.manage', 'supplier_invoices.approve']);
        $approver = $this->member(['supplier_invoices.view', 'supplier_invoices.approve']);
        $invoice = $this->draft();
        app(SaveSupplierInvoiceDraftAction::class)->execute($editor, $this->org, [
            'store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'invoice_number' => 'FA-2026/001', 'invoice_date' => '2026-11-20',
            'currency_code' => 'MAD', 'control_total_ttc' => '120.00', 'row_version' => 1,
            'lines' => [['cost_nature' => 'inventory', 'product_variant_id' => $this->variantId, 'description' => 'Micro', 'quantity' => '2', 'unit_price_ht' => '50.00', 'tax_rate_id' => $this->tva->id]],
            'installments' => [['due_date' => '2026-12-20', 'amount' => '120.00']],
        ], $invoice);

        $this->approve($invoice, [], $this->owner)->assertStatus(422);             // creator
        $this->approve($invoice, [], $editor)->assertStatus(422);                  // last saver
        $this->approve($invoice, [], $approver)->assertRedirect();                   // third person
        $this->assertSame($approver->id, $invoice->fresh()->approved_by_user_id);
    }

    public function test_supplier_ice_is_advisory_unless_required_by_setting(): void
    {
        $invoice = $this->draft();
        config(['supplier_invoices.require_supplier_ice_on_approval' => true]);
        $this->approve($invoice)->assertStatus(422)->assertJsonValidationErrors('invoice');
        $this->assertDatabaseCount('finance_obligations', 0);

        config(['supplier_invoices.require_supplier_ice_on_approval' => false]);
        $this->approve($invoice)->assertRedirect();
        $this->assertNull($invoice->fresh()->supplier_ice_snapshot);

        $other = $this->draft(['invoice_number' => 'ICE-1', 'invoice_date' => '2026-05-01', 'installments' => [['due_date' => '2026-06-01', 'amount' => '120.00']]]);
        $this->supplier->ice = '001234567000089';
        $this->supplier->save();
        $this->approve($other, ['acknowledged_warnings' => ['same_total_nearby']])->assertRedirect();
        $this->assertSame('001234567000089', $other->fresh()->supplier_ice_snapshot);
    }

    public function test_authorization_matrix_and_hidden_feature(): void
    {
        $invoice = $this->draft();
        $noPermission = $this->member(['supplier_invoices.view', 'supplier_invoices.manage']);
        $nonMember = $this->member(['supplier_invoices.view', 'supplier_invoices.approve'], store: false);

        $this->approve($invoice, [], $noPermission)->assertForbidden();
        $this->approve($invoice, [], $nonMember)->assertForbidden();
        $this->store->status = 'inactive';
        $this->store->save();
        $this->approve($invoice)->assertForbidden();
        $this->store->status = 'active';
        $this->store->save();

        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner, 'Foreign');
        $foreignStore = $this->createStore($foreignOrg, $foreignOwner);
        $foreign = $this->makeInvoice($foreignOrg, $foreignStore, $this->makeSupplier($foreignOrg));
        $this->approve($foreign)->assertNotFound();

        config(['supplier_invoices.enabled' => false]);
        $this->approve($invoice)->assertNotFound();
        config(['supplier_invoices.enabled' => true]);
        $this->assertDatabaseCount('finance_obligations', 0);
        $this->assertSame('draft', $invoice->fresh()->status->value);
    }

    public function test_test_mode_denies_approval(): void
    {
        $invoice = $this->draft();
        $this->actingAs($this->owner)->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();

        $this->postJson(route('finance.supplier-invoices.approve', $invoice), ['control_total_ttc' => '120.00'])
            ->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
        $this->assertDatabaseCount('finance_obligations', 0);
    }

    public function test_inactive_supplier_and_void_or_empty_invoices_cannot_be_approved(): void
    {
        $invoice = $this->draft();
        $this->supplier->active = false;
        $this->supplier->save();
        $this->approve($invoice)->assertStatus(422)->assertJsonValidationErrors('invoice');
        $this->supplier->active = true;
        $this->supplier->save();

        DB::table('supplier_invoices')->where('id', $invoice->id)->update(['status' => 'void', 'dedupe_key' => null]);
        $this->approve($invoice)->assertStatus(422)->assertJsonValidationErrors('invoice');

        $empty = $this->makeInvoice($this->org, $this->store, $this->supplier);   // no lines
        $this->approve($empty)->assertStatus(422)->assertJsonValidationErrors('invoice');
        $this->assertDatabaseCount('finance_obligations', 0);
    }

    public function test_tampered_stored_totals_are_refused(): void
    {
        $invoice = $this->draft();
        DB::table('supplier_invoices')->where('id', $invoice->id)->update(['total_ttc' => '121.0000']);

        $this->approve($invoice, ['control_total_ttc' => '121.00'])->assertStatus(422)->assertJsonValidationErrors('invoice');
        $this->assertDatabaseCount('finance_obligations', 0);
    }

    public function test_legacy_obligations_are_untouched(): void
    {
        $legacy = app(ManageFinanceObligationAction::class)->create($this->owner, $this->org, [
            'store_id' => $this->store->id, 'category' => 'supplier', 'label' => 'Legacy', 'supplier_id' => $this->supplier->id, 'due_date' => '2026-12-20',
            'planned_amount' => '500.0000', 'currency_code' => 'MAD',
        ]);
        $before = DB::table('finance_obligations')->where('id', $legacy->id)->first();

        $this->approve($this->draft())->assertRedirect();

        $this->assertEquals($before, DB::table('finance_obligations')->where('id', $legacy->id)->first());
        $this->assertNull($legacy->fresh()->supplier_invoice_installment_id);
        $this->assertDatabaseCount('finance_obligations', 2);
    }

    public function test_invoice_obligations_are_payable_through_the_existing_payment_actions_and_locked_for_edits(): void
    {
        $this->approve($this->draft())->assertRedirect();
        $obligation = FinanceObligation::query()->sole();
        $account = $this->createFinancialAccount($this->org, ['type' => 'bank', 'code' => 'BANK-1', 'accepted_methods' => ['bank_transfer']]);
        $calculator = app(FinanceObligationCalculator::class);
        $pay = fn (string $amount) => app(RecordFinanceOutgoingPaymentAction::class)->execute($this->owner, $this->org, [
            'store_id' => null, 'financial_account_id' => $account->id, 'supplier_id' => $this->supplier->id, 'beneficiary_name' => 'Fournisseur',
            'payment_method' => 'bank_transfer', 'amount' => $amount, 'currency_code' => 'MAD', 'payment_date' => '2026-11-25',
            'reference' => null, 'notes' => null, 'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['obligation_id' => $obligation->id, 'amount' => $amount]],
        ]);
        $values = fn () => $calculator->values($obligation->fresh(), Carbon::parse('2026-11-26'));

        $partial = $pay('50.0000');
        $this->assertSame('50.0000', $values()['paid']);
        $this->assertSame('70.0000', $values()['remaining']);
        $this->assertSame('partial', $values()['status']);
        $full = $pay('70.0000');
        $this->assertSame('paid', $values()['status']);
        $this->assertSame('0.0000', $values()['remaining']);

        app(ReverseFinanceOutgoingPaymentAction::class)->execute($this->owner, $full, 'Erreur de saisie');
        $this->assertSame('70.0000', $values()['remaining']);
        app(ReverseFinanceOutgoingPaymentAction::class)->execute($this->owner, $partial, 'Erreur de saisie');
        $this->assertSame('120.0000', $values()['remaining']);

        // The invoice obligation is edited or cancelled only through the invoice.
        foreach ([
            fn () => app(ManageFinanceObligationAction::class)->update($this->owner, $obligation->fresh(), [
                'store_id' => $this->store->id, 'category' => 'supplier', 'label' => 'X', 'supplier_id' => $this->supplier->id, 'due_date' => '2026-12-20',
                'planned_amount' => '10.0000', 'currency_code' => 'MAD',
            ]),
            fn () => app(ManageFinanceObligationAction::class)->cancel($this->owner, $obligation->fresh(), 'Test'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An invoice obligation must not be editable or cancellable directly.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('obligation', $e->errors());
            }
        }
        $this->assertSame('120.0000', (string) $obligation->fresh()->planned_amount);
    }

    public function test_show_exposes_the_obligations_and_approval_block(): void
    {
        $invoice = $this->draft();
        $this->approve($invoice)->assertRedirect();
        $obligation = FinanceObligation::query()->sole();

        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.show', $invoice))->assertOk()->assertInertia(fn ($page) => $page
            ->where('invoice.status', 'approved')
            ->where('invoice.installments.0.obligation.id', $obligation->id)
            ->where('invoice.installments.0.obligation.number', 'OBL-2026-000001')
            ->where('invoice.installments.0.obligation.planned', '120.0000')
            ->where('invoice.installments.0.obligation.paid', '0.0000')
            ->where('invoice.installments.0.obligation.remaining', '120.0000')
            ->where('invoice.approval.match_status_at_approval', 'no_po')
            ->where('invoice.approval.match_note', 'Sans bon de commande')
            ->where('can.approve', false)->where('can.edit', false));
    }
}
