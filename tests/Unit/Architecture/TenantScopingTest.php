<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Architecture\Support\SourceAnalyzer;
use Tests\Unit\Architecture\Support\TenantScopeScan;

/**
 * Tenant-scoping architecture test (Phase 1 / Step 1, Task B; finding S-01).
 *
 * The application deliberately has NO global Eloquent scope: organization isolation is explicit query
 * scoping plus composite (organization_id, id) foreign keys. This static (AST) test makes the convention
 * enforceable: every query that starts on a tenant-owned model or table must name `organization_id` in its
 * call chain (or be a reviewed, documented exception). It never touches a database. See TenantScopeScan for
 * exactly what counts as a query root and as a scoping signal.
 */
final class TenantScopingTest extends TestCase
{
    /** Application code that can run tenant queries (models themselves hold relations, not queries). */
    private const SCANNED = [
        'app/Actions', 'app/Services', 'app/Http', 'app/Jobs', 'app/Console', 'app/Policies',
        'app/Providers', 'app/Support', 'app/Rules', 'app/Mail', 'app/Notifications', 'app/Contracts',
    ];

    /**
     * Ratchets for the OPEN findings in tenant-scope-exceptions.php. Both may only go DOWN — when you fix or
     * re-classify entries, lower the number in the same change; never raise it.
     *
     *  - UNREVIEWED_CEILING: findings whose callers/origin were not traced yet ("UNREVIEWED BASELINE").
     *    It started at 65 and dropped to 3 after the Step 1 final security review.
     *  - NEEDS_STRENGTHENING_CEILING: reviewed, not exploitable today, but a bare-key / unscoped query that should
     *    gain an organization_id filter ("NEEDS STRENGTHENING"). Together with the unreviewed ones they account for
     *    every one of the 65 original findings that was not proven safe: 3 + 14 = 17.
     */
    private const UNREVIEWED_CEILING = 3;

    private const NEEDS_STRENGTHENING_CEILING = 14;

    private TenantScopeScan $scan;

    protected function setUp(): void
    {
        $this->scan = new TenantScopeScan;
    }

    public function test_tenant_owned_models_are_discovered_and_platform_models_are_not(): void
    {
        $models = array_keys($this->scan->tenantModels());

        foreach (['Payment', 'PaymentAllocation', 'Invoice', 'CreditNote', 'SalesOrder', 'InventoryBalance', 'Customer', 'PurchaseOrder', 'FinancialAccount'] as $name) {
            $this->assertContains('App\\Models\\'.$name, $models, "{$name} must be recognised as tenant-owned.");
        }
        foreach (['User', 'Permission', 'Organization'] as $name) {
            $this->assertNotContains('App\\Models\\'.$name, $models, "{$name} is platform-level, not tenant-owned.");
        }
        $this->assertGreaterThanOrEqual(12, count($models));
    }

    public function test_the_detector_reports_unscoped_queries_and_accepts_scoped_ones(): void
    {
        $violations = $this->scan->violations(['tests/Unit/Architecture/Fixtures/TenantScopeFixtures.php']);
        $reported = array_map(fn (array $v) => substr($v['unit'], strrpos($v['unit'], '::') + 2), $violations);

        foreach ([
            'unsafeByKey', 'unsafeStaticFind', 'unsafeFilteredOnlyByStatus', 'unsafeRawTable', 'unsafeRawTableAlias', 'unsafeBuilderVariableNeverScoped',
        ] as $mustBeFlagged) {
            $this->assertContains($mustBeFlagged, $reported, "The detector missed {$mustBeFlagged}.");
        }
        foreach ($reported as $method) {
            $this->assertStringStartsWith('unsafe', $method, "False positive on safe fixture {$method}.");
        }
    }

    public function test_no_unreviewed_unscoped_tenant_queries_exist(): void
    {
        $exceptions = $this->exceptions();
        $new = array_values(array_filter(
            $this->scan->violations(SourceAnalyzer::phpFiles(...self::SCANNED)),
            fn (array $v) => ! isset($exceptions[$v['key']]),
        ));

        $this->assertSame([], array_map(fn (array $v) => "{$v['file']}:{$v['line']} {$v['unit']} → {$v['model']}", $new),
            'A query on tenant-owned data does not name organization_id. Add ->where(\'organization_id\', …) (preferred), or, only if no other '
            .'organization\'s row can be reached, add a reasoned entry to tests/Unit/Architecture/tenant-scope-exceptions.php.');
    }

    public function test_exception_list_is_documented_and_not_stale(): void
    {
        $exceptions = $this->exceptions();
        $current = array_column($this->scan->violations(SourceAnalyzer::phpFiles(...self::SCANNED)), 'key');

        foreach ($exceptions as $key => $reason) {
            $this->assertGreaterThanOrEqual(60, strlen((string) $reason), "Exception {$key} needs a real written reason.");
            $this->assertContains($key, $current, "Stale exception (the query was fixed or removed) — delete it: {$key}");
        }
    }

    public function test_open_findings_can_only_shrink(): void
    {
        $count = fn (string $prefix) => count(array_filter($this->exceptions(), fn (string $reason) => str_starts_with($reason, $prefix)));

        $this->assertLessThanOrEqual(self::UNREVIEWED_CEILING, $count('UNREVIEWED BASELINE'),
            'The unreviewed baseline grew. Scope the new query with organization_id instead of grandfathering it.');
        $this->assertLessThanOrEqual(self::NEEDS_STRENGTHENING_CEILING, $count('NEEDS STRENGTHENING'),
            'The needs-strengthening list grew. Scope the new query with organization_id instead of listing it.');
    }

    public function test_every_exception_has_a_known_classification(): void
    {
        foreach ($this->exceptions() as $key => $reason) {
            $this->assertMatchesRegularExpression('/^(REVIEWED|NEEDS STRENGTHENING|UNREVIEWED BASELINE)/', (string) $reason,
                "Exception {$key} must start with REVIEWED, NEEDS STRENGTHENING or UNREVIEWED BASELINE.");
        }
    }

    /** @return array<string, string> */
    private function exceptions(): array
    {
        return require __DIR__.'/tenant-scope-exceptions.php';
    }
}
