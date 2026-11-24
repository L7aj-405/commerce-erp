<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance clients Phase 2 hardening.
 *
 *  - payment_allocations.effective_date: the day an allocation took economic effect on its order. NULL means "same day as the
 *    payment" (every legacy row and every allocation made at receipt), so no backfill is needed and legacy positions are unchanged.
 *    The payment's own payment_date (the treasury movement, closed-period anchored) is never touched.
 *  - payment_allocation_releases: history of de-allocated advance allocations (the live row is removed so order balances stay simple;
 *    this row keeps the interval [effective_date, released_date) so historical positions and statements remain reproducible).
 *  - payments.request_fingerprint: hash of the material settlement request, kept independent of later allocation changes (idempotency).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Keep the CHECK triggers that Blueprint's table rebuild would drop (see FinancialCheckConstraints).
            DB::statement('ALTER TABLE payment_allocations ADD COLUMN effective_date date NULL');
            DB::statement('ALTER TABLE payments ADD COLUMN request_fingerprint varchar(64) NULL');
        } else {
            Schema::table('payment_allocations', fn (Blueprint $table) => $table->date('effective_date')->nullable());
            Schema::table('payments', fn (Blueprint $table) => $table->string('request_fingerprint', 64)->nullable());
        }

        Schema::create('payment_allocation_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->date('effective_date');
            $table->date('released_date');
            $table->text('reason');
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'sales_order_id', 'effective_date'], 'pa_rel_order_idx');
            $table->index(['organization_id', 'payment_id'], 'pa_rel_payment_idx');
        });

        // Settlement of an opening balance (historical debt): a real customer payment (advance) applied to the opening line. It creates
        // no sales order, invoice, VAT or second receipt. released_date NULL = still applied; a release keeps the row for history.
        Schema::create('customer_opening_balance_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('opening_balance_id')->constrained('customer_opening_balances')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->date('effective_date');
            $table->date('released_date')->nullable();
            $table->text('release_reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'customer_id', 'effective_date'], 'cobs_cust_eff_idx');
            $table->index(['organization_id', 'payment_id'], 'cobs_payment_idx');
            $table->index(['organization_id', 'opening_balance_id'], 'cobs_opening_idx');
        });

        // Scalability: statement / journal / aging / accounts read these by (organization, customer|order, date).
        Schema::table('payments', fn (Blueprint $table) => $table->index(['organization_id', 'customer_id', 'payment_date'], 'pay_org_cust_date_idx'));
        Schema::table('payment_allocations', fn (Blueprint $table) => $table->index(['organization_id', 'sales_order_id', 'effective_date'], 'pa_org_order_eff_idx'));
    }

    public function down(): void
    {
        Schema::table('payment_allocations', fn (Blueprint $table) => $table->dropIndex('pa_org_order_eff_idx'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex('pay_org_cust_date_idx'));
        Schema::dropIfExists('customer_opening_balance_settlements');
        Schema::dropIfExists('payment_allocation_releases');
        Schema::table('payment_allocations', fn (Blueprint $table) => $table->dropColumn('effective_date'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('request_fingerprint'));
    }
};
