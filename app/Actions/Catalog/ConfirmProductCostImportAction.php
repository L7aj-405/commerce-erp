<?php

namespace App\Actions\Catalog;

use App\Models\ProductCostImport;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmProductCostImportAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, ProductCostImport $import): ProductCostImport
    {
        abort_unless($actor->hasPermission($import->organization_id, 'product_cost.import'), 403);

        return DB::transaction(function () use ($actor, $import) {
            $lockedImport = ProductCostImport::query()
                ->where('organization_id', $import->organization_id)
                ->whereKey($import->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedImport->status !== 'previewed') {
                throw ValidationException::withMessages(['import' => 'Cet import a déjà été confirmé ou n’est plus disponible.']);
            }

            $rows = $lockedImport->rows()->where('status', 'ready')->lockForUpdate()->get();
            $variants = ProductVariant::query()
                ->where('organization_id', $lockedImport->organization_id)
                ->whereIn('id', $rows->pluck('product_variant_id'))
                ->with('product:id,organization_id,status')
                ->lockForUpdate()
                ->get()->keyBy('id');
            $updated = 0;
            $unchanged = 0;

            foreach ($rows as $row) {
                $variant = $variants->get($row->product_variant_id);
                if (! $variant || $variant->status->value !== 'active' || $variant->product?->status->value !== 'active') {
                    throw ValidationException::withMessages([
                        'import' => "La variante de la ligne {$row->row_number} n’est plus active. Aucun prix n’a été modifié.",
                    ]);
                }
                if ((string) $variant->purchase_price === (string) $row->new_purchase_price) {
                    $row->status = 'unchanged';
                    $row->message = 'Le prix est déjà à jour.';
                    $row->save();
                    $unchanged++;
                    continue;
                }

                $variant->purchase_price = $row->new_purchase_price;
                $variant->save();
                $row->status = 'updated';
                $row->save();
                $updated++;
            }

            $lockedImport->status = 'completed';
            $lockedImport->updated_count = $updated;
            $lockedImport->unchanged_count += $unchanged;
            $lockedImport->confirmed_at = now();
            $lockedImport->save();

            $this->audit->record(
                'product_cost.import_confirmed',
                $actor,
                $lockedImport->organization,
                auditable: $lockedImport,
                newValues: [
                    'import_id' => $lockedImport->getKey(),
                    'rows_processed' => $lockedImport->total_rows,
                    'rows_updated' => $updated,
                    'rows_skipped' => $lockedImport->total_rows - $lockedImport->ready_count,
                    'rows_failed' => 0,
                ],
            );

            return $lockedImport->fresh();
        });
    }
}
