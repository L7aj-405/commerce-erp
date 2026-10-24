<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Support\PlatformTestCase;

class OrganizationTimezoneTest extends PlatformTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unconfigured_or_invalid_timezone_resolves_to_utc(): void
    {
        $organization = $this->createOrganization(User::factory()->create());
        $this->assertSame('UTC', $organization->businessTimezone());
        $this->assertFalse($organization->hasConfiguredTimezone());

        $organization->settings = ['timezone' => 'Mars/Olympus'];
        $this->assertSame('UTC', $organization->businessTimezone());
        $this->assertFalse($organization->hasConfiguredTimezone());
    }

    public function test_settings_admin_sets_a_valid_iana_timezone_and_it_is_audited(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['document_profile' => ['legal_name' => 'Keep Me']];
        $organization->save();
        $this->activate($owner, $organization);

        $this->actingAs($owner)->put(route('organization-timezone.update'), ['timezone' => 'Africa/Casablanca'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $organization->refresh();
        $this->assertSame('Africa/Casablanca', $organization->businessTimezone());
        $this->assertTrue($organization->hasConfiguredTimezone());
        $this->assertSame('Keep Me', data_get($organization->settings, 'document_profile.legal_name'));
        $log = AuditLog::query()->where('event', 'organization.settings_changed')->latest('id')->firstOrFail();
        $this->assertSame(['timezone' => 'Africa/Casablanca'], $log->new_values);
    }

    public function test_invalid_timezones_are_rejected(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        foreach (['+01:00', 'CET', 'africa/casablanca', 'Mars/Olympus', ''] as $value) {
            $this->actingAs($owner)->put(route('organization-timezone.update'), ['timezone' => $value])
                ->assertSessionHasErrors('timezone');
        }
        $this->assertFalse($organization->refresh()->hasConfiguredTimezone());
    }

    public function test_settings_viewer_cannot_change_the_timezone(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $viewer, ['settings.view']);
        $this->activate($viewer, $organization);

        $this->actingAs($viewer)->put(route('organization-timezone.update'), ['timezone' => 'Europe/Paris'])->assertForbidden();
    }

    public function test_local_to_utc_conversion_uses_the_organization_timezone(): void
    {
        $organization = new Organization(['settings' => ['timezone' => 'Europe/Paris']]);

        $this->assertSame('2026-10-26T09:00:00+00:00', BusinessTime::localToUtc($organization, '2026-10-26', '10:00')->toIso8601String());
        $this->assertSame('2026-07-01T08:00:00+00:00', BusinessTime::localToUtc($organization, '2026-07-01', '10:00')->toIso8601String());
        $this->assertSame('2026-10-25T23:00:00+00:00', BusinessTime::localToUtc($organization, '2026-10-26')->toIso8601String());

        $this->expectException(InvalidArgumentException::class);
        BusinessTime::localToUtc($organization, '2026-03-29', '02:30'); // skipped by DST
    }

    public function test_quotation_validity_uses_the_organization_calendar_date(): void
    {
        // 23:30 UTC on 26 Oct is already 27 Oct in Asia/Tokyo but still 26 Oct in UTC.
        Carbon::setTestNow('2026-10-26 23:30:00');
        $quotation = new Quotation;
        $quotation->valid_until = '2026-10-26';

        $quotation->setRelation('organization', new Organization(['settings' => []]));
        $this->assertFalse($quotation->isPastValidity());

        $quotation->setRelation('organization', new Organization(['settings' => ['timezone' => 'Asia/Tokyo']]));
        $this->assertTrue($quotation->isPastValidity());
    }
}
