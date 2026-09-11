<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\MutatesQuotation;
use App\Models\NonStockItem;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class RemoveQuotationLineAction
{
    use MutatesQuotation;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Quotation $quotation, QuotationLine $line): void
    {
        $this->authorizeQuotation($actor, $quotation, 'quotations.update');

        DB::transaction(function () use ($actor, $quotation, $line) {
            $quotation = $this->lockDraft($quotation);
            $line = $this->lockLine($quotation, $line);

            $removed = ['line_id' => $line->getKey(), 'description' => $line->description, 'total_incl_tax' => $line->total_incl_tax];
            $nonStockId = $line->non_stock_item_id;
            $line->delete();

            if ($nonStockId) {
                NonStockItem::query()->whereKey($nonStockId)->where('usage_count', '>', 0)->decrement('usage_count');
            }

            $this->reconcileTotals($quotation);

            $this->audit->record('quotation.updated', $actor, $quotation->organization, $quotation->store, $quotation, oldValues: $removed, newValues: [
                'quotation_total_incl_tax' => $quotation->total_incl_tax,
            ]);
        });
    }
}
