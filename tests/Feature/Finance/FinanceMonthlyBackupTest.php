<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\GenerateRecurringFinanceObligationsAction;
use App\Actions\Finance\ManageFinanceRecurringTemplateAction;
use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Models\FinanceObligation;
use App\Models\FinanceOutgoingPayment;
use App\Models\FinanceRecurringObligationTemplate;
use App\Actions\Finance\ManageFinanceSupplierInstallmentPlanAction;
use App\Actions\Finance\ManageFinancePaymentInstrumentAction;
use App\Models\FinanceSupplierInstallmentPlan;
use App\Models\FinancePaymentInstrument;
use App\Models\Supplier;
use App\Models\FinancialAccountOpeningPosition;
use App\Models\FinanceExpectedCollection;
use App\Models\FinanceMonthReview;
use App\Actions\Finance\SetFinancialAccountOpeningPositionAction;
use App\Actions\Finance\ManageFinanceExpectedCollectionAction;
use App\Actions\Finance\ManageFinanceMonthReviewAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\User;
use App\Services\OrganizationBackups\OrganizationBackupExporter;
use App\Services\OrganizationBackups\OrganizationBackupRestorer;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Str;
use Tests\Support\PaymentTestCase;

class FinanceMonthlyBackupTest extends PaymentTestCase
{
    public function test_backup_and_restore_preserve_obligations_outgoing_payments_and_allocations(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $account = $this->createFinancialAccount($organization, ['type' => 'bank', 'code' => 'BANK', 'accepted_methods' => ['bank_transfer']]);
        $template = app(ManageFinanceRecurringTemplateAction::class)->create($owner, $organization, [
            'store_id' => $store->id, 'category' => 'subscription', 'label' => 'SaaS mensuel', 'supplier_id' => null,
            'beneficiary_name' => 'Editeur', 'reference' => 'SAAS', 'planned_amount' => '800.0000', 'currency_code' => 'MAD',
            'planned_payment_method' => 'bank_transfer', 'preferred_financial_account_id' => $account->id,
            'due_day_mode' => 'fixed', 'due_day' => 5, 'start_date' => '2026-10-01', 'end_date' => null, 'active' => true, 'notes' => null,
        ]);
        $recurringObligation = app(GenerateRecurringFinanceObligationsAction::class)->execute($organization, '2026-10', $owner);
        $this->assertSame(1, $recurringObligation['created']);
        $supplier = new Supplier; $supplier->organization_id = $organization->id; $supplier->name = 'Backup Supplier'; $supplier->active = true; $supplier->save();
        $plan = app(ManageFinanceSupplierInstallmentPlanAction::class)->create($owner, $organization, [
            'supplier_id'=>$supplier->id,'sales_order_procurement_id'=>null,'store_id'=>$store->id,'label'=>'Échéance sauvegardée','reference'=>'SUP-1','agreed_total'=>'1000.0000','currency_code'=>'MAD','planned_payment_method'=>'cheque','preferred_financial_account_id'=>$account->id,'notes'=>null,
            'installments'=>[['due_date'=>'2026-11-10','amount'=>'1000.0000']],
        ]);
        $instrument = app(ManageFinancePaymentInstrumentAction::class)->create($owner, $plan->obligations->first(), ['type'=>'cheque','external_reference'=>'CHQ-BACKUP','amount'=>'500.0000','issue_date'=>'2026-10-20','maturity_date'=>'2026-11-10','financial_account_id'=>$account->id,'notes'=>null]);
        $obligation = app(ManageFinanceObligationAction::class)->create($owner, $organization, [
            'store_id' => $store->id, 'category' => 'tax', 'label' => 'TVA octobre', 'supplier_id' => null,
            'beneficiary_name' => 'Administration fiscale', 'reference' => 'TAX-10', 'due_date' => '2026-10-31',
            'planned_amount' => '5000.0000', 'currency_code' => 'MAD', 'planned_payment_method' => 'bank_transfer',
            'preferred_financial_account_id' => $account->id, 'notes' => null,
        ]);
        $payment = app(RecordFinanceOutgoingPaymentAction::class)->execute($owner, $organization, [
            'store_id' => $store->id, 'financial_account_id' => $account->id, 'supplier_id' => null,
            'beneficiary_name' => 'Administration fiscale', 'payment_method' => 'bank_transfer', 'amount' => '2000.0000',
            'currency_code' => 'MAD', 'payment_date' => '2026-10-20', 'reference' => 'VIR-10', 'notes' => null,
            'idempotency_key' => (string) Str::uuid(), 'allocations' => [['obligation_id' => $obligation->id, 'amount' => '2000.0000']],
        ]);
        $opening = app(SetFinancialAccountOpeningPositionAction::class)->execute($owner,$account,['effective_date'=>'2026-10-01','amount'=>'10000.0000','currency_code'=>'MAD','reference'=>'OPEN']);
        $order=$this->createDraftOrder($owner,$organization,$store);$this->addCustomLine($owner,$order,['unit_price_excl_tax'=>'1000.0000']);$order=app(ConfirmSalesOrderAction::class)->execute($owner,$order)->fresh();
        $expected=app(ManageFinanceExpectedCollectionAction::class)->create($owner,$organization,['sales_order_id'=>$order->id,'label'=>'Attendu','reference'=>'EXP','expected_date'=>'2026-10-25','expected_amount'=>'500.0000','notes'=>null]);
        $monthReview=app(ManageFinanceMonthReviewAction::class)->review($owner,$organization,FinancePeriod::fromMonth('2026-10'),'Revue sauvegardée');

        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $this->assertContains('finance_obligations', $archive->manifest['tables']);
        $this->assertContains('finance_outgoing_payments', $archive->manifest['tables']);
        $this->assertContains('finance_outgoing_payment_allocations', $archive->manifest['tables']);
        $this->assertContains('finance_recurring_obligation_templates', $archive->manifest['tables']);
        $this->assertContains('finance_supplier_installment_plans', $archive->manifest['tables']);
        $this->assertContains('finance_payment_instruments', $archive->manifest['tables']);
        $this->assertContains('financial_account_opening_positions', $archive->manifest['tables']);
        $this->assertContains('finance_expected_collections', $archive->manifest['tables']);
        $this->assertContains('finance_month_reviews', $archive->manifest['tables']);

        $obligation->label = 'Mutation après sauvegarde'; $obligation->save();
        $payment->reference = 'MUTATED'; $payment->save();
        $template->label = 'Mutation template'; $template->save();
        $plan->reference = 'MUTATED-PLAN'; $plan->save(); $instrument->external_reference = 'MUTATED-CHQ'; $instrument->save();
        $opening->amount='1.0000';$opening->save();$expected->reference='MUTATED-EXP';$expected->save();$monthReview->note='MUTATED-REVIEW';$monthReview->save();
        app(OrganizationBackupRestorer::class)->restore($archive->path, $organization, $owner);

        $this->assertSame('TVA octobre', FinanceObligation::query()->whereKey($obligation->id)->firstOrFail()->label);
        $this->assertSame('VIR-10', FinanceOutgoingPayment::query()->whereKey($payment->id)->firstOrFail()->reference);
        $this->assertSame('SaaS mensuel', FinanceRecurringObligationTemplate::query()->whereKey($template->id)->firstOrFail()->label);
        $this->assertTrue($template->obligations()->whereDate('recurring_period', '2026-10-01')->exists());
        $this->assertSame('SUP-1', FinanceSupplierInstallmentPlan::query()->whereKey($plan->id)->firstOrFail()->reference);
        $this->assertSame('CHQ-BACKUP', FinancePaymentInstrument::query()->whereKey($instrument->id)->firstOrFail()->external_reference);
        $this->assertSame('10000.0000',(string)FinancialAccountOpeningPosition::query()->whereKey($opening->id)->firstOrFail()->amount);
        $this->assertSame('EXP',FinanceExpectedCollection::query()->whereKey($expected->id)->firstOrFail()->reference);
        $this->assertSame('Revue sauvegardée',FinanceMonthReview::query()->whereKey($monthReview->id)->firstOrFail()->note);
        $this->assertDatabaseHas('finance_outgoing_payment_allocations', ['outgoing_payment_id' => $payment->id, 'obligation_id' => $obligation->id, 'amount' => 2000]);
    }
}
