<?php

namespace Tests\Unit\SupplierInvoices;

use App\Services\SupplierInvoices\SupplierInvoiceSettings;
use Tests\TestCase;

class SupplierInvoiceSettingsTest extends TestCase
{
    public function test_defaults_come_from_config(): void
    {
        $settings = new SupplierInvoiceSettings;

        $this->assertFalse($settings->enabled());
        $this->assertSame('0.0500', $settings->roundingTolerance());
        $this->assertSame(2, $settings->moneyScale());
        $this->assertSame(7, $settings->duplicateWindowDays());
        $this->assertFalse($settings->requireDistinctApprover());
        $this->assertFalse($settings->requireSupplierIceOnApproval());
        $this->assertNull($settings->paymentTermWarningDays());
        $this->assertNull($settings->voidMaxAgeDays());
    }

    public function test_reads_runtime_config(): void
    {
        config(['supplier_invoices.enabled' => true, 'supplier_invoices.rounding_tolerance' => '0.10', 'supplier_invoices.void_max_age_days' => '30']);
        $settings = new SupplierInvoiceSettings;

        $this->assertTrue($settings->enabled());
        $this->assertSame('0.1000', $settings->roundingTolerance());
        $this->assertSame(30, $settings->voidMaxAgeDays());
    }

    public function test_explicit_values_and_fallbacks(): void
    {
        $settings = new SupplierInvoiceSettings(['require_distinct_approver' => true, 'duplicate_window_days' => 3, 'payment_term_warning_days' => 90, 'money_scale' => 9]);

        $this->assertTrue($settings->requireDistinctApprover());
        $this->assertSame(3, $settings->duplicateWindowDays());
        $this->assertSame(90, $settings->paymentTermWarningDays());
        $this->assertSame(4, $settings->moneyScale());
        $this->assertSame('0.0500', $settings->roundingTolerance());
        $this->assertFalse($settings->enabled());
    }
}
