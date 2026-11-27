<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\ManageFinancePaymentInstrumentAction;
use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Actions\Finance\ReverseFinanceOutgoingPaymentAction;
use App\Actions\SupplierInvoices\ApproveSupplierInvoiceAction;
use App\Actions\SupplierInvoices\SaveSupplierInvoiceDraftAction;
use App\Models\AuditLog;
use App\Models\FinanceObligation;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Finance\FinanceMonthlySituationService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceTreasuryForecastService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Support\SupplierInvoiceTestCase;

/** Phase 2 Sprint 1 (PR9): voiding a supplier invoice (mistakes only, never after money moved). */
class SupplierInvoiceVoidTest extends SupplierInvoiceTestCase
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
        $this->travelTo('2026-11-22 10:00:00');
        config(['supplier_invoices.enabled' => true, 'inertia.testing.ensure_pages_exist' => false]);
        [$this->owner, $this->org, $this->store, $this->supplier] = $this->invoiceFixture();
        $this->tva = $this->createTaxRate($this->org, 'TVA 20', '20.0000');
        $this->variantId = $this->createProduct($this->org, 'Micro', 'MIC-1')->variants->firstOrFail()->id;
    }

    /** @param array<string,mixed> $overrides */
    private function draft(array $overrides = []): SupplierInvoice
    {
        return app(SaveSupplierInvoiceDraftAction::class)->execute($this->owner, $this->org, array_replace([
            'store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'invoice_number' => 'FA-2026/001',
            'invoice_date' => '2026-11-20', 'currency_code' => 'MAD', 'control_total_ttc' => '120.00',
            'lines' => [['cost_nature' => 'inventory', 'product_variant_id' => $this->variantId, 'description' => 'Micro', 'quantity' => '2', 'unit_price_ht' => '50.00', 'tax_rate_id' => $this->tva->id]],
            'installments' => [['due_date' => '2026-12-20', 'amount' => '120.00']],
        ], $overrides))['invoice'];
    }

    private function approved(array $overrides = []): SupplierInvoice
    {
        $invoice = $this->draft($overrides);
        app(ApproveSupplierInvoiceAction::class)->execute($this->owner, $invoice, [
            'control_total_ttc' => (string) $invoice->total_ttc, 'acknowledge_unmatched' => true, 'match_note' => 'Sans BC',
        ]);

        return $invoice->fresh();
    }

    /** @param array<string,mixed> $extra */
    private function void(SupplierInvoice $invoice, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson(route('finance.supplier-invoices.void', $invoice), array_replace([
            'reason_code' => 'wrong_data_entry', 'reason' => 'Mauvais montant saisi',
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

    private function account(): FinancialAccount
    {
        return $this->createFinancialAccount($this->org, ['type' => 'bank', 'code' => 'BANK-'.Str::upper(Str::random(4)), 'accepted_methods' => ['bank_transfer', 'cheque', 'traite']]);
    }

    private function pay(FinanceObligation $obligation, FinancialAccount $account, string $amount)
    {
        return app(RecordFinanceOutgoingPaymentAction::class)->execute($this->owner, $this->org, [
            'store_id' => null, 'financial_account_id' => $account->id, 'supplier_id' => $this->supplier->id, 'beneficiary_name' => 'Fournisseur',
            'payment_method' => 'bank_transfer', 'amount' => $amount, 'currency_code' => 'MAD', 'payment_date' => '2026-11-22',
            'reference' => null, 'notes' => null, 'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['obligation_id' => $obligation->id, 'amount' => $amount]],
        ]);
    }

    private function dec(FinancePeriod $period): array
    {
        return [
            'kpis' => app(FinanceMonthlySituationService::class)->kpis($this->org, $period, null),
            'forecast' => app(FinanceTreasuryForecastService::class)->forecast($this->org, $period, null),
        ];
    }

    public function test_voiding_a_draft_frees_the_number_for_re_entry(): void
    {
        $invoice = $this->draft();
        $this->assertNotNull($invoice->dedupe_key);

        $this->void($invoice, ['reason_code' => 'duplicate_entry', 'reason' => null])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('void', $invoice->status->value);
        $this->assertNull($invoice->dedupe_key);
        $this->assertSame($this->owner->id, $invoice->voided_by_user_id);
        $this->assertSame('duplicate_entry', $invoice->void_reason_code->value);
        $this->assertDatabaseCount('finance_obligations', 0);
        $this->assertSame(1, AuditLog::query()->where('event', 'supplier_invoice.voided')->count());
        $again = $this->draft();   // same supplier + number + year
        $this->assertNotSame($invoice->id, $again->id);
        $this->assertSame('FA2026001|2026', $again->dedupe_key);
    }

    public function test_voiding_an_approved_invoice_cancels_its_obligations_and_removes_the_payable(): void
    {
        $invoice = $this->approved(['installments' => [['due_date' => '2026-12-05', 'amount' => '70.00'], ['due_date' => '2026-12-20', 'amount' => '50.00']]]);
        $december = FinancePeriod::fromMonth('2026-12');
        $before = $this->dec($december);
        $this->assertSame('120.0000', $before['kpis']['planned']);
        $this->assertSame('120.0000', $before['kpis']['remaining']);
        $this->assertSame('120.0000', $before['forecast']['remaining_outflows']);

        $this->void($invoice)->assertRedirect();

        $invoice->refresh();
        $this->assertSame('void', $invoice->status->value);
        $this->assertNull($invoice->dedupe_key);
        $obligations = FinanceObligation::query()->orderBy('id')->get();
        $this->assertCount(2, $obligations);
        foreach ($obligations as $obligation) {
            $this->assertNotNull($obligation->cancelled_at);
            $this->assertSame($this->owner->id, $obligation->cancelled_by_user_id);
            $this->assertSame('Facture fournisseur annulée : Mauvais montant saisi', $obligation->cancellation_reason);
        }
        $after = $this->dec($december);
        $this->assertSame('0.0000', $after['kpis']['planned']);
        $this->assertSame('0.0000', $after['kpis']['remaining']);
        $this->assertSame('0.0000', $after['forecast']['remaining_outflows']);
        $this->assertSame(2, AuditLog::query()->where('event', 'finance_obligation.cancelled')->count());
        $audit = AuditLog::query()->where('event', 'supplier_invoice.voided')->sole();
        $this->assertSame($this->store->id, $audit->store_id);
        $this->assertSame(['OBL-2026-000001', 'OBL-2026-000002'], $audit->new_values['obligation_numbers']);
        $this->assertSame('wrong_data_entry', $audit->new_values['reason_code']);
        // The number can be entered again.
        $this->assertSame('draft', $this->draft()->status->value);
    }

    public function test_a_posted_payment_blocks_the_void_until_it_is_reversed_and_history_is_kept(): void
    {
        $invoice = $this->approved();
        $obligation = FinanceObligation::query()->sole();
        $payment = $this->pay($obligation, $this->account(), '50.0000');

        $response = $this->void($invoice)->assertStatus(422)->assertJsonValidationErrors('invoice');
        $this->assertStringContainsString('inversez d’abord les paiements', $response->json('errors.invoice.0'));
        $this->assertStringContainsString('avoir', $response->json('errors.invoice.0'));
        $this->assertSame('approved', $invoice->fresh()->status->value);
        $this->assertNull($obligation->fresh()->cancelled_at);

        app(ReverseFinanceOutgoingPaymentAction::class)->execute($this->owner, $payment, 'Erreur');
        $this->void($invoice)->assertRedirect();

        $this->assertNotNull($obligation->fresh()->cancelled_at);
        $this->assertDatabaseCount('finance_outgoing_payments', 1);
        $this->assertDatabaseCount('finance_outgoing_payment_allocations', 1);
        $this->assertSame('reversed', DB::table('finance_outgoing_payments')->value('status'));
    }

    /**
     * UAT defect: the refusal came back as a validation error on the `invoice` key (not a form field), the dialog only
     * rendered `reason_code` / `reason`, so the user saw nothing. The contract the UI relies on is pinned here: an
     * Inertia void that is refused redirects back with the message under `invoice`, which Inertia shares as `errors`,
     * and no financial state moves.
     */
    public function test_a_refused_void_reaches_the_inertia_page_as_an_invoice_error_and_changes_nothing(): void
    {
        $invoice = $this->approved();
        $obligation = FinanceObligation::query()->sole();
        $this->pay($obligation, $this->account(), '50.0000');
        $before = [
            'obligation' => $obligation->fresh()->only(['cancelled_at', 'amount']),
            'payments' => DB::table('finance_outgoing_payments')->get(['id', 'status', 'amount'])->toArray(),
            'allocations' => DB::table('finance_outgoing_payment_allocations')->get(['id', 'amount'])->toArray(),
            'audit' => AuditLog::query()->count(),
        ];

        $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => route('finance.supplier-invoices.show', $invoice)];
        $this->actingAs($this->owner)->withHeaders($headers)
            ->post(route('finance.supplier-invoices.void', $invoice), ['reason_code' => 'wrong_data_entry', 'reason' => 'Erreur de saisie'])
            ->assertRedirect()
            ->assertSessionHasErrors('invoice');

        // The page the user lands on after the redirect carries the refusal as the shared `errors` prop.
        $page = $this->actingAs($this->owner)->withHeaders($headers)->followingRedirects()
            ->post(route('finance.supplier-invoices.void', $invoice), ['reason_code' => 'wrong_data_entry', 'reason' => 'Erreur de saisie']);
        $message = $page->json('props.errors.invoice');
        $this->assertStringContainsString('inversez d’abord les paiements', (string) $message);
        $this->assertSame('approved', $page->json('props.invoice.status'));

        $invoice = $invoice->fresh();
        $this->assertSame('approved', $invoice->status->value);
        $this->assertNull($invoice->voided_at);
        $this->assertEquals($before['obligation'], $obligation->fresh()->only(['cancelled_at', 'amount']));
        $this->assertEquals($before['payments'], DB::table('finance_outgoing_payments')->get(['id', 'status', 'amount'])->toArray());
        $this->assertEquals($before['allocations'], DB::table('finance_outgoing_payment_allocations')->get(['id', 'amount'])->toArray());
        $this->assertSame($before['audit'], AuditLog::query()->count(), 'a refused void must not write any audit row');
        $this->assertSame(0, AuditLog::query()->whereIn('event', ['supplier_invoice.voided', 'finance_obligation.cancelled'])->count());
    }

    /** A reversed payment is history, not a blocker (existing rule); a closed period still refuses with a visible `invoice` error. */
    public function test_a_reversed_payment_does_not_block_but_a_closed_period_still_refuses_with_an_invoice_error(): void
    {
        $invoice = $this->approved();
        $obligation = FinanceObligation::query()->sole();
        $payment = $this->pay($obligation, $this->account(), '50.0000');
        app(ReverseFinanceOutgoingPaymentAction::class)->execute($this->owner, $payment, 'Erreur');

        DB::table('finance_month_reviews')->insert(['organization_id' => $this->org->id, 'period' => '2026-11-01', 'status' => 'closed', 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner)->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->post(route('finance.supplier-invoices.void', $invoice), ['reason_code' => 'wrong_data_entry', 'reason' => 'Erreur de saisie'])
            ->assertRedirect()
            ->assertSessionHasErrors('invoice');
        $this->assertStringContainsString('clôturée', (string) session('errors')->get('invoice')[0]);
        $this->assertSame('approved', $invoice->fresh()->status->value);
        $this->assertNull($obligation->fresh()->cancelled_at);
        $this->assertSame('reversed', DB::table('finance_outgoing_payments')->value('status'));

        DB::table('finance_month_reviews')->delete();
        $this->void($invoice)->assertRedirect();
        $this->assertNotNull($obligation->fresh()->cancelled_at);
    }

    public function test_an_issued_instrument_blocks_the_void(): void
    {
        $invoice = $this->approved();
        $obligation = FinanceObligation::query()->sole();
        $instrument = app(ManageFinancePaymentInstrumentAction::class)->create($this->owner, $obligation, [
            'type' => 'cheque', 'external_reference' => 'CH-1', 'amount' => '120.0000', 'issue_date' => '2026-11-22',
            'maturity_date' => '2026-12-20', 'financial_account_id' => $this->account()->id, 'notes' => null,
        ]);

        $this->void($invoice)->assertStatus(422)->assertJsonValidationErrors('invoice');
        $this->assertNull($obligation->fresh()->cancelled_at);

        app(ManageFinancePaymentInstrumentAction::class)->cancel($this->owner, $instrument, 'Annulé');
        $this->void($invoice)->assertRedirect();
        $this->assertNotNull($obligation->fresh()->cancelled_at);
    }

    public function test_a_closed_period_refuses_the_invoice_month_and_every_due_month(): void
    {
        $invoice = $this->approved(['installments' => [['due_date' => '2026-11-25', 'amount' => '60.00'], ['due_date' => '2027-01-10', 'amount' => '60.00']]]);

        foreach (['2026-11', '2027-01'] as $month) {
            DB::table('finance_month_reviews')->insert(['organization_id' => $this->org->id, 'period' => $month.'-01', 'status' => 'closed', 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $response = $this->void($invoice)->assertStatus(422)->assertJsonValidationErrors('invoice');
            $this->assertStringContainsString('clôturée', $response->json('errors.invoice.0'));
            DB::table('finance_month_reviews')->delete();
        }
        $this->assertSame('approved', $invoice->fresh()->status->value);
        $this->assertSame(0, FinanceObligation::query()->whereNotNull('cancelled_at')->count());
    }

    public function test_the_maximum_age_setting_applies_only_when_set(): void
    {
        $old = $this->approved(['invoice_date' => '2026-06-01', 'installments' => [['due_date' => '2026-12-20', 'amount' => '120.00']]]);
        config(['supplier_invoices.void_max_age_days' => 30]);
        $this->void($old)->assertStatus(422)->assertJsonValidationErrors('invoice');
        config(['supplier_invoices.void_max_age_days' => null]);
        $this->void($old)->assertRedirect();
    }

    public function test_a_second_void_is_idempotent(): void
    {
        $invoice = $this->approved();
        $this->void($invoice)->assertRedirect();
        $this->void($invoice, ['reason_code' => 'other', 'reason' => 'autre'])->assertRedirect();

        $this->assertSame(1, AuditLog::query()->where('event', 'supplier_invoice.voided')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'finance_obligation.cancelled')->count());
        $this->assertSame('Mauvais montant saisi', $invoice->fresh()->void_reason);
    }

    public function test_reason_validation(): void
    {
        $draft = $this->draft();
        $this->void($draft, ['reason_code' => 'bogus'])->assertStatus(422)->assertJsonValidationErrors('reason_code');
        $this->void($draft, ['reason_code' => null])->assertStatus(422)->assertJsonValidationErrors('reason_code');
        $this->void($draft, ['reason_code' => 'other', 'reason' => '  '])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('draft', $draft->fresh()->status->value);

        $approved = $this->approved(['invoice_number' => 'FA-2', 'invoice_date' => '2026-03-01', 'installments' => [['due_date' => '2026-04-01', 'amount' => '120.00']]]);
        $this->void($approved, ['reason' => '  '])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('approved', $approved->fresh()->status->value);
    }

    public function test_authorization_visibility_and_inactive_store(): void
    {
        $invoice = $this->approved();
        $noPermission = $this->member(['supplier_invoices.view', 'supplier_invoices.approve']);
        $nonMember = $this->member(['supplier_invoices.view', 'supplier_invoices.void'], store: false);
        $voider = $this->member(['supplier_invoices.view', 'supplier_invoices.void']);

        $this->void($invoice, [], $noPermission)->assertForbidden();
        $this->void($invoice, [], $nonMember)->assertForbidden();

        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner, 'Foreign');
        $foreign = $this->makeInvoice($foreignOrg, $this->createStore($foreignOrg, $foreignOwner), $this->makeSupplier($foreignOrg));
        $this->void($foreign)->assertNotFound();

        config(['supplier_invoices.enabled' => false]);
        $this->void($invoice, [], $voider)->assertNotFound();
        config(['supplier_invoices.enabled' => true]);
        $this->assertSame('approved', $invoice->fresh()->status->value);

        // A member may still void after the store was deactivated (history stays correctable).
        $this->store->status = 'inactive';
        $this->store->save();
        $this->void($invoice, [], $voider)->assertRedirect();
        $this->assertSame('void', $invoice->fresh()->status->value);
    }

    public function test_test_mode_denies_the_void(): void
    {
        $invoice = $this->draft();
        $this->actingAs($this->owner)->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();

        $this->postJson(route('finance.supplier-invoices.void', $invoice), ['reason_code' => 'duplicate_entry'])
            ->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
        $this->assertSame('draft', $invoice->fresh()->status->value);
    }

    public function test_invoice_obligations_stay_protected_from_direct_cancellation(): void
    {
        $this->approved();
        $obligation = FinanceObligation::query()->sole();

        try {
            app(ManageFinanceObligationAction::class)->cancel($this->owner, $obligation, 'Direct');
            $this->fail('The manage action must refuse invoice obligations.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('obligation', $e->errors());
        }
        // The model guard refuses a direct write outside the sanctioned scope.
        $fresh = $obligation->fresh();
        $fresh->cancelled_at = now();
        $this->expectException(LogicException::class);
        $fresh->save();
    }

    public function test_can_void_flag_in_show(): void
    {
        $invoice = $this->approved();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.show', $invoice))->assertInertia(fn ($page) => $page->where('can.void', true));
        $this->void($invoice)->assertRedirect();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.show', $invoice))->assertInertia(fn ($page) => $page
            ->where('can.void', false)->where('invoice.status', 'void')->where('invoice.void.reason_code', 'wrong_data_entry')->where('invoice.void.reason', 'Mauvais montant saisi'));
    }
}
