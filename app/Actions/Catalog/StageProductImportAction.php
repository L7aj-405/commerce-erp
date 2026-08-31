<?php

namespace App\Actions\Catalog;

use App\Models\Organization;
use App\Models\ProductImport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CatalogImport\ProductImportFileParser;
use App\Services\CatalogImport\ProductImportHeaderMapper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StageProductImportAction
{
    public function __construct(
        private readonly ProductImportFileParser $parser,
        private readonly ProductImportHeaderMapper $headers,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Organization $organization, UploadedFile $file): ProductImport
    {
        abort_unless($actor->hasPermission($organization, 'products.import'), 403);
        $parsed = $this->parser->parse($file);

        return DB::transaction(function () use ($actor, $organization, $parsed) {
            $import = new ProductImport;
            $import->organization_id = $organization->getKey();
            $import->created_by_user_id = $actor->getKey();
            $import->original_file_name = $parsed['original_name'];
            $import->source_format = $parsed['format'];
            $import->status = 'uploaded';
            $import->headers = $parsed['headers'];
            $import->mapping = $this->headers->suggest($parsed['headers']);
            $import->total_rows = count($parsed['rows']);
            $import->stock_detected = $import->mapping['stock_quantity'] !== null;
            $import->expires_at = now()->addHours((int) config('catalog_imports.unfinished_retention_hours'));
            $import->save();

            $now = now();
            foreach (array_chunk($parsed['rows'], 500, true) as $chunk) {
                $records = [];
                foreach ($chunk as $index => $row) {
                    $records[] = [
                        'organization_id' => $organization->getKey(),
                        'product_import_id' => $import->getKey(),
                        'row_number' => $index + 2,
                        'raw_data' => json_encode($row, JSON_THROW_ON_ERROR),
                        'status' => 'uploaded',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('product_import_rows')->insert($records);
            }

            $this->audit->record('catalog.product_import_uploaded', $actor, $organization, auditable: $import, newValues: [
                'file_name' => $import->original_file_name,
                'format' => $import->source_format,
                'row_count' => $import->total_rows,
            ]);

            return $import;
        });
    }
}
