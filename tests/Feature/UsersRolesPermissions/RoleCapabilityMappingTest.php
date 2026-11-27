<?php

namespace Tests\Feature\UsersRolesPermissions;

use App\Models\User;
use App\Support\Roles\RoleCapabilitySummary;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

/** U-5: the plain-language mapping only references REAL permissions and reaches the roles editor unchanged. */
class RoleCapabilityMappingTest extends PlatformTestCase
{
    public function test_every_mapped_permission_exists_in_the_platform_catalogue(): void
    {
        $catalogue = array_keys(config('platform.permissions'));

        foreach (RoleCapabilitySummary::CAPABILITIES as $capability => $keys) {
            foreach ($keys as $key) {
                $this->assertContains($key, $catalogue, "Capability {$capability} references unknown permission {$key}.");
            }
        }
        foreach (RoleCapabilitySummary::CONFLICTS as $conflict => $required) {
            foreach ($required as $capability) {
                $this->assertArrayHasKey($capability, RoleCapabilitySummary::CAPABILITIES, "Conflict {$conflict} references unknown capability {$capability}.");
            }
        }
    }

    public function test_every_capability_and_conflict_has_a_french_and_arabic_label(): void
    {
        $translations = file_get_contents(dirname(__DIR__, 3).'/resources/js/i18n/translations.ts');
        $ids = [
            ...array_map(fn ($id) => "roles.cap.{$id}", array_keys(RoleCapabilitySummary::CAPABILITIES)),
            ...array_map(fn ($id) => "roles.conflict.{$id}.title", array_keys(RoleCapabilitySummary::CONFLICTS)),
            ...array_map(fn ($id) => "roles.conflict.{$id}.body", array_keys(RoleCapabilitySummary::CONFLICTS)),
        ];

        foreach ($ids as $id) {
            $this->assertSame(2, substr_count($translations, "\"{$id}\":"), "{$id} must exist once in FR and once in AR.");
        }
    }

    public function test_roles_page_receives_the_capability_definition(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $this->actingAs($owner)->get(route('users-access.index', $organization))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roleCapabilities.capabilities.take_payments', ['payments.create'])
                ->where('roleCapabilities.capabilities.cancel_payments', ['payments.reverse'])
                ->where('roleCapabilities.capabilities.refund_payments', ['payments.refund'])
                ->where('roleCapabilities.conflicts.payments_cash_control', ['take_payments', 'cancel_payments', 'refund_payments']));
    }
}
