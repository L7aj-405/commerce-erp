<?php

namespace App\Models;

use App\Enums\SupplierInvoiceMatchStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Enums\SupplierInvoiceVoidReason;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A supplier invoice (facture fournisseur, FAF-YYYY-000001) received by the
 * organization. Only a draft is editable. Once approved (or void) the header,
 * its lines and installments are immutable at the model layer, except for the
 * two explicit state transitions below (approve, void). The lifecycle actions
 * are the only writers; direct edits of a non-draft invoice raise.
 */
class SupplierInvoice extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    /** Attributes a draft -> approved transition may change. */
    private const APPROVE_ATTRIBUTES = [
        'status', 'approved_at', 'approved_by_user_id',
        'supplier_name_snapshot', 'supplier_ice_snapshot', 'supplier_tax_identifier_snapshot', 'supplier_rc_snapshot',
        'match_status_at_approval', 'match_note', 'row_version', 'updated_at',
    ];

    /** Attributes a (draft|approved) -> void transition may change. */
    private const VOID_ATTRIBUTES = [
        'status', 'voided_at', 'voided_by_user_id', 'void_reason_code', 'void_reason',
        'dedupe_key', 'row_version', 'updated_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $originalStatus = (string) ($invoice->getRawOriginal('status') ?? SupplierInvoiceStatus::Draft->value);
            $wasDraft = $originalStatus === SupplierInvoiceStatus::Draft->value && $invoice->getRawOriginal('approved_at') === null;
            $newStatus = (string) ($invoice->getAttributes()['status'] ?? $originalStatus);
            $newStatus = $newStatus === '' ? $originalStatus : $newStatus;
            $dirty = array_keys($invoice->getDirty());

            if ($wasDraft && $newStatus === SupplierInvoiceStatus::Draft->value) {
                return;
            }

            $allowed = match (true) {
                $wasDraft && $newStatus === SupplierInvoiceStatus::Approved->value => self::APPROVE_ATTRIBUTES,
                $newStatus === SupplierInvoiceStatus::Void->value && $originalStatus !== SupplierInvoiceStatus::Void->value => self::VOID_ATTRIBUTES,
                default => [],
            };

            $forbidden = array_diff($dirty, $allowed);
            if ($allowed === [] || $forbidden !== []) {
                throw new LogicException('Supplier invoice '.$invoice->getKey().' is immutable once approved or void'
                    .($forbidden !== [] ? ' (refused: '.implode(', ', $forbidden).')' : '').'.');
            }
        });

        static::deleting(function (self $invoice): void {
            if (! self::isDraftRow((int) $invoice->getKey())) {
                throw new LogicException('Supplier invoice '.$invoice->getKey().' is immutable once approved or void and cannot be deleted.');
            }
        });
    }

    /** True when the stored row is still an editable draft (read fresh from the database). */
    public static function isDraftRow(int $invoiceId): bool
    {
        $row = DB::table('supplier_invoices')->where('id', $invoiceId)->first(['status', 'approved_at']);

        return $row === null || ($row->status === SupplierInvoiceStatus::Draft->value && $row->approved_at === null);
    }

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'subtotal_ht' => 'decimal:4',
            'total_tva' => 'decimal:4',
            'rounding_adjustment' => 'decimal:4',
            'total_ttc' => 'decimal:4',
            'control_total_ttc' => 'decimal:4',
            'status' => SupplierInvoiceStatus::class,
            'match_status_at_approval' => SupplierInvoiceMatchStatus::class,
            'void_reason_code' => SupplierInvoiceVoidReason::class,
            'row_version' => 'integer',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class)->orderBy('position')->orderBy('id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(SupplierInvoiceInstallment::class)->orderBy('position')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupplierInvoiceAttachment::class)->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lastSavedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_saved_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }
}
