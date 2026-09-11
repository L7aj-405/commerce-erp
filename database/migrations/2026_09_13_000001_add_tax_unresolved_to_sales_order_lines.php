<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit "unresolved tax" marker for a Draft SalesOrder catalogue line.
 *
 * `sales_order_lines.tax_rate` is NOT NULL (default 0), so a Product with no
 * resolvable tax configuration could previously only be stored as `tax_rate = 0`
 * — indistinguishable from a genuinely configured 0% TaxRate. This flag keeps
 * the two apart:
 *
 *   - tax_unresolved = false, tax_rate = 0  → a real 0% (exonéré) tax
 *   - tax_unresolved = true,  tax_rate = 0  → tax not yet chosen; provisional
 *
 * A line may be added to a Draft while unresolved (so the employee is never
 * blocked mid-entry), but ConfirmSalesOrderAction refuses to confirm an Order
 * that still carries one. Resolving it (choosing a TaxRate on the line) clears
 * the flag and recomputes the line with that rate — never a hardcoded 20%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->boolean('tax_unresolved')->default(false)->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('tax_unresolved');
        });
    }
};
