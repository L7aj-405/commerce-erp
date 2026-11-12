<?php
namespace Tests\Feature\Sandbox;
use Tests\Support\PlatformTestCase;
use App\Models\User;
use App\Models\Sandbox;
use App\Services\Sandbox\SandboxContext;
use Inertia\Testing\AssertableInertia as Assert;
class SandboxFoundationTest extends PlatformTestCase
{
    public function test_another_authorized_user_remains_in_production_and_reentry_reuses_shared_sandbox(): void
    {
        $owner = User::factory()->create(); $org = $this->createOrganization($owner);
        $member = User::factory()->create();
        $this->addOrganizationMember($org, $member, ['sandbox.access', 'sandbox.switch']);
        $this->activate($owner, $org); $this->activate($member, $org);
        $this->actingAs($owner)->post('/test-mode/policy', ['enabled' => true]);
        $this->post('/test-mode/enter');
        $ownerSelection = session(SandboxContext::SESSION_KEY);
        session()->forget(SandboxContext::SESSION_KEY);
        $this->actingAs($member)->get('/test-mode')->assertInertia(fn (Assert $p) => $p->where('sandbox.active', false));
        $this->post('/test-mode/enter');
        $this->assertSame($ownerSelection['id'], session(SandboxContext::SESSION_KEY)['id']);
        $this->assertSame(1, Sandbox::query()->count());
        $this->actingAs($owner)->withSession([SandboxContext::SESSION_KEY => $ownerSelection])->get('/test-mode')->assertInertia(fn (Assert $p) => $p->where('sandbox.active', true));
    }
    public function test_production_default_disabled_policy_and_enter_leave(): void
    {
        $owner = User::factory()->create();
        $org = $this->createOrganization($owner);
        $this->activate($owner, $org);
        $this->actingAs($owner)->get('/test-mode')->assertOk()->assertInertia(fn (Assert $p) => $p->where('sandbox.active', false));
        $this->post('/test-mode/enter')->assertForbidden();
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect('/test-mode');
        $this->get('/test-mode')->assertOk()->assertInertia(fn (Assert $p) => $p->where('sandbox.active', true));
        $this->get('/payments')->assertRedirect('/test-mode');
        $this->post('/pos/sales', [])->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        $this->post('/test-mode/leave')->assertRedirect('/platform');
        $this->get('/test-mode')->assertOk()->assertInertia(fn (Assert $p) => $p->where('sandbox.active', false));
        $this->assertSame(1, Sandbox::query()->count());
    }
    public function test_permissions_and_tenant_or_user_mismatch_fail_closed(): void
    {
        $owner = User::factory()->create(); $org = $this->createOrganization($owner);
        $user = User::factory()->create(); $this->addOrganizationMember($org, $user);
        $this->activate($user, $org);
        $this->actingAs($user)->post('/test-mode/enter')->assertForbidden();
        $this->post('/test-mode/policy', ['enabled' => true])->assertForbidden();
        $this->activate($owner, $org);
        $this->actingAs($owner)->post('/test-mode/policy', ['enabled' => true]);
        $this->post('/test-mode/enter');
        $selection = session(SandboxContext::SESSION_KEY);
        $this->actingAs($user)->withSession([SandboxContext::SESSION_KEY => $selection])->post('/sales/orders')->assertForbidden();
        $this->get('/test-mode')->assertOk()->assertInertia(fn (Assert $p) => $p->where('sandbox.active', false)->where('sandbox.restricted', true));
        $this->post('/test-mode/leave');
        $this->assertFalse(session()->has(SandboxContext::SESSION_KEY));
    }
    public function test_session_independence_store_switch_and_policy_revocation(): void
    {
        $owner = User::factory()->create(); $org = $this->createOrganization($owner);
        $store = $this->createStore($org, $owner); $this->activate($owner, $org, $store);
        $this->actingAs($owner)->post('/test-mode/policy', ['enabled' => true]);
        $this->post('/test-mode/enter'); $selection = session(SandboxContext::SESSION_KEY);
        session()->forget(SandboxContext::SESSION_KEY);
        $this->get('/test-mode')->assertInertia(fn (Assert $p) => $p->where('sandbox.active', false));
        $otherStore = $this->createStore($org, $owner); $this->activate($owner, $org, $otherStore);
        $this->withSession([SandboxContext::SESSION_KEY => $selection])->get('/test-mode')->assertInertia(fn (Assert $p) => $p->where('sandbox.active', true));
        $org->settings = ['sandbox_enabled' => false]; $org->save();
        $this->post('/sales/orders')->assertForbidden();
        $otherOrg = $this->createOrganization($owner, 'Other'); $this->activate($owner, $otherOrg);
        $this->get('/test-mode')->assertInertia(fn (Assert $p) => $p->where('sandbox.active', false)->where('sandbox.restricted', true));
    }
}
