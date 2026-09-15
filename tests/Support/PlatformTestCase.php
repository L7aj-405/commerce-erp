<?php

namespace Tests\Support;

use App\Models\Organization;
use App\Models\OrganizationDocumentStamp;
use App\Models\OrganizationMailSetting;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Services\OrganizationCreator;
use App\Services\StoreCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class PlatformTestCase extends TestCase
{
    use RefreshDatabase;

    protected function createOrganization(User $owner, string $name = 'Organization'): Organization
    {
        return app(OrganizationCreator::class)->create($owner, $name);
    }

    /** @param list<string> $permissions */
    protected function createRole(Organization $organization, array $permissions = [], string $name = 'Custom Role'): Role
    {
        $role = new Role;
        $role->organization_id = $organization->getKey();
        $role->name = $name;
        $role->slug = Str::slug($name).'-'.Str::lower(Str::random(8));
        $role->is_system = false;
        $role->save();

        $permissionModels = Permission::query()->whereIn('key', $permissions)->get();
        $role->permissions()->attach($permissionModels->mapWithKeys(
            fn (Permission $permission) => [$permission->getKey() => ['organization_id' => $organization->getKey()]],
        )->all());

        return $role;
    }

    /** @param list<string> $permissions */
    protected function addOrganizationMember(
        Organization $organization,
        User $user,
        array $permissions = [],
        string $status = 'active',
        string $roleName = 'Custom Role',
    ): OrganizationMembership {
        $role = $this->createRole($organization, $permissions, $roleName);

        $membership = new OrganizationMembership;
        $membership->organization_id = $organization->getKey();
        $membership->user_id = $user->getKey();
        $membership->role_id = $role->getKey();
        $membership->status = $status;
        $membership->save();

        return $membership;
    }

    protected function createStore(Organization $organization, User $creator, string $name = 'Store'): Store
    {
        return app(StoreCreator::class)->create(
            $creator,
            $organization,
            $name,
            Str::upper(Str::random(8)),
        );
    }

    protected function addStoreMember(Store $store, User $user): StoreMembership
    {
        $membership = new StoreMembership;
        $membership->organization_id = $store->organization_id;
        $membership->store_id = $store->getKey();
        $membership->user_id = $user->getKey();
        $membership->save();

        return $membership;
    }

    protected function activate(User $user, Organization $organization, ?Store $store = null): void
    {
        $user->active_organization_id = $organization->getKey();
        $user->active_store_id = $store?->getKey();
        $user->save();
    }

    protected function permission(string $key): Permission
    {
        return Permission::query()->where('key', $key)->firstOrFail();
    }

    /**
     * Gives an organization a usable outbound email configuration, so document
     * email tests can exercise a "configured" organization without depending on
     * the Settings HTTP flow. Uses a fake (non-routable) SMTP host — every test
     * that sends through it must call Mail::fake() first.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function configureOrganizationMail(Organization $organization, array $overrides = []): OrganizationMailSetting
    {
        $setting = new OrganizationMailSetting;
        $setting->organization_id = $organization->getKey();
        $setting->sender_name = $overrides['sender_name'] ?? $organization->name;
        $setting->sender_email = $overrides['sender_email'] ?? 'no-reply@example.test';
        $setting->smtp_host = $overrides['smtp_host'] ?? 'smtp.example.test';
        $setting->smtp_port = $overrides['smtp_port'] ?? 587;
        $setting->smtp_username = $overrides['smtp_username'] ?? 'no-reply@example.test';
        $setting->smtp_password = $overrides['smtp_password'] ?? 'super-secret';
        $setting->smtp_encryption = $overrides['smtp_encryption'] ?? 'tls';
        $setting->reply_to_email = $overrides['reply_to_email'] ?? null;
        $setting->reply_to_name = $overrides['reply_to_name'] ?? null;
        $setting->is_enabled = $overrides['is_enabled'] ?? true;
        $setting->save();

        return $setting;
    }

    /**
     * Gives an organization an active stamp version directly (bypassing the
     * upload HTTP endpoint), for tests about apposition/rendering rather than
     * the upload flow itself. Call Storage::fake('local') first.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function configureOrganizationStamp(Organization $organization, array $overrides = []): OrganizationDocumentStamp
    {
        $path = $overrides['image_path'] ?? 'organization-stamps/'.$organization->getKey().'/'.Str::random(12).'.png';
        // A valid 1x1 PNG — same fixture pixel used by InvoicePdfPaginationTest
        // for the seller-logo snapshot tests.
        Storage::disk('local')->put(
            $path,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        );

        $stamp = new OrganizationDocumentStamp;
        $stamp->organization_id = $organization->getKey();
        $stamp->image_path = $path;
        $stamp->original_filename = $overrides['original_filename'] ?? 'cachet.png';
        $stamp->mime_type = $overrides['mime_type'] ?? 'image/png';
        $stamp->image_width = $overrides['image_width'] ?? 200;
        $stamp->image_height = $overrides['image_height'] ?? 200;
        $stamp->position_anchor = $overrides['position_anchor'] ?? 'bottom_left';
        $stamp->offset_x_mm = $overrides['offset_x_mm'] ?? 0;
        $stamp->offset_y_mm = $overrides['offset_y_mm'] ?? 5;
        $stamp->display_width_mm = $overrides['display_width_mm'] ?? 35;
        $stamp->rotation_deg = $overrides['rotation_deg'] ?? 0;
        $stamp->source = $overrides['source'] ?? 'uploaded';
        $stamp->active = $overrides['active'] ?? true;
        $stamp->save();

        return $stamp;
    }
}
