<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces organization-scoped variant `reference` uniqueness at the database
 * level, matching VariantReferenceUniqueness (trimmed, blank → NULL, compared
 * under the column's case-insensitive collation).
 *
 * The migration never modifies data. It refuses to run — and adds nothing —
 * when existing rows would make the index disagree with the application rule:
 * duplicate references (case/whitespace-insensitive), blank or untrimmed
 * stored values, or a case-sensitive column collation on MySQL/MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertCaseInsensitiveCollation();
        $this->assertNoDuplicates();
        $this->assertStoredValuesNormalized();

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique(['organization_id', 'reference']);
        });
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->index(['organization_id', 'reference']);
        });
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'reference']);
        });
    }

    private function assertCaseInsensitiveCollation(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $collation = DB::selectOne(
            "select collation_name as name from information_schema.columns where table_schema = database() and table_name = 'product_variants' and column_name = 'reference'",
        )?->name;

        if (! is_string($collation) || ! str_ends_with(strtolower($collation), '_ci')) {
            throw new RuntimeException("product_variants.reference uses collation '{$collation}', which is not case-insensitive. A plain unique index would not match the application rule; aborting without changes.");
        }
    }

    private function assertNoDuplicates(): void
    {
        $groups = DB::select(
            'select organization_id, lower(trim(reference)) as normalized_reference, count(*) as total, group_concat(id) as variant_ids
             from product_variants
             where reference is not null
             group by organization_id, lower(trim(reference))
             having count(*) > 1',
        );

        if ($groups === []) {
            return;
        }

        $lines = array_map(function ($group) {
            $variants = DB::table('product_variants')->whereIn('id', explode(',', (string) $group->variant_ids))
                ->orderBy('id')->get(['id', 'sku', 'reference'])
                ->map(fn ($v) => "#{$v->id} sku=".($v->sku ?? 'NULL')." ref=\"{$v->reference}\"")->implode(', ');

            return "org {$group->organization_id} · \"{$group->normalized_reference}\" × {$group->total}: {$variants}";
        }, array_slice($groups, 0, 50));

        throw new RuntimeException(count($groups)." duplicate variant reference group(s) found; resolve them manually before adding the unique constraint. No data was changed.\n".implode("\n", $lines));
    }

    private function assertStoredValuesNormalized(): void
    {
        $ids = DB::table('product_variants')
            ->whereNotNull('reference')
            ->where(fn ($query) => $query->whereRaw("trim(reference) = ''")->orWhereRaw('length(reference) <> length(trim(reference))'))
            ->orderBy('id')->limit(50)->pluck('id');

        if ($ids->isNotEmpty()) {
            throw new RuntimeException('Variant references stored blank or with surrounding whitespace (variant ids: '.$ids->implode(', ').'); normalize them manually before adding the unique constraint. No data was changed.');
        }
    }
};
