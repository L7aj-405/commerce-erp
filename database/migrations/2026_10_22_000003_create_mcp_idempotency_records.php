<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_idempotency_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id');
            $table->ulid('mcp_connection_id');
            $table->string('tool', 64);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('target_type', 32)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->json('response');
            $table->timestamps();

            $table->foreign('organization_id', 'mcp_idem_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('mcp_connection_id', 'mcp_idem_conn_fk')->references('id')->on('mcp_connections')->cascadeOnDelete();
            $table->unique(['mcp_connection_id', 'tool', 'idempotency_key'], 'mcp_idem_conn_tool_key_uq');
            $table->index(['organization_id', 'created_at'], 'mcp_idem_org_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_idempotency_records');
    }
};
