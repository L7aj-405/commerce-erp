<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\RefundPaymentAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PaymentTestCase;

/**
 * Phase 1 Step 6 (S-03): paid-order cancellation / direct refund authorization, role presets, the provisioning
 * migration (equivalence, no escalation, idempotent) and the MCP boundary.
 */
class PaymentPermissionProvisioningTest extends PaymentTestCase
{
    /** @return array<string, array{bool, bool}> [holds payments.refund, holds payments.reverse] */
    public static function roles(): array
    {
        return ['refund only' => [true, false], 'reverse only' => [false, true], 'both' => [true, true], 'neither' => [false, false]];
    }

    #[DataProvider('roles')]
    public function test_paid_order_cancellation_refunds_money_so_it_requires_refund_not_reverse(bool $refund, bool $reverse): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture();
        $this->recordPayment($owner, $order, $account, '100.0000');
        $member = $this->member($organization, $store, ['sales_orders.view', 'sales_orders.cancel'], $refund, $reverse);

        try {
            app(CancelSalesOrderAction::class)->execute($member, $order->fresh(), 'Matrice');
            $this->assertTrue($refund, 'Cancelling a paid order without payments.refund must be refused.');
            $this->assertSame('cancelled', $order->fresh()->status->value);
            $this->assertDatabaseCount('payment_refunds', 1);
        } catch (HttpException $exception) {
            $this->assertFalse($refund);
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('confirmed', $order->fresh()->status->value);
            $this->assertDatabaseCount('payment_refunds', 0);
        }
    }

    #[DataProvider('roles')]
    public function test_refund_action_checks_refund_only(bool $refund, bool $reverse): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '100.0000');
        $member = $this->member($organization, $store, ['payments.view'], $refund, $reverse);

        try {
            app(RefundPaymentAction::class)->execute($member, $payment, $order->fresh(), 'Matrice');
            $this->assertTrue($refund);
            $this->assertDatabaseCount('payment_refunds', 1);
        } catch (HttpException $exception) {
            $this->assertFalse($refund);
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertDatabaseCount('payment_refunds', 0);
        }
    }

    public function test_default_roles_and_presets_never_split_the_two_permissions_unexpectedly(): void
    {
        $this->assertArrayHasKey('payments.refund', config('platform.permissions'));
        $sets = array_merge(
            array_map(fn ($role) => $role['permissions'], config('platform.default_roles')),
            array_map(fn ($preset) => $preset['permissions'], config('platform.presets')),
        );
        foreach ($sets as $name => $permissions) {
            if ($permissions === '*') {
                continue;
            }
            $this->assertSame(in_array('payments.reverse', $permissions, true), in_array('payments.refund', $permissions, true), "Set [{$name}] must keep today's rights: refund iff reverse.");
        }
    }

    public function test_provisioning_migration_preserves_effective_rights_without_escalation_and_is_idempotent(): void
    {
        $ownerA = User::factory()->create();
        $orgA = $this->createOrganization($ownerA);
        $ownerB = User::factory()->create();
        $orgB = $this->createOrganization($ownerB, 'Other org');

        // Pre-split world: nobody holds payments.refund yet. Customised roles, a custom role holding reverse in
        // each organization, and an Admin that this organization had deliberately stripped of reverse.
        $reverseRoleA = $this->createRole($orgA, ['payments.view', 'payments.reverse'], 'Caissier');
        $plainRoleA = $this->createRole($orgA, ['payments.view', 'payments.create'], 'Vendeur');
        $reverseRoleB = $this->createRole($orgB, ['payments.reverse'], 'Finance B');
        $adminA = Role::query()->where('organization_id', $orgA->id)->where('slug', 'admin')->firstOrFail();
        $reverseId = Permission::query()->where('key', 'payments.reverse')->value('id');
        $refundId = Permission::query()->where('key', 'payments.refund')->value('id');
        DB::table('role_permission')->where('permission_id', $refundId)->delete();
        DB::table('role_permission')->where('role_id', $adminA->id)->where('permission_id', $reverseId)->delete();

        $effective = fn (Role $role) => $role->permissions()->pluck('key')->sort()->values()->all();
        $before = [$reverseRoleA->id => $effective($reverseRoleA), $plainRoleA->id => $effective($plainRoleA),
            $reverseRoleB->id => $effective($reverseRoleB), $adminA->id => $effective($adminA)];
        $ownerBefore = $ownerA->permissionKeysFor($orgA);

        $migration = require database_path('migrations/2026_11_23_000001_provision_payments_refund_permission.php');
        $migration->up();
        $afterFirst = DB::table('role_permission')->count();
        $migration->up(); // idempotent
        $this->assertSame($afterFirst, DB::table('role_permission')->count());

        // Reverse holders gained exactly payments.refund (their effective rights are preserved)...
        $this->assertSame(collect($before[$reverseRoleA->id])->push('payments.refund')->sort()->values()->all(), $effective($reverseRoleA->fresh()));
        $this->assertSame(collect($before[$reverseRoleB->id])->push('payments.refund')->sort()->values()->all(), $effective($reverseRoleB->fresh()));
        // ...nobody else gained anything: no escalation, including the Admin stripped of reverse.
        $this->assertSame($before[$plainRoleA->id], $effective($plainRoleA->fresh()));
        $this->assertSame($before[$adminA->id], $effective($adminA->fresh()));
        $this->assertNotContains('payments.refund', $effective($adminA->fresh()));

        // The grant carries the role's own organization (no cross-organization leakage).
        foreach ([$reverseRoleA, $reverseRoleB] as $role) {
            $this->assertSame($role->organization_id, (int) DB::table('role_permission')->where('role_id', $role->id)->where('permission_id', $refundId)->value('organization_id'));
        }
        $this->assertNotContains('payments.refund', $this->keysOfOrg($orgB, $plainRoleA));
        // Owner keeps everything it had (wildcard role) - refund was already part of its set via the catalogue.
        $this->assertEmpty(array_diff($ownerBefore, $ownerA->permissionKeysFor($orgA)));
    }

    public function test_mcp_exposes_no_refund_or_reversal_path(): void
    {
        $forbidden = ['RefundPaymentAction', 'ReversePaymentAction', 'RefundCustomerReturnAction', 'SettleCustomerExchangeRefundAction', 'payments.refund', 'payments.reverse'];
        $hits = [];
        foreach (['app/Services/Mcp', 'app/Mcp'] as $directory) {
            if (! is_dir(base_path($directory))) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory))) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $source = file_get_contents($file->getPathname()) ?: throw new RuntimeException('unreadable');
                    foreach ($forbidden as $needle) {
                        if (str_contains($source, $needle)) {
                            $hits[] = $file->getFilename().' -> '.$needle;
                        }
                    }
                }
            }
        }
        $this->assertSame([], $hits, 'MCP is read-only for refunds and reversals; any new entry point must enforce its own permission and be added to this matrix.');
    }

    /** @param list<string> $base */
    private function member($organization, $store, array $base, bool $refund, bool $reverse): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($organization, $user, array_merge($base, $refund ? ['payments.refund'] : [], $reverse ? ['payments.reverse'] : []), roleName: 'Matrix '.Str::random(6));
        $this->addStoreMember($store, $user);
        $this->activate($user, $organization, $store);

        return $user;
    }

    /** @return list<string> */
    private function keysOfOrg($organization, Role $foreignRole): array
    {
        return DB::table('role_permission as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.organization_id', $organization->id)->where('rp.role_id', $foreignRole->id)->pluck('p.key')->all();
    }
}
