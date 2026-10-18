<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceSupplierInstallmentPlanAction;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PaymentTestCase;

class FinanceSupplierInstallmentPageTest extends PaymentTestCase
{
    public function test_supplier_aggregates_and_future_installments_are_presented_without_changing_month_kpis(): void
    {
        $owner=User::factory()->create();$organization=$this->createOrganization($owner);$store=$this->createStore($organization,$owner);$this->activate($owner,$organization,$store);
        $supplier=new Supplier;$supplier->organization_id=$organization->id;$supplier->name='MIFA';$supplier->active=true;$supplier->save();
        app(ManageFinanceSupplierInstallmentPlanAction::class)->create($owner,$organization,$this->data($supplier->id,$store->id));
        $this->travelTo('2026-10-17');
        $this->actingAs($owner)->get(route('finance.monthly-situation.installments.index'))->assertOk()->assertInertia(fn(Assert $page)=>$page
            ->component('Finance/SupplierInstallments')->has('plans.data',1)->where('plans.data.0.total','120000.0000')->where('plans.data.0.paid','0.0000')->where('plans.data.0.remaining','120000.0000')->where('plans.data.0.committed','0.0000'));
        $this->actingAs($owner)->get(route('finance.monthly-situation.index',['month'=>'2026-10']))->assertOk()->assertInertia(fn(Assert $page)=>$page
            ->where('kpis.planned','40000.0000')->has('futureObligations',2)->where('futureObligations.0.amount','30000.0000'));
    }

    public function test_view_permission_can_read_but_cannot_create_supplier_schedule(): void
    {
        $owner=User::factory()->create();$viewer=User::factory()->create();$organization=$this->createOrganization($owner);$store=$this->createStore($organization,$owner);
        $supplier=new Supplier;$supplier->organization_id=$organization->id;$supplier->name='Supplier';$supplier->active=true;$supplier->save();
        $this->addOrganizationMember($organization,$viewer,['finance.monthly_situation.view']);$this->activate($viewer,$organization,$store);
        $this->actingAs($viewer)->get(route('finance.monthly-situation.installments.index'))->assertOk();
        $this->actingAs($viewer)->post(route('finance.monthly-situation.installments.store'),$this->data($supplier->id,$store->id))->assertForbidden();
    }

    private function data(int $supplierId,int $storeId):array{return ['supplier_id'=>$supplierId,'sales_order_procurement_id'=>null,'store_id'=>$storeId,'label'=>'Facture fournisseur','reference'=>'INV-77','agreed_total'=>'120000.0000','currency_code'=>'MAD','planned_payment_method'=>'cheque','preferred_financial_account_id'=>null,'notes'=>null,'installments'=>[['due_date'=>'2026-10-20','amount'=>'40000.0000'],['due_date'=>'2026-11-20','amount'=>'30000.0000'],['due_date'=>'2026-12-20','amount'=>'50000.0000']]];}
}
