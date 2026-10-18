<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\ManageFinancePaymentInstrumentAction;
use App\Actions\Finance\ManageFinanceSupplierInstallmentPlanAction;
use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Actions\Finance\ReverseFinanceOutgoingPaymentAction;
use App\Enums\FinancePaymentInstrumentState;
use App\Models\FinancePaymentInstrument;
use App\Models\FinanceSupplierInstallmentPlan;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

class FinanceSupplierInstallmentInstrumentTest extends PaymentTestCase
{
    protected function setUp(): void { parent::setUp(); $this->withFreshAuthentication(level: 1); }

    public function test_three_installment_supplier_schedule_is_atomic_and_exact(): void
    {
        [$owner, $organization, $store, $account, $supplier] = $this->fixture();
        $plan = app(ManageFinanceSupplierInstallmentPlanAction::class)->create($owner, $organization, $this->planData($supplier->id, $store->id, $account->id));
        $this->assertSame('ECH-2026-000001', $plan->plan_number); $this->assertSame('120000.0000', $plan->agreed_total);
        $this->assertSame(['40000.0000','30000.0000','50000.0000'], $plan->obligations->pluck('planned_amount')->all());
        $this->assertSame(['2026-10-20','2026-11-20','2026-12-20'], $plan->obligations->pluck('due_date')->map->toDateString()->all());
        $this->assertTrue($plan->obligations->every(fn ($row) => $row->supplier_id === $supplier->id && $row->store_id === $store->id));

        $originalIds = $plan->obligations->pluck('id')->all();
        $updatedData = $this->planData($supplier->id, $store->id, $account->id);
        $updatedData['installments'][0]['amount'] = '35000.0000'; $updatedData['installments'][1]['amount'] = '35000.0000';
        $updated = app(ManageFinanceSupplierInstallmentPlanAction::class)->update($owner, $plan, $updatedData);
        $this->assertSame($originalIds, $updated->obligations->pluck('id')->all());
        $this->assertSame(['35000.0000','35000.0000','50000.0000'], $updated->obligations->pluck('planned_amount')->all());

        try { app(ManageFinanceSupplierInstallmentPlanAction::class)->create($owner, $organization, $this->planData($supplier->id, $store->id, $account->id, ['agreed_total' => '120000.0001'])); $this->fail('Expected exact sum validation.'); }
        catch (ValidationException) {}
        $this->assertDatabaseCount('finance_supplier_installment_plans', 1); $this->assertDatabaseCount('finance_obligations', 3);

        [$foreignOwner, $foreignOrganization, , , $foreignSupplier] = $this->fixture();
        try { app(ManageFinanceSupplierInstallmentPlanAction::class)->create($owner, $organization, $this->planData($foreignSupplier->id, null, null)); $this->fail('Expected supplier tenant validation.'); }
        catch (ValidationException) {}
        $this->assertNotSame($foreignOwner->id, $owner->id); $this->assertNotSame($foreignOrganization->id, $organization->id);
    }

    public function test_cheque_and_traite_commitment_prevent_double_settlement_and_cancel_releases_capacity(): void
    {
        [$owner, $organization, , $account] = $this->fixture(); $obligation = $this->obligation($owner, $organization);
        $this->payment($owner, $organization, $account->id, $obligation->id, '20000.0000');
        $action = app(ManageFinancePaymentInstrumentAction::class);
        $cheque = $action->create($owner, $obligation, $this->instrumentData($account->id, 'cheque', '50000.0000'));
        $this->assertSame('CHQ-2026-000001', $cheque->instrument_number); $this->assertDatabaseCount('finance_outgoing_payments', 1);

        try { $this->payment($owner, $organization, $account->id, $obligation->id, '50000.0000'); $this->fail('Expected committed-capacity validation.'); }
        catch (ValidationException) {}
        try { $action->create($owner, $obligation, $this->instrumentData($account->id, 'traite', '30000.0001')); $this->fail('Expected instrument overcommit validation.'); }
        catch (ValidationException) {}

        $action->cancel($owner, $cheque, 'Remplacé par une traite');
        $traite = $action->create($owner, $obligation, $this->instrumentData($account->id, 'traite', '80000.0000', 'TRT-EXT'));
        $this->assertSame('TRT-2026-000002', $traite->instrument_number); $this->assertSame(FinancePaymentInstrumentState::Issued, $traite->state);
    }

