<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Obligations generated from supplier invoices are guarded at model level (FinanceObligation::booted), but Eloquent
 * events do not fire for mass updates / DB::table writes. The residual-risk control is this allow-list: only the
 * listed classes may create or bulk-write `finance_obligations` rows. A new writer must be reviewed against the
 * supplier invoice guard (SupplierInvoiceObligationScope) and added here deliberately.
 */
final class FinanceObligationWritersTest extends TestCase
{
    private const ALLOWED = [
        'app/Actions/Finance/ManageFinanceObligationAction.php',
        'app/Actions/Finance/GenerateRecurringFinanceObligationsAction.php',
        'app/Actions/Finance/ManageFinanceSupplierInstallmentPlanAction.php',
        'app/Actions/SupplierInvoices/ApproveSupplierInvoiceAction.php',
        'app/Actions/SupplierInvoices/VoidSupplierInvoiceAction.php',
        // Raw DB::table restore (bypasses Eloquent by design). Data reset is registry-driven (table names, no model).
        'app/Services/OrganizationBackups/OrganizationBackupRestorer.php',
    ];

    private const WRITE_PATTERNS = [
        '/new\s+FinanceObligation\b/',
        '/FinanceObligation::(create|insert|upsert|forceCreate|firstOrCreate|updateOrCreate|destroy)\b/',
        '/FinanceObligation::[^;]*?->(update|delete|insert|upsert|increment|decrement|forceDelete)\s*\(/s',
        '/DB::table\(\s*[\'"]finance_obligations[\'"]\s*\)[^;]*?->(update|delete|insert|insertOrIgnore|upsert|truncate|increment|decrement)\s*\(/s',
        '/->obligations\(\)\s*->\s*(create|update|delete|save)/',
    ];

    public function test_only_allow_listed_classes_write_finance_obligations(): void
    {
        $root = dirname(__DIR__, 3);
        $violations = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            $source = (string) file_get_contents($file->getPathname());
            foreach (self::WRITE_PATTERNS as $pattern) {
                if (preg_match($pattern, $source) === 1 && ! in_array($relative, self::ALLOWED, true)) {
                    $violations[$relative] = $pattern;
                }
            }
        }

        $this->assertSame([], $violations, 'Unreviewed writer of finance_obligations: bypasses the supplier invoice obligation guard.');
    }

    public function test_the_allow_list_has_no_stale_existing_entries(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (self::ALLOWED as $path) {
            if (str_contains($path, 'SupplierInvoices/')) {
                continue; // created by later PRs (A2)
            }
            $this->assertFileExists($root.'/'.$path);
        }
    }
}
