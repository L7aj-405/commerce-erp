<?php

namespace Tests\Feature\Finance;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

/**
 * The Supplier Invoice kill switch is OFF by default. The shared Inertia `features.supplier_invoices` prop is what
 * lets the navigation hide the menu entry while the routes answer 404, so a user who holds supplier_invoices.view
 * never sees a link that leads to a "not found" page.
 */
class SupplierInvoiceFeatureFlagTest extends PlatformTestCase
{
    public function test_the_feature_is_off_by_default_in_the_shipped_configuration(): void
    {
        $this->assertFalse(
            (bool) config('supplier_invoices.enabled'),
            'SUPPLIER_INVOICES_ENABLED must default to false: the feature ships disabled.',
        );
    }

    public function test_shared_prop_is_false_when_the_kill_switch_is_off(): void
    {
        config(['supplier_invoices.enabled' => false]);

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->where('features.supplier_invoices', false));
    }

    public function test_shared_prop_is_true_only_when_the_kill_switch_is_on(): void
    {
        config(['supplier_invoices.enabled' => true]);

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->where('features.supplier_invoices', true));
    }

    public function test_existing_shared_features_are_unchanged(): void
    {
        config(['auth.public_registration' => false, 'supplier_invoices.enabled' => false]);

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('features.public_registration', false)
            ->where('features.supplier_invoices', false));
    }
}
