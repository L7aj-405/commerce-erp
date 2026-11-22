<?php

namespace Tests\Feature\Finance;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionProvisioner;
use App\Services\Sandbox\SandboxModuleCatalog;
use App\Support\Roles\RoleCapabilitySummary;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

/** Phase 2 Sprint 1 (PR4): supplier invoice permission keys, defaults and the equivalence provisioning migration. */
class SupplierInvoicePermissionsTest extends PlatformTestCase
{
    private const KEYS = ['supplier_invoices.view', 'supplier_invoices.manage', 'supplier_invoices.approve', 'supplier_invoices.void'];

    private const MIGRATION = 'migrations/2026_11_26_000004_provision_supplier_invoice_permissions.php';

    private const TEST_MODE_LINE = 'Factures fournisseurs et échéanciers générés (Production uniquement)';

    /** @return list<string> */
    private function keys(Role $role): array
    {
        return $role->permissions()->pluck('key')->sort()->values()->all();
    }

    /** @return list<string> */
    private function supplierInvoiceKeys(Role $role): array
    {
        return array_values(array_intersect($this->keys($role), self::KEYS));
    }

    private function wipeSupplierInvoiceGrants(): void
    {
        DB::table('role_permission')->whereIn('permission_id', Permission::query()->whereIn('key', self::KEYS)->pluck('id'))->delete();
    }

    private function systemRole($organization, string $slug): Role
    {
        return Role::query()->where('organization_id', $organization->id)->where('slug', $slug)->firstOrFail();
    }

