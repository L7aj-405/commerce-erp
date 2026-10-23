<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id');
            $table->foreignId('user_id');
            $table->string('name', 100);
            $table->string('status', 16)->default('active');
            $table->char('token_hash', 64);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'mcp_conn_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id', 'mcp_conn_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['organization_id', 'status'], 'mcp_conn_org_status_idx');
            $table->index(['user_id', 'status'], 'mcp_conn_user_status_idx');
            $table->unique('token_hash', 'mcp_conn_token_hash_uq');
        });

        Schema::create('mcp_connection_scopes', function (Blueprint $table) {
            $table->id();
            $table->ulid('mcp_connection_id');
            $table->string('scope', 80);
            $table->timestamps();

            $table->foreign('mcp_connection_id', 'mcp_scope_conn_fk')->references('id')->on('mcp_connections')->cascadeOnDelete();
            $table->unique(['mcp_connection_id', 'scope'], 'mcp_scope_conn_scope_uq');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_channel', 16)->default('web')->after('actor_id');
            $table->ulid('mcp_connection_id')->nullable()->after('actor_channel');
            $table->string('mcp_connection_name', 100)->nullable()->after('mcp_connection_id');
            $table->index(['organization_id', 'actor_channel', 'created_at'], 'audit_org_channel_created_idx');
            $table->index(['mcp_connection_id', 'created_at'], 'audit_mcp_conn_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_org_channel_created_idx');
            $table->dropIndex('audit_mcp_conn_created_idx');
            $table->dropColumn(['actor_channel', 'mcp_connection_id', 'mcp_connection_name']);
        });

        Schema::dropIfExists('mcp_connection_scopes');
        Schema::dropIfExists('mcp_connections');
    }
};
