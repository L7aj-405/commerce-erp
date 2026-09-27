<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicLegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_homepage_is_accessible_logged_out_and_uses_canonical_branding(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('productName', '10xScale ERP')
                ->where('legalLinks.privacy', route('privacy'))
                ->where('legalLinks.terms', route('terms'))
            );
    }

    public function test_privacy_page_is_public_and_renders_expected_title(): void
    {
        config([
            'services.google_auth.client_secret' => 'google-auth-secret-value',
            'services.google_drive.client_secret' => 'google-drive-secret-value',
        ]);

        $response = $this->get('/privacy');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Legal/Privacy')
                ->where('pageTitle', 'Politique de confidentialité')
                ->where('legal.productName', '10xScale ERP')
                ->where('reviewDisclosures.product', '10xScale ERP')
                ->where('reviewDisclosures.googleAuth', 'Connexion avec Google')
                ->where('reviewDisclosures.googleAuthScopes', 'openid email profile')
                ->where('reviewDisclosures.googleDrive', 'Sauvegardes Google Drive')
                ->where('reviewDisclosures.googleDriveScope', 'https://www.googleapis.com/auth/drive.file')
                ->where('reviewDisclosures.googleDataUse', 'Utilisation des données Google')
                ->where('reviewDisclosures.retention', 'Conservation des données')
                ->where('reviewDisclosures.revocation', 'Révocation de l’accès Google')
                ->where('legalLinks.privacy', route('privacy'))
                ->where('legalLinks.terms', route('terms'))
            );

        $response->assertDontSee('google-auth-secret-value');
        $response->assertDontSee('google-drive-secret-value');
    }

    public function test_terms_page_is_public_and_renders_expected_title(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Legal/Terms')
                ->where('pageTitle', 'Conditions d’utilisation')
                ->where('legal.productName', '10xScale ERP')
                ->where('legalLinks.privacy', route('privacy'))
                ->where('legalLinks.terms', route('terms'))
            );
    }

    public function test_legal_pages_do_not_require_active_organization_context(): void
    {
        $user = User::factory()->create([
            'active_organization_id' => null,
            'active_store_id' => null,
        ]);

        $this->actingAs($user)->get('/privacy')->assertOk();
        $this->actingAs($user)->get('/terms')->assertOk();
    }

    public function test_legal_pages_do_not_render_tenant_specific_private_information(): void
    {
        $organization = new Organization;
        $organization->name = 'Private Tenant Name';
        $organization->slug = 'private-tenant-name';
        $organization->save();

        $this->get('/privacy')
            ->assertOk()
            ->assertDontSee('Private Tenant Name')
            ->assertDontSee('private-tenant-name');
    }

    public function test_auth_pages_expose_privacy_and_terms_links(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('legalLinks.privacy', route('privacy'))
                ->where('legalLinks.terms', route('terms'))
            );

        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Register')
                ->where('legalLinks.privacy', route('privacy'))
                ->where('legalLinks.terms', route('terms'))
            );
    }

    public function test_homepage_exposes_same_privacy_url_submitted_to_google(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('legalLinks.privacy', route('privacy'))
            );
    }
}
