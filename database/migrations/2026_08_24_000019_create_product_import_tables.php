<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('original_file_name');
            $table->string('source_format', 16);
            $table->string('status', 24)->default('uploaded');
            $table->json('headers');
            $table->json('mapping')->nullable();
            $table->json('defaults')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->boolean('stock_detected')->default(false);
            $table->dateTime('expires_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'product_import_org_id_unique');
            $table->index(['organization_id', 'created_at'], 'product_import_org_created_idx');
            $table->index(['status', 'expires_at'], 'product_import_expiry_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('product_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('product_import_id');
            $table->unsignedInteger('row_number');
            $table->json('raw_data');
            $table->json('normalized_data')->nullable();
            $table->string('status', 24)->default('uploaded');
            $table->json('messages')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamps();
            $table->unique(['product_import_id', 'row_number'], 'product_import_row_number_unique');
            $table->index(['organization_id', 'product_import_id', 'status'], 'product_import_row_status_idx');
            $table->foreign(['organization_id', 'product_import_id'], 'product_import_row_import_fk')
                ->references(['organization_id', 'id'])->on('product_imports')->cascadeOnDelete();
            $table->foreign(['organization_id', 'product_id'], 'product_import_row_product_fk')
                ->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
        });

        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['key' => 'products.import'],
            ['name' => 'Import products', 'created_at' => $now, 'updated_at' => $now],
        );
        $permissionId = DB::table('permissions')->where('key', 'products.import')->value('id');
        DB::table('roles')->whereIn('slug', ['owner', 'admin'])->orderBy('id')->each(function ($role) use ($permissionId) {
            DB::table('role_permission')->insertOrIgnore([
                'organization_id' => $role->organization_id,
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_import_rows');
        Schema::dropIfExists('product_imports');
        // Permission records are retained to avoid revoking custom role assignments.
    }
};