    public function test_catalogue_declares_the_four_keys(): void
    {
        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey($key, config('platform.permissions'));
        }
    }

    public function test_new_organizations_get_the_defaults_through_organization_creator(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $this->assertEqualsCanonicalizing(self::KEYS, $this->supplierInvoiceKeys($this->systemRole($organization, 'owner')));
        foreach (self::KEYS as $key) {
            $this->assertTrue($owner->hasPermission($organization, $key));
        }
        // Admin holds both procurement.manage and finance.monthly_situation.manage.
        $this->assertEqualsCanonicalizing(self::KEYS, $this->supplierInvoiceKeys($this->systemRole($organization, 'admin')));
        // Sales Employee holds procurement.manage but not the finance right: view + manage only.
        $this->assertEqualsCanonicalizing(['supplier_invoices.view', 'supplier_invoices.manage'], $this->supplierInvoiceKeys($this->systemRole($organization, 'sales-employee')));
    }

    public function test_config_defaults_follow_the_grant_rules_for_every_default_role_and_preset(): void
    {
        $sets = array_merge(
            array_map(fn ($role) => $role['permissions'], config('platform.default_roles')),
            array_map(fn ($preset) => $preset['permissions'], config('platform.presets')),
        );
        foreach ($sets as $name => $permissions) {
            if ($permissions === '*') {
                continue;
            }
            $procurement = in_array('procurement.manage', $permissions, true);
            $finance = in_array('finance.monthly_situation.manage', $permissions, true);
            $this->assertSame($procurement || $finance, in_array('supplier_invoices.view', $permissions, true), "[{$name}] view");
            $this->assertSame($procurement || $finance, in_array('supplier_invoices.manage', $permissions, true), "[{$name}] manage");
            $this->assertSame($finance, in_array('supplier_invoices.approve', $permissions, true), "[{$name}] approve");
            $this->assertSame($finance, in_array('supplier_invoices.void', $permissions, true), "[{$name}] void");
        }
    }

    public function test_migration_grants_only_to_the_specified_holders_across_organizations_and_is_idempotent(): void
    {
        $ownerA = User::factory()->create();
        $orgA = $this->createOrganization($ownerA);
        $ownerB = User::factory()->create();
        $orgB = $this->createOrganization($ownerB, 'Other org');

        $procurementA = $this->createRole($orgA, ['procurement.manage', 'procurement.view'], 'Acheteur');
        $financeA = $this->createRole($orgA, ['finance.monthly_situation.manage'], 'Finance A');
        $financeB = $this->createRole($orgB, ['finance.monthly_situation.manage'], 'Finance B');
        $procurementB = $this->createRole($orgB, ['procurement.manage'], 'Acheteur B');
        $viewerA = $this->createRole($orgA, ['procurement.view', 'finance.monthly_situation.view', 'suppliers.manage'], 'Lecteur');
        $paymentsOnlyA = $this->createRole($orgA, ['finance.outgoing_payments.manage'], 'Paiements');
        $custom = $this->createRole($orgA, ['sales_orders.view'], 'Vente');

        // Pre-Sprint world: nobody holds the new keys.
        $this->wipeSupplierInvoiceGrants();
        $adminA = $this->systemRole($orgA, 'admin');
        $untouched = [$viewerA, $paymentsOnlyA, $custom];
        $snapshot = collect($untouched)->mapWithKeys(fn (Role $r) => [$r->id => $this->keys($r)])->all();

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $count = DB::table('role_permission')->count();
        $migration->up(); // idempotent
        $this->assertSame($count, DB::table('role_permission')->count());

        $this->assertEqualsCanonicalizing(['supplier_invoices.view', 'supplier_invoices.manage'], $this->supplierInvoiceKeys($procurementA->fresh()));
        $this->assertEqualsCanonicalizing(['supplier_invoices.view', 'supplier_invoices.manage'], $this->supplierInvoiceKeys($procurementB->fresh()));
        $this->assertEqualsCanonicalizing(self::KEYS, $this->supplierInvoiceKeys($financeA->fresh()));
        $this->assertEqualsCanonicalizing(self::KEYS, $this->supplierInvoiceKeys($financeB->fresh()));
        $this->assertEqualsCanonicalizing(self::KEYS, $this->supplierInvoiceKeys($adminA->fresh()));
        $this->assertEqualsCanonicalizing(self::KEYS, $this->supplierInvoiceKeys($this->systemRole($orgA, 'owner')));
        // The procurement-only role never gets approve / void.
        $this->assertNotContains('supplier_invoices.approve', $this->keys($procurementA->fresh()));
        $this->assertNotContains('supplier_invoices.void', $this->keys($procurementA->fresh()));

        // Roles without either source permission are untouched.
        foreach ($untouched as $role) {
            $this->assertSame($snapshot[$role->id], $this->keys($role->fresh()), "Role {$role->name} must not change");
            $this->assertSame([], $this->supplierInvoiceKeys($role->fresh()));
        }

        // Grants carry the role's own organization.
        foreach (DB::table('role_permission')->whereIn('permission_id', Permission::query()->whereIn('key', self::KEYS)->pluck('id'))->get() as $grant) {
            $this->assertSame((int) Role::query()->whereKey($grant->role_id)->value('organization_id'), (int) $grant->organization_id);
        }
    }

    public function test_migration_never_revokes_and_does_not_resync_default_roles(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $admin = $this->systemRole($organization, 'admin');
        $custom = $this->createRole($organization, ['sales_orders.view'], 'Déjà accordé');

        // An organization that stripped the source permissions from Admin must not receive the new keys.
        $this->wipeSupplierInvoiceGrants();
        $strip = Permission::query()->whereIn('key', ['procurement.manage', 'finance.monthly_situation.manage'])->pluck('id');
        DB::table('role_permission')->where('role_id', $admin->id)->whereIn('permission_id', $strip)->delete();
        // A custom role that already holds a key without holding any source permission keeps it (never revoked).
        DB::table('role_permission')->insert(['organization_id' => $organization->id, 'role_id' => $custom->id, 'permission_id' => $this->permission('supplier_invoices.view')->id]);
        $customBefore = $this->keys($custom);

        $migration = require database_path(self::MIGRATION);
        $migration->up();

        $this->assertSame([], $this->supplierInvoiceKeys($admin->fresh()));
        $this->assertSame($customBefore, $this->keys($custom->fresh()));

        $migration->down(); // no-op
        $this->assertSame($customBefore, $this->keys($custom->fresh()));
    }

    public function test_custom_role_without_the_grant_is_denied(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $member = User::factory()->create();
        $this->addOrganizationMember($organization, $member, ['suppliers.manage', 'procurement.view', 'finance.monthly_situation.view']);

        foreach (self::KEYS as $key) {
            $this->assertFalse($member->hasPermission($organization, $key), $key);
        }
    }

    public function test_permissions_do_not_leak_across_organizations(): void
    {
        $ownerA = User::factory()->create();
        $orgA = $this->createOrganization($ownerA);
        $orgB = $this->createOrganization(User::factory()->create(), 'B');

        $this->assertTrue($ownerA->hasPermission($orgA, 'supplier_invoices.approve'));
        $this->assertFalse($ownerA->hasPermission($orgB, 'supplier_invoices.approve'));
    }

    public function test_provisioning_all_organizations_is_idempotent_and_keeps_one_row_per_key(): void
    {
        $organization = $this->createOrganization(User::factory()->create());
        $this->createOrganization(User::factory()->create(), 'Second');
        $provisioner = app(PermissionProvisioner::class);
        $provisioner->provisionAllOrganizations();
        $provisioner->provisionAllOrganizations();

        foreach (self::KEYS as $key) {
            $this->assertSame(1, Permission::query()->where('key', $key)->count());
        }
        $this->assertSame(4, $this->systemRole($organization, 'owner')->permissions()->whereIn('key', self::KEYS)->count());
    }

    public function test_role_capability_summary_surfaces_the_new_keys_without_new_capabilities(): void
    {
        $this->assertContains('purchasing', RoleCapabilitySummary::summarize(['supplier_invoices.manage'])['capabilities']);
        $this->assertContains('finance_periods', RoleCapabilitySummary::summarize(['supplier_invoices.approve'])['capabilities']);
        $this->assertContains('finance_periods', RoleCapabilitySummary::summarize(['supplier_invoices.void'])['capabilities']);
    }

    public function test_test_mode_lists_supplier_invoices_as_unavailable(): void
    {
        $this->assertContains(self::TEST_MODE_LINE, app(SandboxModuleCatalog::class)->unavailable());

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $owner->stores()->first());
        $this->withFreshAuthentication()->actingAs($owner);
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect('/test-mode');

        $this->get('/test-mode')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Sandbox/Index')
            ->where('unavailable', fn ($lines) => in_array(self::TEST_MODE_LINE, collect($lines)->all(), true)));
    }
}
