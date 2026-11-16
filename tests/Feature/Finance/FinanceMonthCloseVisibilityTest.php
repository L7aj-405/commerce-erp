<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PaymentTestCase;

/**
 * Sprint 0 UAT gap: "Vérifié" is a tick, not a closing. The page must say why Close is not
 * offered for the running month, and offer Reopen only to those who may use it.
 */
class FinanceMonthCloseVisibilityTest extends PaymentTestCase
{
    public function test_running_month_cannot_be_closed_and_the_page_exposes_that_state(): void
    {
        $this->travelTo('2026-11-16');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);

        $this->actingAs($owner)->get(route('finance.monthly-situation.index', ['month' => '2026-11']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('monthReview.can_review', true)
                ->where('monthReview.can_close', false)
                ->where('can.reopen', true)); // the Owner can always reopen

        $this->actingAs($owner)->get(route('finance.monthly-situation.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page->where('monthReview.can_close', true));
    }

    public function test_reopen_flag_follows_the_dedicated_permission(): void
    {
        $this->travelTo('2026-11-16');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $manager = User::factory()->create();
        $this->addOrganizationMember($organization, $manager, ['finance.monthly_situation.manage', 'finance.monthly_situation.view'], roleName: 'Finance manager');
        $this->addStoreMember($store, $manager);
        $this->activate($manager, $organization, $store);

        $this->actingAs($manager)->get(route('finance.monthly-situation.index', ['month' => '2026-10']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', true)->where('can.reopen', false));
    }
}
