<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sandboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('active');
            $table->unsignedInteger('generation')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->timestamp('reset_at')->nullable();
            // V1: one shared sandbox. Lift only when multiple sandboxes are supported.
            $table->unique('organization_id', 'sandboxes_org_unique');
        });
        app(App\Services\PermissionProvisioner::class)->provisionAllOrganizations();
    }
    public function down(): void { Schema::dropIfExists('sandboxes'); }
};
