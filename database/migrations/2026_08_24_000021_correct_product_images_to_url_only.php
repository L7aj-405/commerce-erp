<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'image_url')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('image_url', 2048)->nullable()->after('description');
            });
        }

        if (Schema::hasTable('product_media')) {
            DB::table('product_media')
                ->where('kind', 'image')
                ->where('position', 0)
                ->whereNotNull('source_url')
                ->chunkById(500, function ($records) {
                    foreach ($records as $record) {
                        $url = trim((string) $record->source_url);
                        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                        if (mb_strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
                            continue;
                        }
                        DB::table('products')
                            ->where('organization_id', $record->organization_id)
                            ->where('id', $record->product_id)
                            ->whereNull('image_url')
                            ->update(['image_url' => $url]);
                    }
                });

            Schema::drop('product_media');
        }

        if (! Schema::hasColumn('product_imports', 'linked_image_url_count')) {
            Schema::table('product_imports', function (Blueprint $table) {
                $table->unsignedInteger('linked_image_url_count')->default(0)->after('created_variant_count');
            });
        }
        if (! Schema::hasColumn('product_imports', 'invalid_or_missing_image_url_count')) {
            Schema::table('product_imports', function (Blueprint $table) {
                $table->unsignedInteger('invalid_or_missing_image_url_count')->default(0)->after('linked_image_url_count');
            });
        }

        if (Schema::hasColumn('product_imports', 'imported_image_count')) {
            DB::table('product_imports')->update(['linked_image_url_count' => DB::raw('imported_image_count')]);
        }
        if (Schema::hasColumn('product_imports', 'failed_image_count')) {
            DB::table('product_imports')->update(['invalid_or_missing_image_url_count' => DB::raw('failed_image_count')]);
        }
        $legacyCountColumns = array_values(array_filter(
            ['imported_image_count', 'failed_image_count'],
            fn (string $column) => Schema::hasColumn('product_imports', $column),
        ));
        if ($legacyCountColumns !== []) {
            Schema::table('product_imports', function (Blueprint $table) use ($legacyCountColumns) {
                $table->dropColumn($legacyCountColumns);
            });
        }
    }

    public function down(): void
    {
        // Intentionally does not recreate the removed remote-media storage architecture.
    }
};
