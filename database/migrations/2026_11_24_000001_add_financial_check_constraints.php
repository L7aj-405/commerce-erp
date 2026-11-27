<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 Step 7 (F-05 / S-06): minimal database-level CHECK constraints on
 * financial amounts and stock quantities, so a future bug or a direct script
 * cannot persist a negative collection, allocation, refund, document total or
 * stock balance.
 *
 *   payments / payment_allocations / payment_refunds  amount > 0
 *   invoices / credit_notes                           total_incl_tax >= 0
 *   inventory_balances                                on_hand >= 0, reserved >= 0
 *
 * The Test Mode twins (sandbox_payments, sandbox_payment_allocations,
 * sandbox_payment_refunds, sandbox_inventory_balances) carry the same rules,
 * because the same actions write to them. invoices / credit_notes already hold
 * both worlds in one table (sandbox_id).
 *
 * SAFETY: the migration never modifies data. It first scans every target for
 * violating rows and, if any exist, throws BEFORE adding any constraint
 * (nothing is half-applied; fix or review the listed rows, then re-run).
 *
 * MySQL 8.0.16+/8.4: native `CHECK` constraints (enforced). On large tables
 * ALTER TABLE ... ADD CONSTRAINT rebuilds the table, take it in a quiet window.
 * SQLite (test suite): no ALTER ADD CONSTRAINT, so equivalent
 * BEFORE INSERT / BEFORE UPDATE triggers raise ABORT.
 */
return new class extends Migration
{
    /** @var array<int, array{table: string, name: string, column: string, op: string}> */
    private const RULES = [
        ['table' => 'payments', 'name' => 'chk_payments_amount_positive', 'column' => 'amount', 'op' => '>'],
        ['table' => 'payment_allocations', 'name' => 'chk_pay_alloc_amount_positive', 'column' => 'amount', 'op' => '>'],
        ['table' => 'payment_refunds', 'name' => 'chk_pay_refund_amount_positive', 'column' => 'amount', 'op' => '>'],
        ['table' => 'invoices', 'name' => 'chk_invoices_total_nonneg', 'column' => 'total_incl_tax', 'op' => '>='],
        ['table' => 'credit_notes', 'name' => 'chk_credit_notes_total_nonneg', 'column' => 'total_incl_tax', 'op' => '>='],
        ['table' => 'inventory_balances', 'name' => 'chk_inv_bal_on_hand_nonneg', 'column' => 'on_hand', 'op' => '>='],
        ['table' => 'inventory_balances', 'name' => 'chk_inv_bal_reserved_nonneg', 'column' => 'reserved', 'op' => '>='],
        ['table' => 'sandbox_payments', 'name' => 'chk_sbx_payments_amount_positive', 'column' => 'amount', 'op' => '>'],
        ['table' => 'sandbox_payment_allocations', 'name' => 'chk_sbx_pay_alloc_amount_positive', 'column' => 'amount', 'op' => '>'],
        ['table' => 'sandbox_payment_refunds', 'name' => 'chk_sbx_pay_refund_amount_positive', 'column' => 'amount', 'op' => '>'],
        ['table' => 'sandbox_inventory_balances', 'name' => 'chk_sbx_inv_bal_on_hand_nonneg', 'column' => 'on_hand', 'op' => '>='],
        ['table' => 'sandbox_inventory_balances', 'name' => 'chk_sbx_inv_bal_reserved_nonneg', 'column' => 'reserved', 'op' => '>='],
    ];

    public function up(): void
    {
        $this->assertNoViolations();

        foreach (self::RULES as $rule) {
            $this->add($rule);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::RULES) as $rule) {
            $this->drop($rule);
        }
    }

    private function assertNoViolations(): void
    {
        $found = [];

        foreach (self::RULES as $rule) {
            $count = DB::table($rule['table'])
                ->whereRaw("not ({$rule['column']} {$rule['op']} 0)")
                ->count();

            if ($count > 0) {
                $found[] = "{$rule['table']}.{$rule['column']} (must be {$rule['op']} 0): {$count} row(s)";
            }
        }

        if ($found !== []) {
            throw new RuntimeException(
                "Refusing to add financial CHECK constraints; existing rows would violate them and nothing was changed:\n - ".implode("\n - ", $found)
                ."\nReview and correct these rows through the audited business workflow, then re-run the migration.",
            );
        }
    }

    /** @param array{table: string, name: string, column: string, op: string} $rule */
    private function add(array $rule): void
    {
        ['table' => $table, 'name' => $name, 'column' => $column, 'op' => $op] = $rule;

        if ($this->isSqlite()) {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
                DB::statement(
                    "CREATE TRIGGER {$name}_{$suffix} BEFORE {$event} ON {$table} "
                    ."WHEN NEW.{$column} IS NOT NULL AND NOT (NEW.{$column} {$op} 0) "
                    ."BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed: {$name}'); END",
                );
            }

            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} {$op} 0)");
    }

    /** @param array{table: string, name: string, column: string, op: string} $rule */
    private function drop(array $rule): void
    {
        ['table' => $table, 'name' => $name] = $rule;

        if ($this->isSqlite()) {
            DB::statement("DROP TRIGGER IF EXISTS {$name}_insert");
            DB::statement("DROP TRIGGER IF EXISTS {$name}_update");

            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
    }

    private function isSqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }
};
