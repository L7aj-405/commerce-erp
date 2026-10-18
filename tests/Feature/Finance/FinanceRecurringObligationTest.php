<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\GenerateRecurringFinanceObligationsAction;
use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\ManageFinanceRecurringTemplateAction;
use App\Models\FinanceRecurringObligationTemplate;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PaymentTestCase;

class FinanceRecurringObligationTest extends PaymentTestCase
{
    public function test_template_lifecycle_validation_and_tenant_scoping(): void
    {
        [$owner, $organization, $store, $account, $supplier] = $this->fixture();
        $template = $this->template($owner, $organization, [
            'store_id' => $store->id, 'supplier_id' => $supplier->id, 'preferred_financial_account_id' => $account->id,
        ]);
        $this->assertSame('CRF-2026-000001', $template->template_number);
        $this->assertSame('10000.0000', $template->planned_amount);

        $action = app(ManageFinanceRecurringTemplateAction::class);
        $updated = $action->update($owner, $template, $this->data(['label' => 'Loyer principal', 'planned_amount' => '12000.0000']));
        $this->assertSame('Loyer principal', $updated->label);
        $action->deactivate($owner, $updated);
        $this->assertFalse($updated->fresh()->active);
        $action->reactivate($owner, $updated->fresh());
        $this->assertTrue($updated->fresh()->active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance_recurring_template.reactivated', 'auditable_id' => (string) $template->id]);

        foreach ([
            ['end_date' => '2026-09-30'], ['due_day' => 29], ['due_day_mode' => 'end_of_month', 'due_day' => 15],
        ] as $invalid) {
            try { $this->template($owner, $organization, $invalid); $this->fail('Expected recurring template validation.'); }
            catch (ValidationException) {}
        }

        [$foreignOwner, $foreignOrganization, $foreignStore, $foreignAccount, $foreignSupplier] = $this->fixture();
        foreach ([['store_id' => $foreignStore->id], ['supplier_id' => $foreignSupplier->id], ['preferred_financial_account_id' => $foreignAccount->id]] as $foreign) {
            try { $this->template($owner, $organization, $foreign); $this->fail('Expected tenant reference rejection.'); }
            catch (ValidationException) {}
        }
        $this->assertNotSame($foreignOwner->id, $owner->id);
        $this->assertNotSame($foreignOrganization->id, $organization->id);
    }

    public function test_generation_is_idempotent_and_occurrences_are_historical_snapshots(): void
    {
        [$owner, $organization, $store, , $supplier] = $this->fixture();
        $template = $this->template($owner, $organization, ['store_id' => $store->id, 'supplier_id' => $supplier->id]);
        $generator = app(GenerateRecurringFinanceObligationsAction::class);

        $first = $generator->execute($organization, '2026-10', $owner);
        $again = $generator->execute($organization, '2026-10', $owner);
        $this->assertSame(['created' => 1, 'existing' => 0, 'skipped' => 0], $first);
        $this->assertSame(['created' => 0, 'existing' => 1, 'skipped' => 0], $again);
        $october = $template->obligations()->firstOrFail();
        $this->assertSame('10000.0000', $october->planned_amount);
        $this->assertSame('2026-10', $october->recurring_period->format('Y-m'));
        $this->assertSame($store->id, $october->store_id);
        $this->assertSame($supplier->id, $october->supplier_id);

        app(ManageFinanceRecurringTemplateAction::class)->update($owner, $template, $this->data(['planned_amount' => '12000.0000']));
        app(ManageFinanceObligationAction::class)->update($owner, $october, [
            'store_id' => $store->id, 'category' => 'rent', 'label' => 'Loyer exceptionnel', 'supplier_id' => $supplier->id,
            'beneficiary_name' => 'Bailleur', 'reference' => null, 'due_date' => '2026-10-15', 'planned_amount' => '11000.0000',
            'currency_code' => 'MAD', 'planned_payment_method' => 'bank_transfer', 'preferred_financial_account_id' => null, 'notes' => null,
        ]);
        $generator->execute($organization, '2026-11', $owner);

        $this->assertSame('11000.0000', $october->fresh()->planned_amount);
        $this->assertSame('12000.0000', $template->fresh()->planned_amount);
        $this->assertSame('12000.0000', $template->obligations()->whereDate('recurring_period', '2026-11-01')->firstOrFail()->planned_amount);
        $this->assertDatabaseCount('finance_obligations', 2);
    }

    public function test_due_dates_start_end_and_active_rules_are_deterministic(): void
    {
        [$owner, $organization] = $this->fixture();
        $generator = app(GenerateRecurringFinanceObligationsAction::class);
        $fixedOne = $this->template($owner, $organization, ['label' => 'Jour 1', 'due_day' => 1]);
        $fixed28 = $this->template($owner, $organization, ['label' => 'Jour 28', 'due_day' => 28]);
        $monthEnd = $this->template($owner, $organization, ['label' => 'Fin de mois', 'due_day_mode' => 'end_of_month', 'due_day' => null]);
        $midMonth = $this->template($owner, $organization, ['label' => 'Début différé', 'start_date' => '2026-10-20', 'due_day' => 10]);
        $ended = $this->template($owner, $organization, ['label' => 'Terminée', 'end_date' => '2026-10-31']);
        $inactive = $this->template($owner, $organization, ['label' => 'Inactive']);
        app(ManageFinanceRecurringTemplateAction::class)->deactivate($owner, $inactive);

        $generator->execute($organization, '2026-10', $owner);
        $this->assertSame('2026-10-01', $fixedOne->obligations()->firstOrFail()->due_date->toDateString());
        $this->assertSame('2026-10-28', $fixed28->obligations()->firstOrFail()->due_date->toDateString());
        $this->assertSame('2026-10-31', $monthEnd->obligations()->firstOrFail()->due_date->toDateString());
        $this->assertFalse($midMonth->obligations()->whereDate('recurring_period', '2026-10-01')->exists());
        $this->assertDatabaseMissing('finance_obligations', ['recurring_template_id' => $inactive->id]);

        $generator->execute($organization, '2026-11', $owner);
        $this->assertSame('2026-11-10', $midMonth->obligations()->firstOrFail()->due_date->toDateString());
        $this->assertFalse($ended->obligations()->whereDate('recurring_period', '2026-11-01')->exists());

        $generator->execute($organization, '2028-02', $owner);
        $this->assertSame('2028-02-29', $monthEnd->obligations()->whereDate('recurring_period', '2028-02-01')->firstOrFail()->due_date->toDateString());
        $generator->execute($organization, '2027-02', $owner);
        $this->assertSame('2027-02-28', $monthEnd->obligations()->whereDate('recurring_period', '2027-02-01')->firstOrFail()->due_date->toDateString());
    }

    public function test_page_permissions_manual_generation_and_scheduler_are_safe_and_read_only_on_get(): void
    {
        [$owner, $organization] = $this->fixture();
        $template = $this->template($owner, $organization);
        $this->actingAs($owner)->get(route('finance.monthly-situation.recurring.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Finance/RecurringObligations')->has('templates.data', 1));
        $this->assertDatabaseCount('finance_obligations', 0);

        $this->actingAs($owner)->post(route('finance.monthly-situation.recurring.generate'), ['month' => '2026-10'])->assertRedirect();
        $this->assertTrue($template->obligations()->whereDate('recurring_period', '2026-10-01')->exists());

        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['finance.monthly_situation.view']);
        $this->activate($viewer, $organization);
        $this->actingAs($viewer)->get(route('finance.monthly-situation.recurring.index'))->assertOk();
        $this->actingAs($viewer)->post(route('finance.monthly-situation.recurring.generate'), ['month' => '2026-11'])->assertForbidden();

        $this->travelTo(Carbon::parse('2026-12-05'));
        $this->assertSame(0, Artisan::call('finance:generate-recurring-obligations'));
        $this->assertSame(0, Artisan::call('finance:generate-recurring-obligations'));
        $this->assertSame(1, $template->obligations()->whereDate('recurring_period', '2026-12-01')->count());
        $this->assertFalse($template->obligations()->whereDate('recurring_period', '2027-01-01')->exists());
    }

