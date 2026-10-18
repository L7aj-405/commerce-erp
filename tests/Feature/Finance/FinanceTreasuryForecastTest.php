<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceExpectedCollectionAction;
use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\SetFinancialAccountOpeningPositionAction;
use App\Actions\Finance\ManageFinancePaymentInstrumentAction;
use App\Models\FinanceOutgoingPayment;
use App\Models\FinancialAccountOpeningPosition;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Finance\FinancialAccountBalanceService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceTreasuryForecastService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

class FinanceTreasuryForecastTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withFreshAuthentication(level: 1);
    }

    public function test_opening_position_plus_posted_movements_is_the_authoritative_balance(): void
    {
        [$owner,$organization,$store,$order,$account] = $this->paymentFixture('100.0000');
        $this->travelTo('2026-10-17');
        app(SetFinancialAccountOpeningPositionAction::class)->execute($owner,$account,['effective_date'=>'2026-10-01','amount'=>'80.0000','currency_code'=>'MAD','reference'=>'BANK','notes'=>null]);
        $this->recordPayment($owner,$order,$account,'20.0000',['payment_date'=>'2026-10-10']);
        $this->refund($owner,$organization,$store,$order,$account,'3.0000');
        $this->outgoing($owner,$organization,$account,'7.0000','posted','2026-10-12');
        $this->outgoing($owner,$organization,$account,'9.0000','reversed','2026-10-13');

        $position = app(FinancialAccountBalanceService::class)->organizationPosition($organization, now());
        $this->assertTrue($position['complete']);
        $this->assertSame('90.0000',$position['total']);
        $this->assertSame('20.0000',$position['accounts'][0]['incoming']);
        $this->assertSame('3.0000',$position['accounts'][0]['refunds']);
        $this->assertSame('7.0000',$position['accounts'][0]['outgoing']);

        $historical = app(FinancialAccountBalanceService::class)->organizationPosition($organization, now()->setDate(2026,10,10));
        $this->assertSame('100.0000',$historical['total']);
        $beforeOpening = app(FinancialAccountBalanceService::class)->organizationPosition($organization, now()->setDate(2026,9,30));
        $this->assertFalse($beforeOpening['complete']);
        $this->assertNull($beforeOpening['total']);
    }

    public function test_unconfigured_active_account_makes_organization_position_incomplete(): void
    {
        [$owner,$organization,,,$account] = $this->paymentFixture();
        app(SetFinancialAccountOpeningPositionAction::class)->execute($owner,$account,['effective_date'=>'2026-10-01','amount'=>'100.0000','currency_code'=>'MAD']);
        $this->createFinancialAccount($organization,['code'=>'BANK-X','type'=>'bank']);
        $position=app(FinancialAccountBalanceService::class)->organizationPosition($organization,now());
        $this->assertFalse($position['complete']); $this->assertNull($position['total']); $this->assertSame(1,$position['unconfigured_count']);
    }

    public function test_expected_collections_are_capped_and_explicitly_matched(): void
    {
        [$owner,$organization,,$order,$account]=$this->paymentFixture('20000.0000');
        $action=app(ManageFinanceExpectedCollectionAction::class);
        $first=$action->create($owner,$organization,$this->expectedData($order->id,'10000.0000','2026-10-20'));
        $second=$action->create($owner,$organization,$this->expectedData($order->id,'10000.0000','2026-11-10'));
        try {$action->create($owner,$organization,$this->expectedData($order->id,'0.0001','2026-12-01'));$this->fail('Expected receivable cap.');} catch (ValidationException) {}
        $payment=$this->recordPayment($owner,$order,$account,'5000.0000',['payment_date'=>'2026-10-19']);
        $match=$action->match($owner,$first,$payment->allocations()->firstOrFail()->id,'5000.0000');
        $this->assertSame('5000.0000',(string)$match->amount);
        try {$action->cancel($owner,$first,'Incorrect');$this->fail('Matched forecast must retain history.');} catch (ValidationException) {}
        $action->cancel($owner,$second,'Report annulé');
        $this->assertSame('cancelled',$second->fresh()->state);
    }

    public function test_forecast_uses_current_balance_expected_cash_and_remaining_obligations_once(): void
    {
        [$owner,$organization,$store,$order,$account]=$this->paymentFixture('20000.0000');
        $this->travelTo('2026-10-17');
        app(SetFinancialAccountOpeningPositionAction::class)->execute($owner,$account,['effective_date'=>'2026-10-17','amount'=>'80000.0000','currency_code'=>'MAD']);
        app(ManageFinanceExpectedCollectionAction::class)->create($owner,$organization,$this->expectedData($order->id,'20000.0000','2026-10-20'));
        app(ManageFinanceObligationAction::class)->create($owner,$organization,['store_id'=>$store->id,'category'=>'supplier','label'=>'Fournisseur','supplier_id'=>null,'beneficiary_name'=>'Fournisseur','reference'=>null,'due_date'=>'2026-10-25','planned_amount'=>'110000.0000','currency_code'=>'MAD','planned_payment_method'=>null,'preferred_financial_account_id'=>null,'notes'=>null]);
        $forecast=app(FinanceTreasuryForecastService::class)->forecast($organization,FinancePeriod::fromMonth('2026-10'),null);
        $this->assertTrue($forecast['balance']['complete'], json_encode($forecast));
        $this->assertSame('20000.0000',$forecast['expected']); $this->assertSame('110000.0000',$forecast['remaining_outflows']);
        $this->assertSame('-10000.0000',$forecast['projected']); $this->assertSame('10000.0000',$forecast['shortage']);

        $past=app(FinanceTreasuryForecastService::class)->forecast($organization,FinancePeriod::fromMonth('2026-09'),null);
        $this->assertSame('historical',$past['mode']); $this->assertNull($past['projected']);
    }

    public function test_opening_position_route_requires_permission_and_fresh_auth(): void
    {
        [$owner,$organization,$store,,$account]=$this->paymentFixture();
        $viewer=User::factory()->create(); $this->addOrganizationMember($organization,$viewer,['financial_accounts.view']); $this->activate($viewer,$organization,$store);
        $this->actingAs($viewer)->put(route('financial-accounts.opening-position',$account),['effective_date'=>'2026-10-01','amount'=>'10.0000','currency_code'=>'MAD'])->assertForbidden();
        $this->actingAs($owner); session()->forget('auth.fresh.account_confirmed_at');
        $this->put(route('financial-accounts.opening-position',$account),['effective_date'=>'2026-10-01','amount'=>'10.0000','currency_code'=>'MAD'])->assertRedirect();
        $this->assertDatabaseCount('financial_account_opening_positions',0);
    }

    public function test_cleared_instrument_changes_cash_and_obligation_once_while_uncleared_instrument_does_not(): void
    {
        [$owner,$organization,$store,,$account]=$this->paymentFixture();$this->travelTo('2026-10-17');
        app(SetFinancialAccountOpeningPositionAction::class)->execute($owner,$account,['effective_date'=>'2026-10-17','amount'=>'100.0000','currency_code'=>'MAD']);
        $obligation=app(ManageFinanceObligationAction::class)->create($owner,$organization,['store_id'=>$store->id,'category'=>'supplier','label'=>'Dette','supplier_id'=>null,'beneficiary_name'=>'Fournisseur','reference'=>null,'due_date'=>'2026-10-20','planned_amount'=>'50.0000','currency_code'=>'MAD','planned_payment_method'=>'cheque','preferred_financial_account_id'=>$account->id,'notes'=>null]);
        $instrument=app(ManageFinancePaymentInstrumentAction::class)->create($owner,$obligation,['type'=>'cheque','external_reference'=>'CHQ-X','amount'=>'50.0000','issue_date'=>'2026-10-17','maturity_date'=>'2026-10-20','financial_account_id'=>$account->id,'notes'=>null]);
        $before=app(FinanceTreasuryForecastService::class)->forecast($organization,FinancePeriod::fromMonth('2026-10'),null);
        $this->assertSame('100.0000',$before['balance']['total']);$this->assertSame('50.0000',$before['remaining_outflows']);$this->assertSame('50.0000',$before['projected']);
        app(ManageFinancePaymentInstrumentAction::class)->clear($owner,$instrument);
        $after=app(FinanceTreasuryForecastService::class)->forecast($organization,FinancePeriod::fromMonth('2026-10'),null);
        $this->assertSame('50.0000',$after['balance']['total']);$this->assertSame('0.0000',$after['remaining_outflows']);$this->assertSame('50.0000',$after['projected']);
    }

    public function test_multiple_accounts_future_horizon_and_cross_tenant_forecast_are_safe(): void
    {
        [$owner,$organization,$store,$order,$account]=$this->paymentFixture('20.0000');$this->travelTo('2026-10-17');
        app(SetFinancialAccountOpeningPositionAction::class)->execute($owner,$account,['effective_date'=>'2026-10-17','amount'=>'100.0000','currency_code'=>'MAD']);
        $second=$this->createFinancialAccount($organization,['code'=>'BANK-2','type'=>'bank']);
        app(SetFinancialAccountOpeningPositionAction::class)->execute($owner,$second,['effective_date'=>'2026-10-17','amount'=>'50.0000','currency_code'=>'MAD']);
        app(ManageFinanceExpectedCollectionAction::class)->create($owner,$organization,$this->expectedData($order->id,'20.0000','2026-11-10'));
        app(ManageFinanceObligationAction::class)->create($owner,$organization,['store_id'=>$store->id,'category'=>'other','label'=>'Novembre','supplier_id'=>null,'beneficiary_name'=>'Tiers','reference'=>null,'due_date'=>'2026-11-20','planned_amount'=>'40.0000','currency_code'=>'MAD','planned_payment_method'=>null,'preferred_financial_account_id'=>null,'notes'=>null]);
        $future=app(FinanceTreasuryForecastService::class)->forecast($organization,FinancePeriod::fromMonth('2026-11'),null);
        $this->assertSame('future',$future['mode']);$this->assertSame('150.0000',$future['balance']['total']);$this->assertSame('130.0000',$future['projected']);

        [$foreignOwner,$foreignOrganization,, $foreignOrder]=$this->paymentFixture('10.0000');
        try {app(ManageFinanceExpectedCollectionAction::class)->create($owner,$organization,$this->expectedData($foreignOrder->id,'1.0000','2026-11-10'));$this->fail('Expected tenant rejection.');} catch (ValidationException) {}
        $this->assertNotSame($foreignOwner->id,$owner->id);$this->assertNotSame($foreignOrganization->id,$organization->id);
    }

    private function expectedData(int $orderId,string $amount,string $date):array
    {return ['sales_order_id'=>$orderId,'label'=>'Règlement client','reference'=>null,'expected_date'=>$date,'expected_amount'=>$amount,'notes'=>null];}

    private function refund($owner,$organization,$store,$order,$account,string $amount):void
    {
        $payment=$order->paymentAllocations()->firstOrFail()->payment;
        $refund=new PaymentRefund; $refund->organization_id=$organization->id;$refund->store_id=$store->id;$refund->payment_id=$payment->id;$refund->sales_order_id=$order->id;
        $refund->financial_account_id=$account->id;$refund->refund_number='REF-TEST';$refund->method='cash';$refund->status='posted';$refund->amount=$amount;$refund->currency_code='MAD';$refund->refund_date='2026-10-11';$refund->reason='Test';$refund->refunded_by_user_id=$owner->id;$refund->save();
    }

    private function outgoing($owner,$organization,$account,string $amount,string $status,string $date):void
    {
        $row=new FinanceOutgoingPayment;$row->organization_id=$organization->id;$row->payment_number='OUT-'.Str::random(8);$row->financial_account_id=$account->id;
        $row->beneficiary_name='Test';$row->payment_method='bank_transfer';$row->amount=$amount;$row->currency_code='MAD';$row->payment_date=$date;$row->status=$status;
        $row->idempotency_key=(string)Str::uuid();$row->recorded_by_user_id=$owner->id;if($status==='reversed')$row->reversed_at=now();$row->save();
    }
}
