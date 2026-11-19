<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\MembershipService;
use App\Services\RolePermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Staging-only QA access for the Step 6 refund/reverse matrix: four roles (refund only, reverse only, both, neither)
 * and four accounts, scoped to ONE store of ONE organization.
 *
 * Everything is performed AS an existing QA administrator through the same services the UI uses
 * (RolePermissionService / MembershipService), so the escalation guards stay in force: a role can only receive
 * permissions the administrator holds, and only that administrator's own organization/store context is accepted.
 *
 * - Refuses to run when APP_ENV=production. Dry run unless --execute.
 * - Never touches an existing Owner/Admin/staff role or account: it only manages roles whose slug starts with
 *   `qa-step6-` and accounts it created itself (marked by an audit event).
 * - No invitation, no email. Accounts get an unusable random password; the operator receives one-time
 *   password-setup links (standard reset token) and hands them over privately. Nothing is stored in the repository.
 * - `cleanup` removes memberships and roles and locks the accounts (random password, sessions and tokens revoked).
 *   Account rows are kept so audit history and any records the QA users created stay intact.
 */
class QaAccessCommand extends Command
{
    public const ROLE_PREFIX = 'qa-step6-';

    public const MARKER_EVENT = 'qa_access.user_provisioned';

    /** Identical for all four roles, so the two payment permissions are the ONLY difference between them. */
    public const BASE_PERMISSIONS = [
        'sales_orders.view', 'sales_orders.cancel',   // cancel a paid order (refund path), view the order
        'payments.view',                              // open payments (reverse path)
        'sales_returns.view', 'sales_exchanges.view', // reach return / exchange screens (refund path)
        'sales_exchanges.process',                    // settle an exchange (refund path)
    ];

    /** @var array<string, array{name: string, permissions: list<string>}> */
    public const MATRIX = [
        'refund' => ['name' => 'QA Refund only', 'permissions' => ['payments.refund']],
        'reverse' => ['name' => 'QA Reverse only', 'permissions' => ['payments.reverse']],
        'both' => ['name' => 'QA Refund and Reverse', 'permissions' => ['payments.refund', 'payments.reverse']],
        'neither' => ['name' => 'QA Neither', 'permissions' => []],
    ];

    protected $signature = 'erp:qa-access
        {action : plan | setup | links | cleanup}
        {--actor= : Email of the existing QA administrator (member of the organization and of the store, holding the permissions to delegate)}
        {--organization=10xScale QA Morocco : Exact organization name}
        {--store=QA Showroom : Exact store name}
        {--email-refund= : Email for the refund-only account}
        {--email-reverse= : Email for the reverse-only account}
        {--email-both= : Email for the refund+reverse account}
        {--email-neither= : Email for the neither account}
        {--execute : Apply the change (without it the command only prints what it would do)}';

    protected $description = 'Staging-only: provision or remove the four Step 6 QA roles/accounts for one organization store';

    public function handle(MembershipService $memberships, RolePermissionService $permissionService, AuditLogger $audit, ActiveTenantContext $context): int
    {
        if (app()->environment('production')) {
            $this->error('Refused: this command never runs in production.');

            return self::FAILURE;
        }

        $action = (string) $this->argument('action');
        if (! in_array($action, ['plan', 'setup', 'links', 'cleanup'], true)) {
            $this->error('Action must be plan, setup, links or cleanup.');

            return self::INVALID;
        }

        $emails = [];
        foreach (array_keys(self::MATRIX) as $key) {
            $email = mb_strtolower(trim((string) $this->option("email-{$key}")));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error("--email-{$key} must be a valid email address.");

                return self::INVALID;
            }
            $emails[$key] = $email;
        }
        if (count(array_unique($emails)) !== 4) {
            $this->error('The four emails must be distinct.');

            return self::INVALID;
        }

        $organizations = Organization::query()->where('name', (string) $this->option('organization'))->get();
        if ($organizations->count() !== 1) {
            $this->error("Expected exactly one organization named \"{$this->option('organization')}\", found {$organizations->count()}.");

            return self::FAILURE;
        }
        /** @var Organization $organization */
        $organization = $organizations->first();

