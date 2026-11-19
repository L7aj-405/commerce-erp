<?php

namespace Tests\Feature\Console;

use App\Actions\Payments\RefundPaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Console\Commands\QaAccessCommand;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PaymentTestCase;

class QaAccessCommandTest extends PaymentTestCase
{
    private const EMAILS = [
        'refund' => 'qa-refund@qa-staging.test', 'reverse' => 'qa-reverse@qa-staging.test',
        'both' => 'qa-both@qa-staging.test', 'neither' => 'qa-neither@qa-staging.test',
    ];

    /** @return array{User, Organization, Store} the QA administrator (Owner) with the QA organization and store active */
    private function qaWorld(): array
    {
        $admin = User::factory()->create();
        $organization = $this->createOrganization($admin, '10xScale QA Morocco');
        $store = $this->createStore($organization, $admin, 'QA Showroom');
        $this->activate($admin, $organization, $store);

        return [$admin, $organization, $store];
    }

    /** @return array{int, string} */
    private function qa(string $action, User $actor, bool $execute = true, array $emails = self::EMAILS): array
    {
        $arguments = ['action' => $action, '--actor' => $actor->email];
        foreach ($emails as $key => $email) {
            $arguments["--email-{$key}"] = $email;
        }
        if ($execute) {
            $arguments['--execute'] = true;
        }
        $code = Artisan::call('erp:qa-access', $arguments);

        return [$code, Artisan::output()];
    }

    public function test_refuses_to_run_in_production(): void
    {
        [$admin] = $this->qaWorld();
        $this->app['env'] = 'production';

        [$code] = $this->qa('setup', $admin);

        $this->assertNotSame(0, $code);
        $this->assertSame(0, User::query()->whereIn('email', self::EMAILS)->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        [$admin, $organization] = $this->qaWorld();
        $before = [Role::query()->count(), User::query()->count(), OrganizationMembership::query()->count()];

        [$code, $output] = $this->qa('setup', $admin, execute: false);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Dry run', $output);

        $this->assertSame($before, [Role::query()->count(), User::query()->count(), OrganizationMembership::query()->count()]);
    }

    public function test_setup_creates_exactly_four_roles_and_accounts_and_leaves_existing_roles_alone(): void
    {
        [$admin, $organization, $store] = $this->qaWorld();
        $existing = Role::query()->where('organization_id', $organization->id)->get()->mapWithKeys(fn ($r) => [$r->id => [$r->name, $r->permissions()->pluck('key')->sort()->values()->all()]])->all();

        [$code, $output] = $this->qa('setup', $admin);
        $this->assertSame(0, $code, $output);
        $this->assertStringNotContainsString('password:', $output);

        foreach ($existing as $id => [$name, $permissions]) {
            $role = Role::query()->findOrFail($id);
            $this->assertSame([$name, $permissions], [$role->name, $role->permissions()->pluck('key')->sort()->values()->all()]);
        }
        $expected = [
            'refund' => [...QaAccessCommand::BASE_PERMISSIONS, 'payments.refund'],
            'reverse' => [...QaAccessCommand::BASE_PERMISSIONS, 'payments.reverse'],
            'both' => [...QaAccessCommand::BASE_PERMISSIONS, 'payments.refund', 'payments.reverse'],
            'neither' => QaAccessCommand::BASE_PERMISSIONS,
        ];
        foreach (self::EMAILS as $key => $email) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $membership = OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail();
            $this->assertSame('qa-step6-'.$key, $membership->role->slug);
            $this->assertFalse($membership->role->is_system);
            $this->assertSame(collect($expected[$key])->sort()->values()->all(), $membership->role->permissions()->pluck('key')->sort()->values()->all());
            $this->assertTrue($store->memberships()->where('user_id', $user->id)->exists());
            $this->assertFalse($user->hasPermission($organization, 'roles.assign-permissions'));
        }
        $this->assertSame(4, Role::query()->where('slug', 'like', 'qa-step6-%')->count());
        $this->assertStringContainsString('/reset-password/', $output);
        $this->assertSame(0, DB::table('user_invitations')->count());

        // Idempotent re-run: no duplicate roles, accounts or memberships.
        [$code] = $this->qa('setup', $admin);
        $this->assertSame(0, $code);
        $this->assertSame(4, Role::query()->where('slug', 'like', 'qa-step6-%')->count());
        $this->assertSame(4, User::query()->whereIn('email', self::EMAILS)->count());
    }

