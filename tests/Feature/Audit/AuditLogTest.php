<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Tests\Support\PlatformTestCase;

class AuditLogTest extends PlatformTestCase
{
    public function test_organization_and_store_creation_are_audited(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner, 'Organization A');
        $this->createStore($organization, $owner, 'Store A');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_id' => $owner->id,
            'event' => 'organization.created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_id' => $owner->id,
            'event' => 'store.created',
        ]);
    }

    public function test_membership_and_role_changes_are_audited(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();

        $this->actingAs($owner)->post(route('organization-memberships.store', $organization), [
            'user_id' => $member->id,
            'role_id' => $role->id,
        ])->assertRedirect();

        $membership = $organization->memberships()->where('user_id', $member->id)->firstOrFail();
        $customRole = $this->createRole($organization, [], 'Audited Role');
        $this->actingAs($owner)->patch(route('organization-memberships.update', $membership), [
            'role_id' => $customRole->id,
        ])->assertRedirect();

        $permission = $this->permission('organizations.view');
        $this->actingAs($owner)->put(route('roles.permissions.update', $customRole), [
            'permission_ids' => [$permission->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['event' => 'organization_membership.created']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'organization_membership.role_changed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role.permissions_changed']);
    }

    public function test_settings_changes_are_audited(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $this->actingAs($owner)->patch(route('organizations.settings.update', $organization), [
            'settings' => ['timezone' => 'Africa/Casablanca'],
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'event' => 'organization.settings_changed',
        ]);
    }

    public function test_sensitive_credentials_are_removed_from_audit_payloads(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        app(AuditLogger::class)->record(
            'security.payload_test',
            $owner,
            $organization,
            newValues: [
                'name' => 'Visible',
                'password' => 'must-not-be-stored',
                'nested' => [
                    'token' => 'must-not-be-stored',
                    'safe' => 'visible',
                ],
            ],
        );

        $log = AuditLog::query()->where('event', 'security.payload_test')->firstOrFail();

        $this->assertSame('Visible', $log->new_values['name']);
        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertArrayNotHasKey('token', $log->new_values['nested']);
        $this->assertSame('visible', $log->new_values['nested']['safe']);
        $this->assertStringNotContainsString('must-not-be-stored', json_encode($log->new_values, JSON_THROW_ON_ERROR));
    }
}