    public function test_clearing_is_idempotent_posts_one_cash_movement_and_reversal_marks_instrument_returned(): void
    {
        [$owner, $organization, , $account] = $this->fixture(); $obligation = $this->obligation($owner, $organization, '100000.0000');
        $action = app(ManageFinancePaymentInstrumentAction::class);
        $instrument = $action->create($owner, $obligation, $this->instrumentData($account->id, 'cheque', '40000.0000'));
        $action->present($owner, $instrument);
        $cleared = $action->clear($owner, $instrument->fresh());
        $again = $action->clear($owner, $cleared->fresh());
        $this->assertSame($cleared->outgoing_payment_id, $again->outgoing_payment_id);
        $this->assertDatabaseCount('finance_outgoing_payments', 1); $this->assertDatabaseCount('finance_outgoing_payment_allocations', 1);
        $this->assertSame('cheque', $cleared->outgoingPayment->payment_method->value);

        app(ReverseFinanceOutgoingPaymentAction::class)->execute($owner, $cleared->outgoingPayment, 'Chèque rejeté');
        $this->assertSame(FinancePaymentInstrumentState::Returned, $instrument->fresh()->state);
        $this->assertNotNull($instrument->fresh()->returned_at);
        $this->assertSame('0.0000', app(\App\Services\Finance\FinanceObligationCalculator::class)->paid($obligation->fresh()));
    }

    public function test_plan_cannot_be_restructured_after_payment_or_instrument_commitment(): void
    {
        [$owner, $organization, $store, $account, $supplier] = $this->fixture();
        $action = app(ManageFinanceSupplierInstallmentPlanAction::class); $plan = $action->create($owner, $organization, $this->planData($supplier->id, $store->id, $account->id));
        app(ManageFinancePaymentInstrumentAction::class)->create($owner, $plan->obligations->first(), $this->instrumentData($account->id, 'cheque', '10000.0000'));
        try { $action->update($owner, $plan, $this->planData($supplier->id, $store->id, $account->id)); $this->fail('Expected committed schedule immutability.'); }
        catch (ValidationException) {}
        $this->assertDatabaseCount('finance_obligations', 3);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(); $organization = $this->createOrganization($owner); $store = $this->createStore($organization, $owner); $this->activate($owner, $organization, $store);
        $account = $this->createFinancialAccount($organization, ['type' => 'bank', 'code' => 'BANK-'.Str::random(5), 'accepted_methods' => ['bank_transfer','cheque']]);
        $supplier = new Supplier; $supplier->organization_id = $organization->id; $supplier->name = 'MIFA '.Str::random(4); $supplier->active = true; $supplier->save();
        return [$owner,$organization,$store,$account,$supplier];
    }
    private function obligation(User $owner, $organization, string $amount = '100000.0000')
    { return app(ManageFinanceObligationAction::class)->create($owner, $organization, ['store_id'=>null,'category'=>'supplier','label'=>'Dette fournisseur','supplier_id'=>null,'beneficiary_name'=>'Fournisseur','reference'=>null,'due_date'=>'2026-10-20','planned_amount'=>$amount,'currency_code'=>'MAD','planned_payment_method'=>'cheque','preferred_financial_account_id'=>null,'notes'=>null]); }
    private function payment(User $owner, $organization, int $accountId, int $obligationId, string $amount)
    { return app(RecordFinanceOutgoingPaymentAction::class)->execute($owner,$organization,['store_id'=>null,'financial_account_id'=>$accountId,'supplier_id'=>null,'beneficiary_name'=>'Fournisseur','payment_method'=>'bank_transfer','amount'=>$amount,'currency_code'=>'MAD','payment_date'=>'2026-10-10','reference'=>null,'notes'=>null,'idempotency_key'=>(string)Str::uuid(),'allocations'=>[['obligation_id'=>$obligationId,'amount'=>$amount]]]); }
    private function instrumentData(int $accountId, string $type, string $amount, string $reference = 'EXT-001'): array
    { return ['type'=>$type,'external_reference'=>$reference,'amount'=>$amount,'issue_date'=>'2026-10-01','maturity_date'=>'2026-10-20','financial_account_id'=>$accountId,'notes'=>null]; }
    private function planData(int $supplierId, ?int $storeId, ?int $accountId, array $overrides = []): array
    { return array_replace(['supplier_id'=>$supplierId,'sales_order_procurement_id'=>null,'store_id'=>$storeId,'label'=>'Facture fournisseur','reference'=>'INV-2026-77','agreed_total'=>'120000.0000','currency_code'=>'MAD','planned_payment_method'=>'cheque','preferred_financial_account_id'=>$accountId,'notes'=>null,'installments'=>[['due_date'=>'2026-10-20','amount'=>'40000.0000'],['due_date'=>'2026-11-20','amount'=>'30000.0000'],['due_date'=>'2026-12-20','amount'=>'50000.0000']]],$overrides); }
}
