<?php

namespace Tests\Unit\Architecture\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/**
 * Finds floating-point arithmetic in code that computes money.
 *
 * Money is `DECIMAL(19,4)` in the database and a decimal STRING in PHP, computed with App\Support\Decimal
 * (bcmath) and App\Support\Money. A float anywhere in that chain silently loses precision
 * (0.1 + 0.2 !== 0.3), so the core financial code must not contain:
 *
 *  - `(float)` / `(double)` casts, `floatval()`, `doubleval()`;
 *  - arithmetic against a float literal (`$total * 1.2`);
 *  - PHP `round()`, `floor()`, `ceil()`, `fmod()` (they convert to float — use Decimal::round & co);
 *  - `array_sum()` (sums strings as floats).
 *
 * `number_format()` is allowed: it is a display formatter, not arithmetic. The scan is AST based
 * (comments and strings never match) and reports findings as `<file>::<Class::method>::<kind>`.
 */
final class FloatPrecisionScan
{
    private const FLOAT_FUNCTIONS = ['floatval' => 'floatval', 'doubleval' => 'floatval', 'round' => 'round', 'floor' => 'floor', 'ceil' => 'ceil', 'fmod' => 'fmod', 'array_sum' => 'array_sum'];

    private const ARITHMETIC = ['+', '-', '*', '/', '%', '**'];

    /** Repo-relative directories whose every file computes money. */
    private const CORE_DIRECTORIES = [
        'app/Actions/Payments', 'app/Actions/Finance', 'app/Actions/Documents', 'app/Actions/Sales',
        'app/Actions/Returns', 'app/Actions/Exchanges', 'app/Actions/Pos', 'app/Actions/Procurement',
        'app/Actions/Commissions', 'app/Actions/Quotations',
        'app/Services/Finance', 'app/Services/Commissions', 'app/Services/Pos', 'app/Services/Procurement',
        'app/Services/Mcp/Finance', 'app/Services/Mcp/Sales', 'app/Services/Mcp/Procurement',
        'app/Http/Controllers/Finance', 'app/Http/Controllers/Payments', 'app/Http/Controllers/Sales',
        'app/Http/Controllers/Documents', 'app/Http/Controllers/Procurement', 'app/Http/Controllers/Quotations',
    ];

    /** Money-bearing classes that live directly in app/Services or app/Support (matched by file name). */
    private const CORE_FILE_PATTERN = '#^app/(Services|Support)/(Decimal|Money|PriceCalculator|ProductPriceResolver|SalesLineCalculator|SalesOrderTotalsCalculator|SalesOrderPaymentCalculator|SaleLineMarginCalculator|SalesMarginReportService|QuotationLinePricing|QuotationTotals|PosDraftCheckoutCalculator|CustomerFinancialSummary|InventoryQuantity)\.php$#';

    /** @return list<string> repo-relative paths of the core financial files */
    public function coreFiles(): array
    {
        $files = SourceAnalyzer::phpFiles(...self::CORE_DIRECTORIES);
        foreach (SourceAnalyzer::phpFiles('app/Services', 'app/Support') as $path) {
            if (preg_match(self::CORE_FILE_PATTERN, $path)) {
                $files[] = $path;
            }
        }
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @return list<array{key: string, file: string, unit: string, kind: string, line: int}>
     */
    public function findings(array $files): array
    {
        $findings = [];
        foreach ($files as $file) {
            foreach (SourceAnalyzer::units($file) as $unit) {
                foreach ((new NodeFinder)->find($unit['node'], fn (Node $node) => $this->kind($node) !== null) as $node) {
                    $kind = $this->kind($node);
                    $key = $file.'::'.$unit['name'].'::'.$kind;
                    $findings[$key] ??= ['key' => $key, 'file' => $file, 'unit' => $unit['name'], 'kind' => $kind, 'line' => $node->getStartLine()];
                }
            }
        }

        return array_values($findings);
    }

    private function kind(Node $node): ?string
    {
        if ($node instanceof Expr\Cast\Double) {
            return 'float-cast';
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            return self::FLOAT_FUNCTIONS[strtolower($node->name->toString())] ?? null;
        }
        if ($node instanceof Expr\BinaryOp && in_array($node->getOperatorSigil(), self::ARITHMETIC, true)
            && ($node->left instanceof Node\Scalar\Float_ || $node->right instanceof Node\Scalar\Float_)) {
            return 'float-literal-arithmetic';
        }

        return null;
    }
}
