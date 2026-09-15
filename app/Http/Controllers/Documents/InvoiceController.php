<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\CancelInvoiceDraftAction;
use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Documents\StartInvoiceCorrectionAction;
use App\Actions\Documents\UpdateInvoiceDraftAction;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\CreateInvoiceRequest;
use App\Http\Requests\Documents\UpdateInvoiceDraftRequest;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Services\ActiveTenantContext;
use App\Services\DocumentSellerProfile;
use App\Services\DocumentValueFormatter;
use App\Services\OrganizationOutboundMailService;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $this->authorize('viewAny', [Invoice::class, $organization, $store]);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'invoice_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $invoices = Invoice::query()->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())
            ->when($filters['search'] ?? null, fn($query, string $search) => $query->where(fn($query) => $query
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('customer_company', 'like', "%{$search}%")
                ->orWhereHas('salesOrder', fn($query) => $query->where('order_number', 'like', "%{$search}%"))))
            ->when($filters['status'] ?? null, fn($query, string $status) => $query->where('status', $status))
            ->when($filters['invoice_date'] ?? null, fn($query, string $date) => $query->whereDate('invoice_date', $date))
            ->with(['store:id,name,code', 'salesOrder:id,order_number'])
            ->latest('invoice_date')->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('Documents/Invoices/Index', ['invoices' => $invoices, 'filters' => $filters]);
    }

    public function store(CreateInvoiceRequest $request, SalesOrder $order, CreateFullInvoiceFromSalesOrderAction $action): RedirectResponse
    {
        $this->authorize('create', [Invoice::class, $order]);
        $invoice = $action->execute($request->user(), $order, $request->validated());

        return redirect()->route('invoices.show', $invoice);
    }

    public function show(Request $request, Invoice $invoice, SalesOrderPaymentCalculator $payments, DocumentValueFormatter $formatter, OrganizationOutboundMailService $mail): Response
    {
        $this->authorize('view', $invoice);
        $invoice->load([
            'store:id,name,code',

            'salesOrder:id,organization_id,store_id,order_number,payment_status,total_incl_tax',

            'lines',

            'issuedBy:id,name',

            'cancelledBy:id,name',

            'correctedInvoice' => function ($query) {
                $query->select([
                    'invoices.id',
                    'invoices.invoice_number',
                    'invoices.status',
                    'invoices.issued_at',
                    'invoices.correction_reason',
                    'invoices.currency_code',
                    'invoices.subtotal_excl_tax',
                    'invoices.discount_total',
                    'invoices.tax_total',
                    'invoices.total_incl_tax',
                ]);
            },

            'correction' => function ($query) {
                $query->select([
                    'invoices.id',
                    'invoices.corrected_invoice_id',
                    'invoices.invoice_number',
                    'invoices.status',
                    'invoices.issued_at',
                    'invoices.correction_reason',
                ]);
            },
        ]);

        // The seller snapshot carries the logo as a large base64 data URI for the
        // PDF; the on-screen preview only needs a lightweight display copy.
        $seller = $invoice->seller_snapshot ?? [];
        $hasLogo = ! empty($seller['logo']);
        unset($seller['logo']);
        $invoice->setAttribute('seller_snapshot', $seller);

        $hasDiscount = Decimal::compare($invoice->discount_total, '0') !== 0
            || $invoice->lines->contains(fn($line) => Decimal::compare($line->discount_amount, '0') !== 0);

        $isIssued = $invoice->status === InvoiceStatus::Issued;
        $isCorrection = $invoice->corrected_invoice_id !== null;

        // Original vs corrected financial comparison for the correction UI's
        // delta block. Totals are the server-authoritative stored aggregates.
        $correctionComparison = $isCorrection && $invoice->correctedInvoice ? [
            'original' => [
                'subtotal_excl_tax' => $invoice->correctedInvoice->subtotal_excl_tax,
                'discount_total' => $invoice->correctedInvoice->discount_total,
                'tax_total' => $invoice->correctedInvoice->tax_total,
                'total_incl_tax' => $invoice->correctedInvoice->total_incl_tax,
            ],
            'correction' => [
                'subtotal_excl_tax' => $invoice->subtotal_excl_tax,
                'discount_total' => $invoice->discount_total,
                'tax_total' => $invoice->tax_total,
                'total_incl_tax' => $invoice->total_incl_tax,
            ],
            'delta_incl_tax' => Decimal::subtract($invoice->total_incl_tax, $invoice->correctedInvoice->total_incl_tax),
        ] : null;

        $canEditLines = $request->user()->can('editLines', $invoice);

        // A temporary signed PDF link + a prefilled WhatsApp message are only
        // meaningful for the currently-live official Invoice — never for a draft
        // and never for a superseded original (sharing must follow the
        // correction).
        $sharePdfUrl = $isIssued
            ? URL::temporarySignedRoute(
                'invoices.shared-pdf',
                now()->addDays((int) config('documents.share_link_ttl_days', 14)),
                $invoice,
            )
            : null;

        $activeCorrectionExists = Invoice::query()
            ->where('organization_id', $invoice->organization_id)
            ->where('corrected_invoice_id', $invoice->getKey())
            ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Issued->value])
            ->exists();

        return Inertia::render('Documents/Invoices/Show', [
            'invoice' => $invoice,
            'hasDiscount' => $hasDiscount,
            'sellerHasLogo' => $hasLogo,
            'isCorrection' => $isCorrection,
            'correctionComparison' => $correctionComparison,
            'productSearchUrl' => $canEditLines ? route('invoices.correction-lines.search', $invoice) : null,
            'previewUrl' => route('invoices.print', $invoice),
            'accentColor' => $seller['accent_color'] ?? DocumentSellerProfile::DEFAULT_ACCENT_COLOR,
            'relatedOrderPaymentSummary' => $payments->summary($invoice->salesOrder),
            'sharing' => $isIssued ? [
                'pdfUrl' => $sharePdfUrl,
                'email' => $invoice->customer_email,
                'phone' => $invoice->customer_phone,
                'whatsappPhone' => PhoneNumber::forWhatsApp($invoice->customer_phone),
                'whatsappMessage' => $this->whatsappMessage($invoice, $formatter, $sharePdfUrl),
                'defaultSubject' => "Facture {$invoice->invoice_number} — ".($seller['trade_name'] ?: $seller['legal_name'] ?? $invoice->organization->name),
                'attachmentName' => "Facture-{$invoice->invoice_number}.pdf",
            ] : null,
            'history' => $this->correctionHistory($invoice),
            'mailConfigured' => $mail->isConfigured($invoice->organization),
            'can' => [
                'updateDraft' => $request->user()->can('updateDraft', $invoice),
                'issue' => $request->user()->can('issue', $invoice),
                'backdate' => $request->user()->hasPermission($invoice->organization_id, 'invoices.backdate'),
                'email' => $request->user()->can('email', $invoice),
                'correct' => $request->user()->can('correct', $invoice) && ! $activeCorrectionExists,
                'editLines' => $canEditLines,
                'configureMail' => $request->user()->hasPermission($invoice->organization_id, 'settings.update'),
            ],
        ]);
    }

    /**
     * A compact, ordered view of the original / correction chain for the
     * HISTORIQUE panel. Empty when the Invoice is neither a correction nor a
     * corrected original.
     *
     * @return list<array<string, mixed>>
     */
    private function correctionHistory(Invoice $invoice): array
    {
        $entries = [];

        if ($invoice->correctedInvoice) {
            $entries[] = [
                'role' => 'original',
                'id' => $invoice->correctedInvoice->id,
                'invoice_number' => $invoice->correctedInvoice->invoice_number,
                'status' => $invoice->correctedInvoice->status->value,
                'issued_at' => $invoice->correctedInvoice->issued_at?->toIso8601String(),
                'reason' => null,
            ];
            $entries[] = [
                'role' => 'correction',
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status->value,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'reason' => $invoice->correction_reason,
            ];
        } elseif ($invoice->correction) {
            $entries[] = [
                'role' => 'original',
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status->value,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'reason' => null,
            ];
            $entries[] = [
                'role' => 'correction',
                'id' => $invoice->correction->id,
                'invoice_number' => $invoice->correction->invoice_number,
                'status' => $invoice->correction->status->value,
                'issued_at' => $invoice->correction->issued_at?->toIso8601String(),
                'reason' => $invoice->correction->correction_reason,
            ];
        }

        return $entries;
    }

    private function whatsappMessage(Invoice $invoice, DocumentValueFormatter $formatter, ?string $pdfUrl): string
    {
        $name = trim((string) ($invoice->customer_name ?: $invoice->customer_company));
        $greeting = $name !== '' ? "Bonjour {$name}," : 'Bonjour,';
        $currency = $invoice->currency_code === 'MAD' ? 'DH' : $invoice->currency_code;

        return implode("\n", array_filter([
            $greeting,
            "Voici votre facture {$invoice->invoice_number}.",
            'Montant : ' . $formatter->money($invoice->total_incl_tax) . ' ' . $currency . '.',
            $pdfUrl ? "PDF : {$pdfUrl}" : null,
        ]));
    }

    public function update(UpdateInvoiceDraftRequest $request, Invoice $invoice, UpdateInvoiceDraftAction $action): RedirectResponse
    {
        $this->authorize('updateDraft', $invoice);
        $action->execute($request->user(), $invoice, $request->validated());

        return back();
    }

    public function issue(Request $request, Invoice $invoice, IssueInvoiceAction $action): RedirectResponse
    {
        $this->authorize('issue', $invoice);
        $action->execute($request->user(), $invoice);

        return back();
    }

    public function cancel(Request $request, Invoice $invoice, CancelInvoiceDraftAction $action): RedirectResponse
    {
        $this->authorize('updateDraft', $invoice);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $action->execute($request->user(), $invoice, $data['reason'] ?? null);

        return back();
    }

    public function correct(Request $request, Invoice $invoice, StartInvoiceCorrectionAction $action): RedirectResponse
    {
        $this->authorize('correct', $invoice);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $correction = $action->execute($request->user(), $invoice, $data['reason']);

        return redirect()->route('invoices.show', $correction);
    }
}
