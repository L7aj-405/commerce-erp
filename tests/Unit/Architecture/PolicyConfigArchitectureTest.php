<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Phase 1 Step 11: the two payment-term rules migrated to PolicyResolver must not be read from config anywhere
 * else in app/. The resolver is the only allowed reader (it owns the environment fallback). Historical
 * migrations are out of scope (they ran against a frozen config); config/ declares the keys.
 */
final class PolicyConfigArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../';

    private const RESOLVER = 'app/Services/Policies/PolicyResolver.php';

    private const MIGRATED = ['payments.sales_order_payment_term_days', 'documents.invoice.payment_term_days'];

    public function test_migrated_rules_are_only_read_through_the_resolver(): void
    {
        $offenders = [];
        $base = realpath(self::ROOT.'app');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = 'app/'.str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            if ($relative === self::RESOLVER) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (self::MIGRATED as $key) {
                if (preg_match('/config\(\s*[\'"]'.preg_quote($key, '/').'[\'"]/', $source) || preg_match('/Config::get\(\s*[\'"]'.preg_quote($key, '/').'[\'"]/', $source)) {
                    $offenders[] = "{$relative} reads {$key}";
                }
            }
        }

        $this->assertSame([], $offenders, "Read these through App\\Services\\Policies\\PolicyResolver:\n - ".implode("\n - ", $offenders));
    }

    public function test_the_resolver_is_the_reader_of_both_environment_values(): void
    {
        $source = (string) file_get_contents(self::ROOT.self::RESOLVER);
        foreach (self::MIGRATED as $key) {
            $this->assertStringContainsString("config('{$key}'", $source);
        }
    }

    public function test_the_two_gated_actions_use_the_resolver(): void
    {
        foreach (['app/Actions/Sales/ConfirmSalesOrderAction.php' => 'salesOrderPaymentTermDays', 'app/Actions/Documents/IssueInvoiceAction.php' => 'invoicePaymentTermDays'] as $file => $method) {
            $this->assertStringContainsString("policies->{$method}(", (string) file_get_contents(self::ROOT.$file));
        }
    }
}
