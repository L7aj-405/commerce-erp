<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP-6 — OAuth 2.1 for remote MCP clients. Purely additive: existing
 * connections keep auth_mode "token" and their opaque bearer secrets keep
 * working. Only hashes of codes/tokens/client secrets are persisted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_clients', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // public client_id
            $table->string('name', 150);
            $table->json('redirect_uris');
            $table->string('token_endpoint_auth_method', 32)->default('none');
            $table->char('secret_hash', 64)->nullable();
            $table->string('client_uri', 2000)->nullable();
            $table->string('software_id', 255)->nullable();
            $table->string('software_version', 100)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('mcp_connections', function (Blueprint $table) {
            $table->string('auth_mode', 16)->default('token')->after('status');
            $table->string('oauth_client_id', 64)->nullable()->after('auth_mode');
            $table->index(['user_id', 'oauth_client_id'], 'mcp_conn_user_oauth_client_idx');
        });

        Schema::create('mcp_oauth_authorization_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('code_hash', 64);
            $table->string('client_id', 64);
            $table->ulid('mcp_connection_id');
            $table->string('redirect_uri', 2000);
            $table->string('code_challenge', 128);
            $table->string('resource', 2000)->nullable();
            $table->json('scopes');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique('code_hash', 'mcp_oauth_code_hash_uq');
            $table->foreign('client_id', 'mcp_oauth_code_client_fk')->references('id')->on('mcp_oauth_clients')->cascadeOnDelete();
            $table->foreign('mcp_connection_id', 'mcp_oauth_code_conn_fk')->references('id')->on('mcp_connections')->cascadeOnDelete();
        });

        Schema::create('mcp_oauth_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('family_id');
            $table->string('client_id', 64);
            $table->ulid('mcp_connection_id');
            $table->ulid('authorization_code_id')->nullable();
            $table->char('access_token_hash', 64);
            $table->timestamp('access_expires_at');
            $table->char('refresh_token_hash', 64)->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->json('scopes');
            $table->string('resource', 2000)->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('access_token_hash', 'mcp_oauth_access_hash_uq');
            $table->unique('refresh_token_hash', 'mcp_oauth_refresh_hash_uq');
            $table->index('family_id', 'mcp_oauth_token_family_idx');
            $table->index(['mcp_connection_id', 'revoked_at'], 'mcp_oauth_token_conn_idx');
            $table->index('authorization_code_id', 'mcp_oauth_token_code_idx');
            $table->foreign('client_id', 'mcp_oauth_token_client_fk')->references('id')->on('mcp_oauth_clients')->cascadeOnDelete();
            $table->foreign('mcp_connection_id', 'mcp_oauth_token_conn_fk')->references('id')->on('mcp_connections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_tokens');
        Schema::dropIfExists('mcp_oauth_authorization_codes');
        Schema::table('mcp_connections', function (Blueprint $table) {
            $table->dropIndex('mcp_conn_user_oauth_client_idx');
            $table->dropColumn(['auth_mode', 'oauth_client_id']);
        });
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
