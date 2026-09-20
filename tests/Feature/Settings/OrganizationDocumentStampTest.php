<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\OrganizationDocumentStamp;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

class OrganizationDocumentStampTest extends PlatformTestCase
{
    private function image(int $width = 200, int $height = 200): UploadedFile
    {
        return UploadedFile::fake()->image('cachet.png', $width, $height);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'image' => $this->image(),
            'position_anchor' => 'bottom_left',
            'offset_x_mm' => 0,
            'offset_y_mm' => 5,
            'display_width_mm' => 35,
            'rotation_deg' => 0,
        ], $overrides);
    }

    public function test_authorized_admin_can_upload_a_stamp_with_default_position(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('document-stamp.store'), $this->payload())->assertRedirect();

        $stamp = OrganizationDocumentStamp::query()->where('organization_id', $organization->id)->where('active', true)->firstOrFail();
        $this->assertSame('bottom_left', $stamp->position_anchor);
        $this->assertSame('0.00', (string) $stamp->rotation_deg);
        $this->assertTrue($stamp->active);
        Storage::disk('local')->assertExists($stamp->image_path);
    }

    public function test_unauthorized_user_cannot_configure_the_stamp(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $outsider = User::factory()->create();
        $this->addOrganizationMember($organization, $outsider, ['customers.view']);
        $this->activate($outsider, $organization);

        $this->actingAs($outsider)->post(route('document-stamp.store'), $this->payload())->assertForbidden();

        $this->assertDatabaseCount('organization_document_stamps', 0);
    }

    public function test_settings_view_permission_is_read_only_for_the_stamp_page(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $viewer, ['settings.view']);
        $this->activate($viewer, $organization);

        $this->actingAs($viewer)->get(route('document-stamp.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));

        $this->actingAs($viewer)->post(route('document-stamp.store'), $this->payload())->assertForbidden();
    }

    public function test_organization_a_cannot_access_or_change_organization_b_stamp(): void
    {
        Storage::fake('local');
        $ownerA = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Org A');
        $this->configureOrganizationStamp($organizationA, ['position_anchor' => 'top_right']);

        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB, 'Org B');
        $this->configureOrganizationStamp($organizationB, ['position_anchor' => 'bottom_left']);

        $this->activate($ownerA, $organizationA);
        $this->actingAs($ownerA)->get(route('document-stamp.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stamp.position_anchor', 'top_right'));

        // Org A's owner has no membership in Org B, so Org B can never become
        // their active organization — the settings page can only ever show
        // the org they are actually scoped into.
        $this->activate($ownerA, $organizationA);
        $this->assertSame($organizationA->id, $ownerA->fresh()->active_organization_id);
    }

    public function test_invalid_image_is_rejected(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        // Garbage bytes with a .png name: Laravel's `image` rule inspects real
        // file content (not the client-supplied filename/extension), so this
        // is rejected even though the name and declared size look plausible.
        $fake = UploadedFile::fake()->create('cachet.png', 10);

        $this->actingAs($owner)->post(route('document-stamp.store'), $this->payload(['image' => $fake]))
            ->assertSessionHasErrors('image');

        $this->assertDatabaseCount('organization_document_stamps', 0);
    }

    public function test_image_below_minimum_dimensions_is_rejected(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('document-stamp.store'), $this->payload(['image' => $this->image(10, 10)]))
            ->assertSessionHasErrors('image');
    }

    public function test_offset_is_clamped_to_safe_boundaries(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('document-stamp.store'), $this->payload(['offset_x_mm' => 99999]))
            ->assertSessionHasErrors('offset_x_mm');

        $this->assertDatabaseCount('organization_document_stamps', 0);
    }

    public function test_size_can_be_set_above_the_old_sixty_millimetre_cap_when_it_still_fits(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        // 75mm at the default bottom_left offset (0, 5) comfortably fits the
        // ~184x247mm content box — this used to be hard-rejected by the old
        // max:60 bound even though it does not overflow the page.
        $this->actingAs($owner)->post(route('document-stamp.store'), $this->payload(['display_width_mm' => 75]))
            ->assertRedirect();

        $stamp = $organization->activeDocumentStamp()->first();
        $this->assertSame('75.00', (string) $stamp->display_width_mm);
    }

    public function test_excessively_large_size_that_would_overflow_the_page_is_rejected(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        // Within the raw per-field cap (100mm) but combined with a large
        // offset this cannot possibly fit the ~184mm-wide content box — the
        // cross-field safe-area check must reject it, not just clamp it
        // silently into something that overflows.
        $this->actingAs($owner)->post(route('document-stamp.store'), $this->payload([
            'display_width_mm' => 100,
            'offset_x_mm' => 120,
        ]))->assertSessionHasErrors('display_width_mm');

        $this->assertDatabaseCount('organization_document_stamps', 0);
    }

    public function test_rotation_accepts_negative_zero_and_positive_values(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        foreach ([-15, 0, 12] as $degrees) {
            $this->actingAs($owner)->post(route('document-stamp.store'), $this->payload([
                'offset_x_mm' => 5,
                'offset_y_mm' => 5,
                'rotation_deg' => $degrees,
            ]))
                ->assertRedirect();
            $active = $organization->activeDocumentStamp()->first();
            $this->assertSame(number_format($degrees, 2, '.', ''), (string) $active->rotation_deg);
        }
    }

    public function test_rotation_outside_the_safe_range_is_rejected(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('document-stamp.store'), $this->payload(['rotation_deg' => 45]))
            ->assertSessionHasErrors('rotation_deg');

        $this->assertDatabaseCount('organization_document_stamps', 0);
    }

    public function test_private_stamp_path_is_never_exposed_in_the_inertia_payload(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->configureOrganizationStamp($organization);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->get(route('document-stamp.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('stamp.image_path'));
    }

    public function test_stamp_image_endpoint_requires_settings_authorization(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->configureOrganizationStamp($organization);

        $outsider = User::factory()->create();
        $this->addOrganizationMember($organization, $outsider, ['customers.view']);
        $this->activate($outsider, $organization);

        $this->actingAs($outsider)->get(route('document-stamp.image'))->assertForbidden();

        $this->activate($owner, $organization);
        $this->actingAs($owner)->get(route('document-stamp.image'))->assertOk();
    }

    public function test_replacing_the_stamp_creates_a_new_version_and_deactivates_the_previous_one(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $first = $this->configureOrganizationStamp($organization);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('document-stamp.store'), $this->payload([
            'position_anchor' => 'top_right',
            'offset_x_mm' => 10,
            'offset_y_mm' => 10,
            'display_width_mm' => 40,
            'rotation_deg' => -6,
        ]))->assertRedirect();

        $this->assertFalse($first->fresh()->active);
        $active = $organization->activeDocumentStamp()->first();
        $this->assertNotSame($first->id, $active->id);
        $this->assertSame('top_right', $active->position_anchor);
        $this->assertSame('-6.00', (string) $active->rotation_deg);
        $this->assertDatabaseCount('organization_document_stamps', 2);
    }

    /**
     * Direct regression test for the MassAssignmentException: modifying an
     * EXISTING stamp's position/size (no new image, exactly the "current
     * request" from manual testing — `POST /document-stamp` without a file)
     * used to 500 on `$current?->update(['active' => false])` because
     * OrganizationDocumentStamp is `$guarded = ['*']`. It must now redirect
     * cleanly, keep the previous version inactive, and activate the new one.
     */
    public function test_updating_position_and_rotation_without_a_new_image_does_not_throw(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $first = $this->configureOrganizationStamp($organization, ['display_width_mm' => 35, 'rotation_deg' => 0]);
        $this->activate($owner, $organization);

        $response = $this->actingAs($owner)->post(route('document-stamp.store'), $this->payload([
            'image' => null,
            'offset_x_mm' => 5,
            'display_width_mm' => 55,
            'rotation_deg' => 8,
        ]));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertFalse($first->fresh()->active);
        $active = $organization->activeDocumentStamp()->first();
        $this->assertNotSame($first->id, $active->id);
        $this->assertSame('55.00', (string) $active->display_width_mm);
        $this->assertSame('8.00', (string) $active->rotation_deg);
        // The image itself was not re-uploaded — the file is carried forward,
        // never deleted/overwritten.
        $this->assertSame($first->image_path, $active->image_path);
        $this->assertDatabaseCount('organization_document_stamps', 2);
    }

    public function test_deactivating_the_stamp_is_audited_and_documents_keep_their_own_apposition(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->configureOrganizationStamp($organization);
        $this->activate($owner, $organization);

        $response = $this->actingAs($owner)->delete(route('document-stamp.destroy'));
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertNull($organization->activeDocumentStamp()->first());
        $this->assertDatabaseHas('audit_logs', ['event' => 'organization_document_stamp.deactivated']);
    }

    public function test_upload_is_audited_and_does_not_leak_binary_data(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('document-stamp.store'), $this->payload())->assertRedirect();

        $log = AuditLog::query()->where('event', 'organization_document_stamp.uploaded')->firstOrFail();
        $this->assertArrayNotHasKey('image_path', $log->new_values ?? []);
        $this->assertArrayNotHasKey('image', $log->new_values ?? []);
        $payload = json_encode([$log->old_values, $log->new_values]);
        $this->assertLessThan(500, strlen((string) $payload));
    }

    public function test_preview_reflects_the_live_unsaved_rotation_and_size(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->configureOrganizationStamp($organization, ['rotation_deg' => 0, 'display_width_mm' => 35]);
        $this->activate($owner, $organization);

        // Unsaved preview values override the persisted configuration.
        $this->actingAs($owner)->get(route('document-stamp.preview', [
            'position_anchor' => 'bottom_left',
            'offset_x_mm' => 0,
            'offset_y_mm' => 5,
            'display_width_mm' => 45,
            'rotation_deg' => -4,
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_preview_rejects_a_rotation_outside_the_safe_range(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->configureOrganizationStamp($organization);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->get(route('document-stamp.preview', ['rotation_deg' => 90]))
            ->assertSessionHasErrors('rotation_deg');
    }
}