        $store = Store::query()->where('organization_id', $organization->getKey())->where('name', (string) $this->option('store'))->where('status', 'active')->first();
        if (! $store) {
            $this->error("No active store \"{$this->option('store')}\" in this organization.");

            return self::FAILURE;
        }

        $actor = User::query()->where('email', mb_strtolower(trim((string) $this->option('actor'))))->first();
        $context->resolve($actor);
        if (! $actor || $context->organization()?->getKey() !== $organization->getKey() || $context->store()?->getKey() !== $store->getKey()) {
            $this->error('The actor must exist and have this organization and store ACTIVE (log in as that administrator and select them once). Nothing was changed.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute') && $action !== 'plan';
        $this->line(sprintf('Organization #%d "%s", store #%d "%s", actor #%d. Mode: %s.', $organization->getKey(), $organization->name, $store->getKey(), $store->name, $actor->getKey(), $execute ? 'EXECUTE' : 'dry run'));

        try {
            return match ($action) {
                'links' => $this->links($emails, $execute),
                'cleanup' => $this->cleanup($emails, $organization, $store, $actor, $memberships, $audit, $execute),
                default => $this->setup($emails, $organization, $store, $actor, $memberships, $permissionService, $audit, $execute),
            };
        } catch (HttpException $exception) {
            $this->error("Stopped by an existing authorization control (HTTP {$exception->getStatusCode()}): {$exception->getMessage()} Nothing further was applied.");

            return self::FAILURE;
        }
    }

    /** @param array<string, string> $emails */
    private function setup(array $emails, Organization $organization, Store $store, User $actor, MembershipService $memberships, RolePermissionService $permissionService, AuditLogger $audit, bool $execute): int
    {
        // Existing accounts are never modified: an email that exists but was not created by this command is a stop.
        foreach ($emails as $email) {
            $existing = User::query()->where('email', $email)->first();
            if ($existing && ! $this->isProvisioned($existing)) {
                $this->error("An account for {$email} already exists and was not created by this command; it is never modified. Use another email.");

                return self::FAILURE;
            }
        }

        $held = $actor->permissionKeysFor($organization);
        foreach (self::MATRIX as $key => $definition) {
            $wanted = array_merge(self::BASE_PERMISSIONS, $definition['permissions']);
            $missing = array_values(array_diff($wanted, $held));
            $this->line(sprintf('- role "%s" (%s%s): %s', $definition['name'], self::ROLE_PREFIX, $key, implode(', ', $wanted), $missing === [] ? 'delegable by the actor' : 'NOT delegable, actor lacks: '.implode(', ', $missing)));
            if ($missing !== []) {
                $this->error('The actor does not hold every permission to delegate. Grant them legitimately first (Users & Access); escalation controls are not bypassed.');

                return self::FAILURE;
            }
        }
        if (! $execute) {
            $this->info('Dry run complete. Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        $links = [];
        foreach (self::MATRIX as $key => $definition) {
            $role = $this->ensureRole($organization, $key, $definition['name'], $actor, $audit);
            $permissions = Permission::query()->whereIn('key', array_merge(self::BASE_PERMISSIONS, $definition['permissions']))->get();
            $permissionService->sync($actor, $role, $permissions);

            $user = User::query()->where('email', $emails[$key])->first();
            if (! $user) {
                $user = new User;
                $user->name = 'QA Step 6 - '.$definition['name'];
                $user->email = $emails[$key];
                $user->password = Str::random(64); // unusable until the setup link is used
                $user->email_verified_at = now();
                $user->save();
                $audit->record(self::MARKER_EVENT, $actor, $organization, auditable: $user, newValues: ['role_slug' => $role->slug]);
            }

            $membership = OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $user->getKey())->first();
            if (! $membership) {
                $memberships->addOrganizationMember($actor, $organization, $user, $role);
            } elseif ((int) $membership->role_id !== (int) $role->getKey()) {
                $memberships->changeRole($actor, $membership, $role);
            }
            if (! StoreMembership::query()->where('store_id', $store->getKey())->where('user_id', $user->getKey())->exists()) {
                $memberships->addStoreMember($actor, $store, $user);
            }
            $links[$key] = $this->resetLink($user);
        }

        $this->printLinks($emails, $links);

        return self::SUCCESS;
    }

    /** @param array<string, string> $emails */
    private function links(array $emails, bool $execute): int
    {
        $links = [];
        foreach ($emails as $key => $email) {
            $user = User::query()->where('email', $email)->first();
            if (! $user || ! $this->isProvisioned($user)) {
                $this->error("{$email} is not a QA account created by this command.");

                return self::FAILURE;
            }
            if ($execute) {
                $links[$key] = $this->resetLink($user);
            }
        }
        $execute ? $this->printLinks($emails, $links) : $this->info('Dry run: would issue four fresh one-time links. Re-run with --execute.');

        return self::SUCCESS;
    }

    /** @param array<string, string> $emails */
    private function cleanup(array $emails, Organization $organization, Store $store, User $actor, MembershipService $memberships, AuditLogger $audit, bool $execute): int
    {
        $roles = Role::query()->where('organization_id', $organization->getKey())->where('is_system', false)->where('slug', 'like', self::ROLE_PREFIX.'%')->get();
        $this->line('Would remove store access, organization membership and lock: '.implode(', ', $emails).'; delete roles: '.($roles->pluck('slug')->implode(', ') ?: '(none)'));
        if (! $execute) {
            $this->info('Dry run complete. Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        foreach ($emails as $email) {
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                continue;
            }
            if (! $this->isProvisioned($user)) {
                $this->warn("Skipped {$email}: not created by this command.");

                continue;
            }
            StoreMembership::query()->where('store_id', $store->getKey())->where('user_id', $user->getKey())->each(fn (StoreMembership $m) => $memberships->removeStoreMember($actor, $m));
            OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $user->getKey())->each(fn (OrganizationMembership $m) => $memberships->removeOrganizationMember($actor, $m));

            $user->password = Str::random(64);
            $user->active_organization_id = null;
            $user->active_store_id = null;
            $user->remember_token = null;
            $user->save();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
            }
            $audit->record('qa_access.user_locked', $actor, $organization, auditable: $user);
        }

