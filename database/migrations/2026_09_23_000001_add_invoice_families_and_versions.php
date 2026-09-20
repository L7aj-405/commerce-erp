<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_families', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('canonical_invoice_number', 64)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'invoice_family_org_id_unique');
            $table->unique(['organization_id', 'canonical_invoice_number'], 'invoice_family_org_number_unique');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_family_id')->nullable()->after('organization_id');
            $table->unsignedInteger('version')->nullable()->after('invoice_number');
        });

        DB::table('invoices')->orderBy('id')->chunkById(200, function ($invoices) {
            foreach ($invoices as $invoice) {
                $familyId = DB::table('invoice_families')->insertGetId([
                    'organization_id' => $invoice->organization_id,
                    'canonical_invoice_number' => $invoice->invoice_number,
                    'created_at' => $invoice->created_at,
                    'updated_at' => $invoice->updated_at,
                ]);

                DB::table('invoices')->where('id', $invoice->id)->update([
                    'invoice_family_id' => $familyId,
                    'version' => 1,
                ]);
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_family_id')->nullable(false)->change();
            $table->unsignedInteger('version')->nullable(false)->change();
            $table->dropUnique('invoice_org_number_unique');
            $table->index(['organization_id', 'invoice_number'], 'invoice_org_number_idx');
            $table->unique(['organization_id', 'invoice_family_id', 'version'], 'invoice_family_version_unique');
            $table->foreign(['organization_id', 'invoice_family_id'], 'invoice_family_fk')
                ->references(['organization_id', 'id'])->on('invoice_families')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $duplicate = DB::table('invoices')
            ->whereNotNull('invoice_number')
            ->select('organization_id', 'invoice_number')
            ->groupBy('organization_id', 'invoice_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicate) {
            throw new LogicException('Invoice version data cannot be rolled back without destructively removing immutable versions.');
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign('invoice_family_fk');
            $table->dropUnique('invoice_family_version_unique');
            $table->dropIndex('invoice_org_number_idx');
            $table->dropColumn(['invoice_family_id', 'version']);
            $table->unique(['organization_id', 'invoice_number'], 'invoice_org_number_unique');
        });

        Schema::dropIfExists('invoice_families');
    }
};