    public function test_no_mail_is_sent_and_a_qa_user_signs_in_through_the_one_time_link_and_normal_login(): void
    {
        Mail::fake();
        Notification::fake();
        [$admin] = $this->qaWorld();
        [, $output] = $this->qa('setup', $admin);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();

        preg_match('#/reset-password/([A-Za-z0-9]+)\?email=#', $output, $match);
        $this->assertNotEmpty($match);
        $this->post('/reset-password', ['token' => $match[1], 'email' => self::EMAILS['refund'], 'password' => 'Qa-Test-Only-9xKq!2', 'password_confirmation' => 'Qa-Test-Only-9xKq!2'])->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->post('/login', ['email' => self::EMAILS['refund'], 'password' => 'Qa-Test-Only-9xKq!2'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs(User::query()->where('email', self::EMAILS['refund'])->firstOrFail());
    }

    public function test_an_actor_missing_a_permission_cannot_delegate_it_and_nothing_is_created(): void
    {
        [$owner, $organization, $store] = $this->qaWorld();
        $actor = User::factory()->create();
        $this->addOrganizationMember($organization, $actor, ['members.create', 'roles.assign-permissions', 'store-memberships.manage', ...QaAccessCommand::BASE_PERMISSIONS, 'payments.reverse'], roleName: 'QA admin without refund');
        $this->addStoreMember($store, $actor);
        $this->activate($actor, $organization, $store);

        [$code, $output] = $this->qa('setup', $actor);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('payments.refund', $output);
        $this->assertSame(0, Role::query()->where('slug', 'like', 'qa-step6-%')->count());
        $this->assertSame(0, User::query()->whereIn('email', self::EMAILS)->count());
    }

    public function test_an_existing_account_is_never_modified(): void
    {
        [$admin] = $this->qaWorld();
        $existing = User::factory()->create(['email' => self::EMAILS['both']]);
        $hash = $existing->password;

        [$code] = $this->qa('setup', $admin);

        $this->assertNotSame(0, $code);
        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertSame(0, Role::query()->where('slug', 'like', 'qa-step6-%')->count());
    }

    public function test_the_wrong_organization_or_an_inactive_actor_context_is_refused(): void
    {
        [$admin, $organization] = $this->qaWorld();
        $admin->active_store_id = null;
        $admin->save();

        [$code] = $this->qa('setup', $admin);
        $this->assertNotSame(0, $code);
        $this->assertSame(0, User::query()->whereIn('email', self::EMAILS)->count());

        $this->activate($admin, $organization, $this->createStore($organization, $admin, 'Other Store'));
        [$code] = $this->qa('setup', $admin);
        $this->assertNotSame(0, $code);
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function matrix(): array
    {
        return ['refund only' => ['refund', true, false], 'reverse only' => ['reverse', false, true], 'both' => ['both', true, true], 'neither' => ['neither', false, false]];
    }

    #[DataProvider('matrix')]
    public function test_backend_enforces_each_combination_for_the_provisioned_accounts(string $key, bool $canRefund, bool $canReverse): void
    {
        [$admin, $organization, $store] = $this->qaWorld();
        $this->qa('setup', $admin);
        $qa = User::query()->where('email', self::EMAILS[$key])->firstOrFail();
        $this->activate($qa, $organization, $store);

        $account = $this->createFinancialAccount($organization);
        $order = fn () => $this->confirmedOrder($admin, $organization, $store);

        // Refund (direct) and Reverse (direct) on separate payments so one outcome never masks the other.
        $refundTarget = $order();
        $refundPayment = $this->recordPayment($admin, $refundTarget, $account, '100.0000');
        $reverseTarget = $order();
        $reversePayment = $this->recordPayment($admin, $reverseTarget, $account, '100.0000');
        $cancelTarget = $order();
        $this->recordPayment($admin, $cancelTarget, $account, '100.0000');

        $this->assertSame($canRefund, $this->allowed(fn () => app(RefundPaymentAction::class)->execute($qa, $refundPayment, $refundTarget->fresh(), 'QA matrix')));
        $this->assertSame($canReverse, $this->allowed(fn () => app(ReversePaymentAction::class)->execute($qa, $reversePayment, $reverseTarget->fresh(), 'QA matrix')));
        // Cancelling a PAID order refunds money, so it follows payments.refund (the user may cancel orders in every role).
        $this->assertSame($canRefund, $this->allowed(fn () => app(CancelSalesOrderAction::class)->execute($qa, $cancelTarget->fresh(), 'QA matrix')));
    }

    public function test_cleanup_removes_access_and_roles_locks_accounts_and_never_touches_other_roles(): void
    {
        [$admin, $organization, $store] = $this->qaWorld();
        $this->qa('setup', $admin);
        $untouched = Role::query()->where('organization_id', $organization->id)->where('slug', 'not like', 'qa-step6-%')->pluck('name', 'id')->all();
        $qa = User::query()->where('email', self::EMAILS['refund'])->firstOrFail();
        $oldHash = $qa->password;

        [$dry] = $this->qa('cleanup', $admin, execute: false);
        $this->assertSame(0, $dry);
        $this->assertSame(4, Role::query()->where('slug', 'like', 'qa-step6-%')->count());

        [$code, $output] = $this->qa('cleanup', $admin);

        $this->assertSame(0, $code, $output);
        $this->assertSame(0, Role::query()->where('slug', 'like', 'qa-step6-%')->count());
        $this->assertSame(0, OrganizationMembership::query()->whereIn('user_id', User::query()->whereIn('email', self::EMAILS)->pluck('id'))->count());
        $this->assertSame(0, $store->memberships()->whereIn('user_id', User::query()->whereIn('email', self::EMAILS)->pluck('id'))->count());
        $this->assertNotSame($oldHash, $qa->fresh()->password);
        $this->assertSame($untouched, Role::query()->where('organization_id', $organization->id)->where('slug', 'not like', 'qa-step6-%')->pluck('name', 'id')->all());
        $this->assertTrue(OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $admin->id)->exists());
    }

    private function confirmedOrder(User $owner, $organization, $store)
    {
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '100.0000']);

        return app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
    }

    private function allowed(callable $call): bool
    {
        try {
            $call();

            return true;
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return false;
        }
    }
}