    /** @return array{User,\App\Models\Organization,\App\Models\Store,\App\Models\FinancialAccount,Supplier} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $account = $this->createFinancialAccount($organization, ['type' => 'bank', 'code' => 'BANK-'.str()->random(5), 'accepted_methods' => ['bank_transfer']]);
        $supplier = new Supplier; $supplier->organization_id = $organization->id; $supplier->name = 'Supplier '.str()->random(5); $supplier->active = true; $supplier->save();
        return [$owner, $organization, $store, $account, $supplier];
    }

    private function template(User $owner, $organization, array $overrides = []): FinanceRecurringObligationTemplate
    {
        return app(ManageFinanceRecurringTemplateAction::class)->create($owner, $organization, $this->data($overrides));
    }

    private function data(array $overrides = []): array
    {
        return array_replace([
            'store_id' => null, 'category' => 'rent', 'label' => 'Loyer', 'supplier_id' => null, 'beneficiary_name' => 'Bailleur',
            'reference' => null, 'planned_amount' => '10000.0000', 'currency_code' => 'MAD', 'planned_payment_method' => 'bank_transfer',
            'preferred_financial_account_id' => null, 'due_day_mode' => 'fixed', 'due_day' => 15, 'start_date' => '2026-10-01',
            'end_date' => null, 'active' => true, 'notes' => null,
        ], $overrides);
    }
}
