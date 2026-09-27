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
use App\Models\CreditNote;
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
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('customer_company', 'like', "%{$search}%")
                ->orWhereHas('salesOrder', fn ($query) => $query->where('order_number', 'like', "%{$search}%"))))
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status),
                fn ($query) => $query->where('status', '!=', InvoiceStatus::Superseded->value),
            )
            ->when($filters['invoice_date'] ?? null, fn ($query, string $date) => $query->whereDate('invoice_date', $date))
            ->with(['store:id,name,code', 'salesOrder:id,order_number'])
            ->addSelect(['credited_amount' => CreditNote::query()->selectRaw('COALESCE(SUM(total_incl_tax), 0)')
                ->whereColumn('credit_notes.sales_order_id', 'invoices.sales_order_id')->where('status', 'issued')])
            ->latest('invoice_date')->latest('id')->paginate(20)->withQueryString()
            ->through(function (Invoice $invoice) {
                $credited = Decimal::normalize((string) ($invoice->credited_amount ?? '0.0000'));
                $net = Decimal::subtract($invoice->total_incl_tax, $credited);
                if (Decimal::compare($net, '0.0000') < 0) {
                    $net = '0.0000';
                }
                $invoice->setAttribute('credited_amount', $credited);
                $invoice->setAttribute('net_total_incl_tax', $net);
                $invoice->setAttribute('credit_state', Decimal::compare($credited, '0.0000') === 0
                    ? 'none'
                    : (Decimal::compare($credited, $invoice->total_incl_tax) >= 0 ? 'full' : 'partial'));

                return $invoice;
            });

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

            'stampApposition',

            'issuedBy:id,name',

            'cancelledBy:id,name',

            'correctedInvoice' => function ($query) {
                $query->select([
                    'invoices.id',
                    'invoices.invoice_number',
                    'invoices.version',
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

        ]);

        // The seller snapshot carries the logo as a large base64 data URI for the
        // PDF; the on-screen preview only needs a lightweight display copy.
        $seller = $invoice->seller_snapshot ?? [];
        $hasLogo = ! empty($seller['logo']);
        unset($seller['logo']);
        $invoice->setAttribute('seller_snapshot', $seller);

        $hasDiscount = Decimal::compare($invoice->discount_total, '0') !== 0
            || $invoice->lines->contains(fn ($line) => Decimal::compare($line->discount_amount, '0') !== 0);

        $isIssued = $invoice->status === InvoiceStatus::Issued;
        $isReplacement = $invoice->corrected_invoice_id !== null;
        $isCorrection = $isReplacement && $invoice->sales_order_addendum_id === null;

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
        $history = $this->correctionHistory($invoice);
        $currentVersion = collect($history)->firstWhere('is_current', true);
        $orderExpandedAfterInvoice = $invoice->status === InvoiceStatus::Issued
            && $invoice->lines->pluck('sales_order_line_id')->filter()->map(fn ($id) => (int) $id)->sort()->values()->all()
                !== $invoice->salesOrder->lines()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $creditNotes = CreditNote::query()->where('organization_id', $invoice->organization_id)
            ->where('sales_order_id', $invoice->sales_order_id)->where('status', 'issued')->orderBy('issued_at')
            ->get(['id', 'invoice_id', 'credit_note_number', 'credit_note_date', 'status', 'total_incl_tax']);
        $credited = $creditNotes->reduce(fn (string $sum, $note) => Decimal::add($sum, $note->total_incl_tax), '0.0000');
        $netAfterCredits = Decimal::subtract($invoice->total_incl_tax, $credited);
        if (Decimal::compare($netAfterCredits, '0.0000') < 0) $netAfterCredits = '0.0000';
        $paymentSummary = $payments->summary($invoice->salesOrder);
        $receivable = Decimal::subtract($netAfterCredits, $paymentSummary['net']);
        $refundObligation = Decimal::subtract($paymentSummary['net'], $netAfterCredits);
        if (Decimal::compare($receivable, '0.0000') < 0) $receivable = '0.0000';
        if (Decimal::compare($refundObligation, '0.0000') < 0) $refundObligation = '0.0000';

        return Inertia::render('Documents/Invoices/Show', [
            'invoice' => $invoice,
            'hasDiscount' => $hasDiscount,
            'sellerHasLogo' => $hasLogo,
            'isCorrection' => $isCorrection,
            'isReplacement' => $isReplacement,
            'correctionComparison' => $correctionComparison,
            'previewUrl' => route('invoices.print', $invoice),
            'accentColor' => $seller['accent_color'] ?? DocumentSellerProfile::DEFAULT_ACCENT_COLOR,
            'relatedOrderPaymentSummary' => $paymentSummary,
            'sharing' => $isIssued ? [
                'pdfUrl' => $sharePdfUrl,
                'email' => $invoice->customer_email,
                'phone' => $invoice->customer_phone,
                'whatsappPhone' => PhoneNumber::forWhatsApp($invoice->customer_phone),
                'whatsappMessage' => $this->whatsappMessage($invoice, $formatter, $sharePdfUrl),
                'defaultSubject' => "Facture {$invoice->invoice_number} — ".($seller['trade_name'] ?: $seller['legal_name'] ?? $invoice->organization->name),
                'attachmentName' => "Facture-{$invoice->invoice_number}-V{$invoice->version}.pdf",
            ] : null,
            'history' => $history,
            'isCurrentVersion' => $invoice->status === InvoiceStatus::Issued,
            'currentVersion' => $currentVersion,
            'orderExpandedAfterInvoice' => $orderExpandedAfterInvoice,
            'creditNotes' => $creditNotes,
            'creditSummary' => ['credited' => $credited, 'net' => $netAfterCredits, 'receivable' => $receivable, 'refund_obligation' => $refundObligation, 'state' => Decimal::compare($credited, '0') === 0 ? 'none' : (Decimal::compare($credited, $invoice->total_incl_tax) >= 0 ? 'full' : 'partial')],
            'mailConfigured' => $mail->isConfigured($invoice->organization),
            'stamp' => [
                'applied' => $invoice->stampApposition !== null,
                'appliedAt' => $invoice->stampApposition?->applied_at?->toIso8601String(),
            ],
            'can' => [
                'updateDraft' => $request->user()->can('updateDraft', $invoice),
                'issue' => $request->user()->can('issue', $invoice),
                'backdate' => $request->user()->hasPermission($invoice->organization_id, 'invoices.backdate'),
                'email' => $request->user()->can('email', $invoice),
                'configureMail' => $request->user()->hasPermission($invoice->organization_id, 'settings.update'),
                'stamp' => $request->user()->can('stamp', $invoice) && ! $invoice->stampApposition,
                'viewOrder' => $request->user()->can('view', $invoice->salesOrder),
            ],
        ]);
    }

    /**
     * A compact newest-first view of every immutable row in this Invoice's
     * tenant-scoped canonical-number family.
     *
     * @return list<array<string, mixed>>
     */
    private function correctionHistory(Invoice $invoice): array
    {
        return Invoice::query()
            ->where('organization_id', $invoice->organization_id)
            ->where('invoice_family_id', $invoice->invoice_family_id)
            ->orderByDesc('version')
            ->get(['id', 'invoice_family_id', 'invoice_number', 'version', 'status', 'issued_at', 'correction_reason'])
            ->map(fn (Invoice $version) => [
                'id' => $version->id,
                'invoice_number' => $version->invoice_number,
                'version' => $version->version,
                'status' => $version->status->value,
                'issued_at' => $version->issued_at?->toIso8601String(),
                'reason' => $version->correction_reason,
                'is_current' => $version->status === InvoiceStatus::Issued,
            ])->all();
    }

    private function whatsappMessage(Invoice $invoice, DocumentValueFormatter $formatter, ?string $pdfUrl): string
    {
        $name = trim((string) ($invoice->customer_name ?: $invoice->customer_company));
        $greeting = $name !== '' ? "Bonjour {$name}," : 'Bonjour,';
        $currency = $invoice->currency_code === 'MAD' ? 'DH' : $invoice->currency_code;

        return implode("\n", array_filter([
            $greeting,
            "Voici votre facture {$invoice->invoice_number}.",
            'Montant : '.$formatter->money($invoice->total_incl_tax).' '.$currency.'.',
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
