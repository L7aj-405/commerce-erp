<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CatalogTestCase;

class OperationalDashboardTest extends CatalogTestCase
{
    public function test_user_without_organization_receives_first_step_onboarding_state(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('platform.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Index')
            ->has('organizations', 0)
            ->where('onboarding.organization', false)
            ->where('onboarding.store', false)
            ->where('dashboard.product_count', null));
    }

    public function test_active_tenant_dashboard_reports_only_operational_onboarding_state(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->createProduct($organization, 'Dashboard Product');
        $this->activate($owner, $organization, $store);

        $this->actingAs($owner)->get(route('platform.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Index')
            ->where('dashboard.product_count', 1)
            ->where('dashboard.stocked_item_count', 0)
            ->where('dashboard.sales_today', 0)
            ->where('dashboard.payments_to_receive', 0)
            ->where('onboarding.organization', true)
            ->where('onboarding.store', true)
            ->where('onboarding.products', true)
            ->where('onboarding.stock', false)
            ->where('onboarding.first_sale', false));
    }

    public function test_dashboard_does_not_disclose_product_metric_without_view_permission(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->createProduct($organization, 'Private Product');
        $this->addOrganizationMember($organization, $member, []);
        $this->activate($member, $organization);

        $this->actingAs($member)->get(route('platform.index'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.product_count', null)
            ->where('onboarding.products', false));
    }
}
