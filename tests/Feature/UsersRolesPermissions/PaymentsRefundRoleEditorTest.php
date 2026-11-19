<?php

namespace Tests\Feature\UsersRolesPermissions;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Support\PlatformTestCase;

/**
 * Step 6 UAT: `payments.refund` and `payments.reverse` must appear and be assignable independently in the Role Editor,
 * the catalogue must never drift from config, and the repair migration must heal an environment that lacks the row
 * without escalating anybody.
 */
class PaymentsRefundRoleEditorTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withFreshAuthentication();
    }

    /** @return list<string> */
    private function catalogue(User $actor, $organization): array
    {
        $keys = [];
        $this->actingAs($actor)->get(route('users-access.index', $organization))->assertOk()
            ->assertInertia(function (Assert $page) use (&$keys) {
                $keys = collect($page->toArray()['props']['permissionGroups'])->flatten(1)->pluck('key')->all();
            });

        return $keys;
    }

    /** @return list<int> */
    private function ids(string ...$keys): array
    {
        return Permission::query()->whereIn('key', $keys)->pluck('id')->all();
    }

    /** @return list<string> */
    private function keys(Role $role): array
    {
        return $role->permissions()->pluck('key')->sort()->values()->all();
    }

    public function test_role_editor_catalogue_lists_refund_and_reverse_as_separate_rows(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $this->actingAs($owner)->get(route('users-access.index', $organization))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissionGroups.payments', fn ($rows) => collect($rows)->pluck('key')->contains('payments.refund')
                    && collect($rows)->pluck('key')->contains('payments.reverse')
                    && collect($rows)->firstWhere('key', 'payments.refund')['name'] !== collect($rows)->firstWhere('key', 'payments.reverse')['name']));
    }

    public function test_every_configured_permission_is_offered_by_the_role_editor(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $missing = array_values(array_diff(array_keys(config('platform.permissions')), $this->catalogue($owner, $organization)));

        $this->assertSame([], $missing, 'Config permissions absent from the Role Editor catalogue (missing provisioning migration?).');
    }

    public function test_refund_and_reverse_are_assignable_separately_and_other_permissions_are_preserved(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $this->createRole($organization, ['payments.view', 'sales_orders.view'], 'Caisse');
        $base = ['payments.view', 'sales_orders.view'];

        $this->actingAs($owner)->put(route('roles.permissions.update', $role), ['permission_ids' => $this->ids(...$base, ...['payments.refund'])])->assertRedirect();
        $this->assertSame(collect([...$base, 'payments.refund'])->sort()->values()->all(), $this->keys($role));

        $this->actingAs($owner)->put(route('roles.permissions.update', $role), ['permission_ids' => $this->ids(...$base, ...['payments.reverse'])])->assertRedirect();
        $this->assertSame(collect([...$base, 'payments.reverse'])->sort()->values()->all(), $this->keys($role));

        $this->actingAs($owner)->put(route('roles.permissions.update', $role), ['permission_ids' => $this->ids(...$base, ...['payments.reverse', 'payments.refund'])])->assertRedirect();
        $this->assertSame(collect([...$base, 'payments.refund', 'payments.reverse'])->sort()->values()->all(), $this->keys($role));
    }

    public function test_creating_a_role_can_carry_refund_without_reverse(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $this->actingAs($owner)->post(route('roles.store'), ['name' => 'Refunder', 'permission_ids' => $this->ids('payments.view', 'payments.refund')])->assertRedirect();

        $role = Role::query()->where('organization_id', $organization->id)->where('name', 'Refunder')->firstOrFail();
        $this->assertSame(['payments.refund', 'payments.view'], $this->keys($role));
    }

    public function test_an_actor_cannot_grant_a_payment_permission_they_do_not_hold(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $target = $this->createRole($organization, ['payments.view'], 'Target');

        foreach ([['payments.reverse', 'payments.refund'], ['payments.refund', 'payments.reverse']] as [$held, $missing]) {
            $actor = User::factory()->create();
            $this->addOrganizationMember($organization, $actor, ['roles.view', 'roles.assign-permissions', 'payments.view', $held], roleName: "Holds {$held}");
            $this->activate($actor, $organization);

            $this->actingAs($actor)->put(route('roles.permissions.update', $target), ['permission_ids' => $this->ids('payments.view', $missing)])->assertForbidden();
            $this->assertSame(['payments.view'], $this->keys($target));

            $this->actingAs($actor)->put(route('roles.permissions.update', $target), ['permission_ids' => $this->ids('payments.view', $held)])->assertRedirect();
            $this->assertSame(collect(['payments.view', $held])->sort()->values()->all(), $this->keys($target));

            $this->actingAs($owner)->put(route('roles.permissions.update', $target), ['permission_ids' => $this->ids('payments.view')])->assertRedirect();
        }
    }

    public function test_repair_migration_restores_a_missing_refund_row_for_reverse_holders_only(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $reverseRole = $this->createRole($organization, ['payments.view', 'payments.reverse'], 'Reverser');
        $plainRole = $this->createRole($organization, ['payments.view'], 'Viewer');

        // Environment as observed on Staging: the refund row (and so every grant) is absent.
        $refund = Permission::query()->where('key', 'payments.refund')->firstOrFail();
        DB::table('role_permission')->where('permission_id', $refund->id)->delete();
        $refund->delete();
        $this->assertNotContains('payments.refund', $this->catalogue($owner, $organization));

        $migration = require database_path('migrations/2026_11_23_000002_repair_payments_refund_catalog.php');
        $migration->up();

        $this->assertContains('payments.refund', $this->catalogue($owner, $organization));
        $this->assertContains('payments.refund', $this->keys($reverseRole));
        $this->assertNotContains('payments.refund', $this->keys($plainRole));

        // Idempotent, and it never re-grants once the row exists (a role customised afterwards stays as the admin left it).
        DB::table('role_permission')->where('role_id', $reverseRole->id)->where('permission_id', Permission::query()->where('key', 'payments.refund')->value('id'))->delete();
        $migration->up();
        $this->assertSame(['payments.reverse', 'payments.view'], $this->keys($reverseRole));
    }

    public function test_provisioning_migrations_fail_loudly_when_config_lacks_the_permission(): void
    {
        $permissions = config('platform.permissions');
        unset($permissions['payments.refund']);
        config(['platform.permissions' => $permissions]);
        DB::table('role_permission')->whereIn('permission_id', Permission::query()->where('key', 'payments.refund')->pluck('id'))->delete();
        Permission::query()->where('key', 'payments.refund')->delete();

        foreach (['2026_11_23_000001_provision_payments_refund_permission', '2026_11_23_000002_repair_payments_refund_catalog'] as $file) {
            try {
                (require database_path("migrations/{$file}.php"))->up();
                $this->fail("{$file} must not succeed silently without the permission.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('payments.refund', $exception->getMessage());
            }
        }
    }
}
