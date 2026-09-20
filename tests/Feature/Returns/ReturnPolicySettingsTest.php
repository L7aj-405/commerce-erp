<?php

namespace Tests\Feature\Returns;

use App\Models\User;
use App\Services\ReturnPolicyService;
use Tests\Support\PlatformTestCase;

class ReturnPolicySettingsTest extends PlatformTestCase
{
    public function test_store_override_wins_and_inherit_uses_organization_default(): void
    {
        $owner = User::factory()->create(); $organization = $this->createOrganization($owner); $store = $this->createStore($organization, $owner); $this->activate($owner, $organization, $store);
        $organization->settings = ['return_policy' => ['enabled' => true, 'window_value' => 7, 'window_unit' => 'days', 'window_minutes' => 10080]]; $organization->save();
        $store->settings = ['return_policy' => ['enabled' => true, 'window_value' => 24, 'window_unit' => 'hours', 'window_minutes' => 1440]]; $store->save();
        $resolved = app(ReturnPolicyService::class)->resolve($organization->fresh(), $store->fresh());
        $this->assertSame('store', $resolved['source']); $this->assertSame(1440, $resolved['window_minutes']);
        $store->settings = ['return_policy' => ['inherit' => true]]; $store->save();
        $inherited = app(ReturnPolicyService::class)->resolve($organization->fresh(), $store->fresh());
        $this->assertSame('organization', $inherited['source']); $this->assertSame(10080, $inherited['window_minutes']);
    }

    public function test_return_policy_settings_are_server_authorized_and_normalized_to_minutes(): void
    {
        $owner = User::factory()->create(); $organization = $this->createOrganization($owner); $store = $this->createStore($organization, $owner); $this->activate($owner, $organization, $store);
        $this->actingAs($owner)->put(route('return-policy.update'), ['scope' => 'organization', 'inherit' => false, 'enabled' => true, 'window_value' => 24, 'window_unit' => 'hours', 'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => true, 'require_reason' => true, 'default_disposition' => 'restock'])->assertRedirect();
        $this->assertSame(1440, data_get($organization->fresh()->settings, 'return_policy.window_minutes'));
        $viewer = User::factory()->create(); $this->addOrganizationMember($organization, $viewer, ['settings.view']); $this->addStoreMember($store, $viewer); $this->activate($viewer, $organization, $store);
        $this->actingAs($viewer)->put(route('return-policy.update'), ['scope' => 'organization', 'enabled' => false])->assertForbidden();
    }
}
