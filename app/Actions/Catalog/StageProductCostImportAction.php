<?php

namespace App\Actions\Catalog;

use App\Models\Organization;
use App\Models\ProductCostImport;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CatalogImport\ExactDecimalParser;
use App\Services\CatalogImport\ProductImportFileParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StageProductCostImportAction
{
    public function __construct(
        private readonly ProductImportFileParser $parser,
        private readonly ExactDecimalParser $decimals,
    ) {}

    public function execute(User $actor, Organization $organization, UploadedFile $file): ProductCostImport
    {
        abort_unless($actor->hasPermission($organization, 'product_cost.import'), 403);
        $parsed = $this->parser->parse($file);
        $columns = $this->columns($parsed['headers']);
        $rawRows = $parsed['rows'];
        $variantIds = [];

        foreach ($rawRows as $row) {
            $id = trim((string) ($row[$columns['variant_id']] ?? ''));
            if (ctype_digit($id)) {
                $variantIds[] = (int) $id;
            }
        }
        $duplicates = array_filter(array_count_values($variantIds), fn (int $count) => $count > 1);
        $variants = ProductVariant::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', array_keys(array_count_values($variantIds)))
            ->with('product:id,organization_id,name,status')
            ->get()->keyBy('id');

        return DB::transaction(function () use ($actor, $organization, $parsed, $rawRows, $columns, $duplicates, $variants) {
            $import = new ProductCostImport;
            $import->organization_id = $organization->getKey();
            $import->created_by_user_id = $actor->getKey();
            $import->original_file_name = $parsed['original_name'];
            $import->source_format = $parsed['format'];
            $import->status = 'previewed';
            $import->total_rows = count($rawRows);
            $import->save();

            $counts = array_fill_keys(['ready', 'unchanged', 'invalid', 'not_found', 'duplicate', 'skipped'], 0);
            $now = now();
            $records = [];

            foreach ($rawRows as $offset => $raw) {
                $preview = $this->previewRow($raw, $columns, $duplicates, $variants);
                $counts[$preview['status']]++;
                $records[] = [
                    'organization_id' => $organization->getKey(),
                    'product_cost_import_id' => $import->getKey(),
                    'row_number' => $offset + 2,
                    ...$preview,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('product_cost_import_rows')->insert($chunk);
            }

            $import->ready_count = $counts['ready'];
            $import->unchanged_count = $counts['unchanged'];
            $import->invalid_count = $counts['invalid'];
            $import->not_found_count = $counts['not_found'];
            $import->duplicate_count = $counts['duplicate'];
            $import->skipped_count = $counts['skipped'];
            $import->save();

            return $import;
        });
    }

    /** @param list<string> $headers @return array<string, int> */
    private function columns(array $headers): array
    {
        $indexed = collect($headers)->mapWithKeys(fn (string $header, int $index) => [$this->header($header) => $index]);
        $aliases = [
            'variant_id' => ['internal variant id', 'id variante interne'],
            'product_id' => ['internal product id', 'id produit interne'],
            'product_name' => ['product', 'produit'],
            'variant_label' => ['variant', 'variante'],
            'sku' => ['sku'], 'reference' => ['reference'], 'barcode' => ['barcode', 'code-barres'],
            'new_price' => ['new purchase price ht', "nouveau prix d'achat ht", 'nouveau prix d achat ht'],
        ];
        $result = [];
        foreach ($aliases as $field => $names) {
            $index = collect($names)->map(fn (string $name) => $indexed->get($this->header($name)))->first(fn ($value) => $value !== null);
            if ($index !== null) {
                $result[$field] = $index;
            }
        }
        if (! isset($result['variant_id'], $result['new_price'])) {
            throw ValidationException::withMessages([
                'file' => 'Le fichier doit contenir les colonnes « Internal Variant ID » et « New Purchase Price HT ».',
            ]);
        }

        return $result;
    }

    /** @param list<string> $raw @param array<string, int> $columns @param array<int, int> $duplicates @return array<string, mixed> */
    private function previewRow(array $raw, array $columns, array $duplicates, $variants): array
    {
        $value = fn (string $key) => isset($columns[$key]) ? trim((string) ($raw[$columns[$key]] ?? '')) : '';
        $rawVariantId = $value('variant_id');
        $variantId = ctype_digit($rawVariantId) ? (int) $rawVariantId : null;
        $variant = $variantId ? $variants->get($variantId) : null;
        $base = [
            'product_variant_id' => $variantId,
            'product_id' => $variant?->product_id ?: (ctype_digit($value('product_id')) ? (int) $value('product_id') : null),
            'product_name' => $variant?->product?->name ?: ($value('product_name') ?: null),
            'variant_label' => $variant?->label ?: ($value('variant_label') ?: null),
            'sku' => $variant?->sku ?: ($value('sku') ?: null),
            'reference' => $variant?->reference ?: ($value('reference') ?: null),
            'barcode' => $variant?->barcode ?: ($value('barcode') ?: null),
            'current_purchase_price' => $variant?->purchase_price,
            'new_purchase_price' => null,
            'status' => 'invalid',
            'message' => null,
        ];

        if ($variantId === null) {
            return [...$base, 'message' => 'Identifiant interne de variante invalide.'];
        }
        if (isset($duplicates[$variantId])) {
            return [...$base, 'status' => 'duplicate', 'message' => 'Cette variante apparaît plusieurs fois dans le fichier.'];
        }
        if (! $variant) {
            return [...$base, 'status' => 'not_found', 'message' => 'Variante introuvable dans l’organisation active.'];
        }
        if ($variant->status->value !== 'active' || $variant->product?->status->value !== 'active') {
            return [...$base, 'status' => 'skipped', 'message' => 'La variante ou son produit est archivé.'];
        }
        foreach (['product_id', 'sku', 'reference', 'barcode'] as $support) {
            $provided = $value($support);
            $actual = $support === 'product_id' ? (string) $variant->product_id : (string) ($variant->{$support} ?? '');
            if ($provided !== '' && $provided !== $actual) {
                return [...$base, 'message' => 'Les identifiants descriptifs ne correspondent pas à la variante exportée.'];
            }
        }
        if ($value('new_price') === '') {
            return [...$base, 'status' => 'unchanged', 'message' => 'Prix vide : aucune modification.'];
        }
        try {
            $price = $this->decimals->parse($value('new_price'));
        } catch (InvalidArgumentException $exception) {
            return [...$base, 'message' => $exception->getMessage()];
        }
        if ($variant->purchase_price !== null && $this->decimals->compare($price, $variant->purchase_price) === 0) {
            return [...$base, 'new_purchase_price' => $price, 'status' => 'unchanged', 'message' => 'Le prix est déjà à jour.'];
        }

        return [...$base, 'new_purchase_price' => $price, 'status' => 'ready'];
    }

    private function header(string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', Str::ascii($value)) ?? $value));
    }
}
