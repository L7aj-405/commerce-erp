<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Services\CreditNoteDocumentRenderer;
use App\Services\DocumentPdfService;
use App\Support\Decimal;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class CreditNoteController extends Controller
{
    public function show(CreditNote $creditNote): Response
    {
        $this->authorize('view', $creditNote);
        $creditNote->load([
            'invoice:id,invoice_number,version,total_incl_tax',
            'salesOrder:id,order_number',
            'customerReturn:id,return_number,reason',
            'lines.customerReturnLine:id,sku,variant_name',
            'issuedBy:id,name',
        ]);
        return Inertia::render('Documents/CreditNotes/Show', [
            'creditNote' => $creditNote,
            'summary' => ['net_excl_tax' => Decimal::subtract($creditNote->subtotal_excl_tax, $creditNote->discount_total)],
        ]);
    }

    public function print(CreditNote $creditNote, CreditNoteDocumentRenderer $renderer): HttpResponse
    {
        $this->authorize('view', $creditNote);
        return response($renderer->html($creditNote), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function pdf(CreditNote $creditNote, DocumentPdfService $documents): HttpResponse
    {
        $this->authorize('view', $creditNote); $document = $documents->creditNote($creditNote);
        return response($document['bytes'], 200, ['Content-Type' => $document['mime'], 'Content-Disposition' => 'inline; filename="'.$document['filename'].'"', 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
    }
}
