<?php

namespace Tests\Feature\FollowUps;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Models\FollowUp;
use App\Models\FollowUpRecurringRule;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** W3.1: creating recurring rules from customer / quotation context (existing W1/W3 validation). */
class FollowUpRecurringContextTest extends QuotationTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, Organization, Store, User} owner, organization, store, seller */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => 'UTC'];
        $organization->save();
        $store = $this->createStore($organization, $owner, 'Store A');
        $this->activate($owner, $organization, $store);
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);
        Carbon::setTestNow('2026-11-02 08:00:00');

        return [$owner, $organization->fresh(), $store, $seller];
    }

    private function quotationIn(User $owner, Organization $organization, Store $store, Store $back, array $data = []): Quotation
    {
        $this->activate($owner, $organization, $store);
        $quotation = $this->createQuotation($owner, $organization, $store, $data);
        $this->activate($owner, $organization, $back);

        return $quotation;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function payload(array $extra): array
    {
        return ['title' => 'Relance', 'frequency' => 'weekly', 'weekdays' => [1], 'starts_on' => '2026-11-02', ...$extra];
    }

    public function test_customer_page_prefills_and_creates_a_customer_linked_rule(): void
    {
        [, $organization, , $seller] = $this->fixture();
        $customer = $this->createCustomer($organization, 'ACME');

        $this->actingAs($seller)->get(route('sales.customers.show', $customer))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('followUps.prefill.customer_id', (int) $customer->getKey())
                ->where('followUps.prefill.quotation_id', null)->where('followUps.prefill_labels.customer', 'ACME'));

        // Tampered organization/store fields are not accepted inputs.
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Foreign');
        $this->actingAs($seller)->post(route('follow-ups.recurring.store'), $this->payload([
            'customer_id' => $customer->getKey(), 'organization_id' => $foreign->getKey(), 'store_id' => 999,
        ]))->assertSessionHasNoErrors();

        $rule = FollowUpRecurringRule::query()->sole();
        $this->assertSame((int) $organization->getKey(), (int) $rule->organization_id);
        $this->assertSame((int) $customer->getKey(), (int) $rule->customer_id);
        $this->assertNull($rule->store_id);
        $this->assertSame((int) $seller->getKey(), (int) $rule->assigned_user_id);
        $this->assertSame((int) $customer->getKey(), (int) FollowUp::query()->where('recurring_rule_id', $rule->getKey())->firstOrFail()->customer_id);

        // Another organization's customer id is revalidated server-side.
        $foreignCustomer = $this->createCustomer($foreign, 'Foreign customer');
        $this->actingAs($seller)->post(route('follow-ups.recurring.store'), $this->payload(['customer_id' => $foreignCustomer->getKey()]))
            ->assertSessionHasErrors('customer_id');
    }

    public function test_quotation_page_creates_a_rule_with_derived_store_and_customer(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $customer = $this->createCustomer($organization, 'ACME');
        $other = $this->createCustomer($organization, 'Other');
        $quotation = $this->createQuotation($owner, $organization, $store, ['customer_id' => $customer->getKey()]);

        $this->actingAs($owner)->get(route('quotations.show', $quotation))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('followUps.prefill.quotation_id', (int) $quotation->getKey())
                ->where('followUps.prefill.customer_id', (int) $customer->getKey())->where('followUps.prefill_labels.customer', 'ACME'));

        $this->actingAs($owner)->post(route('follow-ups.recurring.store'), $this->payload(['quotation_id' => $quotation->getKey(), 'customer_id' => $other->getKey()]))
            ->assertSessionHasErrors('customer_id');
        $this->assertSame(0, FollowUpRecurringRule::query()->count());

        $this->actingAs($owner)->post(route('follow-ups.recurring.store'), $this->payload(['quotation_id' => $quotation->getKey(), 'customer_id' => $customer->getKey()]))
            ->assertSessionHasNoErrors();
        $rule = FollowUpRecurringRule::query()->sole();
        $this->assertSame([(int) $store->getKey(), (int) $customer->getKey(), (int) $quotation->getKey()], [(int) $rule->store_id, (int) $rule->customer_id, (int) $rule->quotation_id]);

        // The Recurring list shows the link; removing it through the existing edit path clears the derived store too.
        $this->actingAs($owner)->get(route('follow-ups.recurring.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rules.data.0.customer.name', 'ACME')->where('rules.data.0.quotation.id', (int) $quotation->getKey()));
        $generated = FollowUp::query()->where('recurring_rule_id', $rule->getKey())->pluck('quotation_id')->all();
        $this->actingAs($owner)->patch(route('follow-ups.recurring.update', $rule), ['customer_id' => null, 'quotation_id' => null, 'expected_version' => $rule->version])
            ->assertSessionHasNoErrors();
        $this->assertSame([null, null, null], [$rule->fresh()->store_id, $rule->fresh()->customer_id, $rule->fresh()->quotation_id]);
        $this->assertSame($generated, FollowUp::query()->where('recurring_rule_id', $rule->getKey())->pluck('quotation_id')->all(), 'Generated occurrences keep their links.');
    }

    public function test_cross_store_quotation_rule_is_created_through_the_authorized_path_only(): void
    {
        [$owner, $organization, $storeA, $seller] = $this->fixture();
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $this->addStoreMember($storeB, $seller);
        $customer = $this->createCustomer($organization, 'ACME');
        $quotation = $this->quotationIn($owner, $organization, $storeB, $storeA, ['customer_id' => $customer->getKey()]);
        $followUp = app(SaveFollowUpAction::class)->create($seller, $organization, ['title' => 'Devis B', 'quotation_id' => $quotation->getKey()]);
        $this->activate($seller, $organization, $storeA);

        // W1.1 path: switch to Store B, open the quotation page, create from its panel.
        $this->actingAs($seller)->post(route('follow-ups.open-quotation', $followUp))->assertRedirect(route('quotations.show', $quotation->getKey()));
        $this->actingAs($seller->fresh())->get(route('quotations.show', $quotation))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('followUps.prefill.quotation_id', (int) $quotation->getKey()));
        $this->actingAs($seller->fresh())->post(route('follow-ups.recurring.store'), $this->payload(['quotation_id' => $quotation->getKey(), 'customer_id' => $customer->getKey()]))
            ->assertSessionHasNoErrors();
        $rule = FollowUpRecurringRule::query()->sole();
        $this->assertSame([(int) $storeB->getKey(), (int) $customer->getKey()], [(int) $rule->store_id, (int) $rule->customer_id]);

        // A member of Store A only cannot link the Store B quotation, whatever the browser sends.
        $outsider = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $storeA, $outsider);
        $this->actingAs($outsider)->post(route('follow-ups.recurring.store'), $this->payload(['quotation_id' => $quotation->getKey(), 'customer_id' => $customer->getKey()]))
            ->assertSessionHasErrors('quotation_id');
        $this->actingAs($outsider)->get(route('quotations.show', $quotation))->assertNotFound();
        $this->assertSame(1, FollowUpRecurringRule::query()->count());
    }

    public function test_converted_or_superseded_quotation_is_rejected_with_the_canonical_error(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        foreach (['converted', 'superseded'] as $status) {
            $quotation = $this->createQuotation($owner, $organization, $store);
            DB::table('quotations')->where('id', $quotation->getKey())->update(['status' => $status]);
            $this->actingAs($owner)->post(route('follow-ups.recurring.store'), $this->payload(['quotation_id' => $quotation->getKey()]))
                ->assertSessionHasErrors(['quotation_id' => 'Ce devis n’est plus actif (converti ou remplacé) : une récurrence ne peut pas s’y rattacher.']);
        }
        $this->assertSame(0, FollowUpRecurringRule::query()->count());
    }
}
