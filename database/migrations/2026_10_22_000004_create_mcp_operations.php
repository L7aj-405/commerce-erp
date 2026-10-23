<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP-4 bulk product operations. The operation row is the durable summary and
 * survives retention pruning; item rows hold per-record payloads and are purged
 * after the retention window (see mcp:prune-operations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_operations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id');
            // Nullable so the summary keeps its attribution snapshot if a
            // connection or user row is ever removed.
            $table->ulid('mcp_connection_id')->nullable();
            $table->string('mcp_connection_name', 100);
            $table->foreignId('user_id')->nullable();
            $table->json('initiated_by');
            $table->string('type', 32);
            $table->string('status', 16);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->json('required_scopes');
            $table->char('preview_hash', 64)->nullable();
            $table->timestamp('preview_expires_at')->nullable();
            $table->char('executed_fingerprint_hash', 64)->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('execute_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('details_purged_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'mcp_op_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('mcp_connection_id', 'mcp_op_conn_fk')->references('id')->on('mcp_connections')->nullOnDelete();
            $table->foreign('user_id', 'mcp_op_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->unique(['mcp_connection_id', 'idempotency_key'], 'mcp_op_conn_key_uq');
            $table->index(['organization_id', 'created_at'], 'mcp_op_org_created_idx');
            $table->index(['mcp_connection_id', 'status'], 'mcp_op_conn_status_idx');
            $table->index(['status', 'completed_at'], 'mcp_op_status_completed_idx');
        });

        Schema::create('mcp_operation_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('mcp_operation_id');
            $table->foreignId('organization_id');
            $table->unsignedInteger('ordinal');
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('locator');
            $table->json('input_changes')->nullable();
            $table->json('proposed_values')->nullable();
            $table->char('proposed_hash', 64)->nullable();
            $table->string('expected_version', 40)->nullable();
            $table->char('expected_state_hash', 64)->nullable();
            $table->string('preview_status', 16)->default('pending');
            $table->json('preview_result')->nullable();
            $table->string('status', 16)->default('validating');
            $table->json('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('mcp_operation_id', 'mcp_op_item_op_fk')->references('id')->on('mcp_operations')->cascadeOnDelete();
            $table->unique(['mcp_operation_id', 'ordinal'], 'mcp_op_item_ordinal_uq');
            $table->index(['mcp_operation_id', 'status', 'id'], 'mcp_op_item_status_idx');
            $table->index(['mcp_operation_id', 'preview_status'], 'mcp_op_item_preview_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_operation_items');
        Schema::dropIfExists('mcp_operations');
    }
};