        foreach ($roles as $role) {
            if (OrganizationMembership::query()->where('role_id', $role->getKey())->exists()) {
                $this->warn("Role {$role->slug} still has members and was kept.");

                continue;
            }
            $audit->record('role.deleted', $actor, $organization, oldValues: $role->only(['name', 'slug']));
            $role->delete();
        }
        $this->info('Cleanup complete. Account rows are kept (locked) so audit history and QA-created records stay intact.');

        return self::SUCCESS;
    }

    private function ensureRole(Organization $organization, string $key, string $name, User $actor, AuditLogger $audit): Role
    {
        $slug = self::ROLE_PREFIX.$key;
        $role = Role::query()->where('organization_id', $organization->getKey())->where('slug', $slug)->first();
        if ($role) {
            abort_if($role->is_system, 403, 'A system role carries this slug.');

            return $role;
        }

        $role = new Role;
        $role->organization_id = $organization->getKey();
        $role->name = $name;
        $role->slug = $slug;
        $role->is_system = false;
        $role->save();
        $audit->record('role.created', $actor, $organization, auditable: $role, newValues: ['name' => $role->name, 'slug' => $role->slug, 'preset_slug' => null]);

        return $role;
    }

    private function isProvisioned(User $user): bool
    {
        return AuditLog::query()->where('event', self::MARKER_EVENT)->where('auditable_type', $user->getMorphClass())->where('auditable_id', $user->getKey())->exists();
    }

    private function resetLink(User $user): string
    {
        $token = Password::broker()->createToken($user);

        return url(route('password.reset', ['token' => $token, 'email' => $user->email], false));
    }

    /**
     * @param  array<string, string>  $emails
     * @param  array<string, string>  $links
     */
    private function printLinks(array $emails, array $links): void
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords', 'users').'.expire', 60);
        $this->info("Setup complete. One-time password-setup links (valid {$minutes} minutes; hand over privately, never commit or paste into tickets):");
        foreach ($links as $key => $link) {
            $this->line(sprintf('%-8s %s%s  %s', $key, '', $emails[$key], $link));
        }
    }
}
