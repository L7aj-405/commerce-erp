<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\TrustedTwoFactorDevice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PermissionProvisioner;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

class ActivityAuditCenterTest extends PlatformTestCase
{
    public function test_audit_permission_provisioning_is_idempotent_for_system_roles_and_leaves_custom_roles_unchanged(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $permission = $this->permission('audit.view');
        $admin = $organization->roles()->where('slug', 'admin')->firstOrFail();
        $custom = $this->createRole($organization, [], 'Custom without audit');
        $admin->permissions()->detach($permission->id);

        app(PermissionProvisioner::class)->provisionAllOrganizations();
        app(PermissionProvisioner::class)->provisionAllOrganizations();

        $this->assertTrue($admin->fresh()->permissions()->where('key', 'audit.view')->exists());
        $this->assertFalse($custom->fresh()->permissions()->where('key', 'audit.view')->exists());
    }

    public function test_authorized_owner_can_view_human_readable_activity(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        app(AuditLogger::class)->record('product.updated', $owner, $organization, newValues: ['name' => 'Console X32']);

        $this->actingAs($owner)->get(route('activity.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Activity/Index')
                ->where('logs.data.0.action_label', 'Produit modifié')
                ->where('logs.data.0.module.label', 'Catalogue')
                ->where('logs.data.0.target.reference', 'Console X32'));
    }

    public function test_activity_center_renders_integer_and_string_audit_targets(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $device = new TrustedTwoFactorDevice;
        $device->user_id = $owner->getKey();
        $device->token_hash = hash('sha256', 'activity-device-token');
        $device->device_name = 'Chrome sur Windows';
        $device->expires_at = now()->addDays(15);
        $device->save();

        app(AuditLogger::class)->record(
            'organization.audit_target_test',
            $owner,
            $organization,
            auditable: $organization,
        );
        app(AuditLogger::class)->record(
            'two_factor.trusted_device_audit_target_test',
            $owner,
            $organization,
            auditable: $device,
        );

        $this->actingAs($owner)->get(route('activity.index', ['event' => 'organization.audit_target_test']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('logs.data.0.target.id', (string) $organization->getKey()));

        $this->actingAs($owner)->get(route('activity.index', ['event' => 'two_factor.trusted_device_audit_target_test']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('logs.data.0.target.id', $device->getKey()));
    }

    public function test_user_without_audit_permission_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $member = User::factory()->create();
        $this->addOrganizationMember($organization, $member, ['products.view']);
        $this->activate($member, $organization);

        $this->actingAs($member)->get(route('activity.index'))->assertForbidden();
    }

    public function test_activity_is_tenant_scoped_and_foreign_detail_is_hidden(): void
    {
        $ownerA = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Organization A');
        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB, 'Organization B');
        $this->activate($ownerA, $organizationA);

        $foreign = app(AuditLogger::class)->record('product.updated', $ownerB, $organizationB, newValues: ['name' => 'Secret B']);
        app(AuditLogger::class)->record('product.updated', $ownerA, $organizationA, newValues: ['name' => 'Visible A']);

        $this->actingAs($ownerA)->get(route('activity.index'))->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.target.reference', 'Visible A')
            ->where('organizations', fn ($organizations) => collect($organizations)->pluck('id')->all() === [$organizationA->id]));

        $this->actingAs($ownerA)->get(route('activity.index', ['detail' => $foreign->id]))->assertNotFound();
    }

    public function test_filters_apply_before_server_side_pagination(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $otherUser = User::factory()->create();
        $this->addOrganizationMember($organization, $otherUser, ['audit.view']);
        $this->activate($owner, $organization);

        foreach (range(1, 27) as $index) {
            app(AuditLogger::class)->record('inventory.adjusted', $owner, $organization, newValues: ['reference' => "ADJ-{$index}"]);
        }
        app(AuditLogger::class)->record('product.updated', $otherUser, $organization, newValues: ['name' => 'Filtered product']);

        $this->actingAs($owner)->get(route('activity.index', [
            'user_id' => $otherUser->id,
            'module' => 'catalog',
            'event' => 'product.updated',
            'search' => 'Filtered product',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.total', 1)
            ->where('logs.data.0.actor.id', $otherUser->id));

        $this->actingAs($owner)->get(route('activity.index', ['event' => 'inventory.adjusted']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 25)->where('logs.total', 27));
    }

    public function test_legacy_sensitive_values_are_redacted_when_rendered_in_list_and_detail(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $log = new AuditLog;
        $log->organization_id = $organization->id;
        $log->actor_id = $owner->id;
        $log->event = 'woocommerce.integration_updated';
        $log->new_values = [
            'name' => 'Visible integration',
            'consumer_secret' => 'must-never-render',
            'nested' => ['refresh_token' => 'also-secret', 'safe' => 'visible'],
        ];
        $log->save();

        $response = $this->actingAs($owner)->get(route('activity.index', ['detail' => $log->id]))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('detail.new_values.name', 'Visible integration')
            ->missing('detail.new_values.consumer_secret')
            ->missing('detail.new_values.nested.refresh_token')
            ->where('detail.new_values.nested.safe', 'visible'));
        $this->assertStringNotContainsString('must-never-render', $response->getContent());
        $this->assertStringNotContainsString('also-secret', $response->getContent());
    }

    public function test_user_with_permission_in_multiple_organizations_can_filter_only_those_organizations(): void
    {
        $owner = User::factory()->create();
        $organizationA = $this->createOrganization($owner, 'Organization A');
        $organizationB = $this->createOrganization($owner, 'Organization B');
        $this->activate($owner, $organizationA);
        app(AuditLogger::class)->record('store.updated', $owner, $organizationB, newValues: ['name' => 'Store B']);

        $this->actingAs($owner)->get(route('activity.index', ['organization_id' => $organizationB->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('filters.organization_id', $organizationB->id)
                ->where('logs.data.0.organization.id', $organizationB->id));
    }
}
