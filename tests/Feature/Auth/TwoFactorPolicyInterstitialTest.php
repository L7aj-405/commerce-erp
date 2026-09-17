<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\Support\PlatformTestCase;

/**
 * §7/§10 — EnsureTwoFactorPolicy's redirect ("Votre organisation exige la
 * double authentification…") is flashed via `session('status')`, but that key
 * was never part of the globally shared Inertia `flash` props (see
 * HandleInertiaRequests), so nothing ever displayed it: a user landed on the
 * Security page with no explanation for why they were sent there. This
 * verifies the Security page prop now actually carries that message, closing
 * the "unexplained redirect loop" the Account Settings V1 spec calls out.
 */
class TwoFactorPolicyInterstitialTest extends PlatformTestCase
{
    public function test_the_security_page_explains_why_the_organization_forced_the_redirect(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $organization->securitySetting()->update(['require_2fa' => true]);

        $this->actingAs($owner)->get(route('platform.index'))->assertRedirect(route('security.edit'));

        $response = $this->actingAs($owner)->withHeader('X-Inertia', 'true')->get(route('security.edit'));
        $response->assertOk();
        $this->assertStringContainsString('double authentification', (string) $response->json('props.status'));
    }

    public function test_the_security_page_carries_no_status_outside_the_redirect(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->withHeader('X-Inertia', 'true')->get(route('security.edit'));

        $response->assertOk();
        $this->assertNull($response->json('props.status'));
    }
}
