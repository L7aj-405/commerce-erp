<?php

namespace App\Http\Controllers\Quotations;

use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Http\Controllers\Controller;
use App\Models\Quotation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuotationConversionController extends Controller
{
    public function store(Request $request, Quotation $quotation, ConvertQuotationToSalesOrderAction $action): RedirectResponse
    {
        $this->authorize('convert', $quotation);
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer']]);

        $result = $action->execute($request->user(), $quotation, ['warehouse_id' => $data['warehouse_id'] ?? null]);

        $redirect = redirect()->route('sales.orders.show', $result['order']);
        if (! empty($result['stock_warnings'])) {
            $names = collect($result['stock_warnings'])->pluck('product_name')->implode(', ');
            $redirect->with('success', "Commande créée. Stock à vérifier pour : {$names}.");
        } elseif ($result['reused']) {
            $redirect->with('success', 'Ce devis était déjà transformé — commande existante ouverte.');
        } else {
            $redirect->with('success', 'Devis transformé en commande brouillon.');
        }

        return $redirect;
    }
}
