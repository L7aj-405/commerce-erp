<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance clients Phase 2 — additive customer-finance tables. No existing financial record is rewritten.
 *
 *  - customer_opening_balances        controlled historical debt/credit (NOT a sale, invoice, VAT or bank receipt);
 *                                     corrected by a reversal, never deleted.
 *  - customer_financial_terms         per-customer credit conditions (credit stays OFF until an organization enables it).
 *  - customer_credit_exceptions       audited one-order credit overrides.
 *  - customer_payment_installments    payment schedule of one issued invoice (must reconcile to its net obligation).
 *  - customer_payment_promises        customer payment promises (a follow-up aid, never a financial fact).
 *  - customer_payment_instruments     incoming cheques / commercial effects + customer_instrument_events (status history).
 *  - payments.customer_id             set ONLY on unallocated customer advances (a Payment with no allocation yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('effective_date');
            // Signed: positive = the customer owes the organization, negative = the organization owes the customer.
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->string('reference', 120);
            $table->text('reason');
            $table->string('status', 16)->default('posted');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'customer_id', 'status', 'effective_date'], 'cust_open_bal_idx');
        });

        Schema::create('customer_financial_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->boolean('credit_enabled')->default(false);
            $table->decimal('credit_limit', 19, 4)->nullable();
            $table->unsignedSmallInteger('payment_term_days')->nullable();
            $table->decimal('min_deposit_percent', 5, 2)->nullable();
            $table->unsignedSmallInteger('overdue_tolerance_days')->default(0);
            $table->string('policy', 16)->default('warn');
            $table->string('financial_status', 16)->default('normal');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'customer_id'], 'cust_terms_org_cust_uq');
        });

        Schema::create('customer_credit_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('authorized_amount', 19, 4);
            $table->text('reason');
            $table->foreignId('authorized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('expires_on')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'customer_id', 'consumed_at'], 'cust_credit_exc_idx');
        });

        Schema::create('customer_payment_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'invoice_id'], 'cust_inst_invoice_idx');
            $table->index(['organization_id', 'customer_id', 'due_date'], 'cust_inst_due_idx');
        });

        Schema::create('customer_payment_promises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->date('promised_date');
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->string('status', 16)->default('open');
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'customer_id', 'status', 'promised_date'], 'cust_promise_idx');
        });

        Schema::create('customer_payment_instruments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->string('instrument_number', 80);
            $table->string('customer_reference', 120)->nullable();
            $table->string('issuing_bank', 120)->nullable();
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->date('received_date');
            $table->date('expected_collection_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->string('state', 16)->default('received');
            $table->foreignId('cleared_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->uuid('idempotency_key');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key'], 'cust_instr_idem_uq');
            $table->unique(['organization_id', 'type', 'instrument_number', 'issuing_bank'], 'cust_instr_number_uq');
            $table->index(['organization_id', 'state', 'maturity_date'], 'cust_instr_state_idx');
            $table->index(['organization_id', 'customer_id'], 'cust_instr_customer_idx');
        });

        Schema::create('customer_instrument_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained('customer_payment_instruments')->cascadeOnDelete();
            $table->string('from_state', 16)->nullable();
            $table->string('to_state', 16);
            $table->date('event_date');
            $table->text('note')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['organization_id', 'instrument_id'], 'cust_instr_evt_idx');
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite: a Blueprint ALTER rebuilds the table and would DROP the amount CHECK triggers added by
            // 2026_11_24_000001_add_financial_check_constraints. A plain ADD COLUMN keeps them.
            DB::statement('ALTER TABLE payments ADD COLUMN customer_id integer null references customers (id) on delete set null');
            DB::statement('CREATE INDEX payment_org_customer_idx ON payments (organization_id, customer_id)');
        } else {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('customer_id')->nullable()->after('financial_account_id')->constrained('customers')->nullOnDelete();
                $table->index(['organization_id', 'customer_id'], 'payment_org_customer_idx');
            });
        }

        app(PermissionProvisioner::class)->provisionAllOrganizations();
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex('payment_org_customer_idx');
            $table->dropColumn('customer_id');
        });
        foreach (['customer_instrument_events', 'customer_payment_instruments', 'customer_payment_promises', 'customer_payment_installments',
            'customer_credit_exceptions', 'customer_financial_terms', 'customer_opening_balances'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
