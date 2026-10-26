<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Enums\AttentionStatus;
use App\Enums\AttentionType;
use App\Models\AttentionItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\FollowUps\FollowUpAccess;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * W4.1 single source of attention visibility, search and presentation (web
 * now; MCP later). An item is visible only to users who may see the
 * underlying document: its view permission (quotations.view, W4.4
 * invoices.view, W4.5 sales_orders.view) in the organization AND membership of the item's store. follow_ups.manage_all grants nothing extra here — tenant
 * and store security stay authoritative.
 */
final class AttentionQuery
{
    public const VIEWS = ['open', 'snoozed', 'dismissed', 'resolved', 'all'];

    public function __construct(private readonly FollowUpAccess $access) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'view' => ['nullable', Rule::in(self::VIEWS)],
            'type' => ['nullable', Rule::enum(AttentionType::class)],
            // Domain: quotation | sales_order | invoice
            'domain' => ['nullable', Rule::in([AttentionItem::SUBJECT_QUOTATION, AttentionItem::SUBJECT_SALES_ORDER, AttentionItem::SUBJECT_INVOICE])],
            'severity' => ['nullable', Rule::enum(AttentionSeverity::class)],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'quotation_id' => ['nullable', 'integer', 'min:1'],
            // W5 team filter; still bounded by visible() (a store the viewer lacks matches nothing).
            'store_id' => ['nullable', 'integer', 'min:1'],
            // A single item (notification target); still bounded by visible().
            'focus' => ['nullable', 'integer', 'min:1'],
            // W4.3 ownership: mine | unassigned | mine_or_unassigned | all | {user id} (the last two: manage_all).
            'assigned' => ['nullable', 'string', 'max:20'],
            'escalated' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function canViewAny(User $user, Organization $organization): bool
    {
        return $this->subjects($user, $organization) !== [];
    }

    public function canView(User $user, AttentionItem $item): bool
    {
        return $this->access->allows($user, (int) $item->organization_id, AttentionOwnership::permissionFor($item->subject_type))
            && $this->access->hasStoreAccess($user, $item->store_id);
    }

    /** Subject types the user may see (by document view permission). @return list<string> */
    public function subjects(User $user, Organization $organization): array
    {
        return array_keys(array_filter([
            AttentionItem::SUBJECT_QUOTATION => $this->access->allows($user, $organization, 'quotations.view'),
            AttentionItem::SUBJECT_INVOICE => $this->access->allows($user, $organization, 'invoices.view'),
            AttentionItem::SUBJECT_SALES_ORDER => $this->access->allows($user, $organization, 'sales_orders.view'),
        ]));
    }

    public function visible(User $user, Organization $organization): Builder
    {
        $storeIds = $this->access->storeIds($user, $organization);

        return AttentionItem::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('subject_type', $this->subjects($user, $organization) ?: ['-'])
            ->where(fn (Builder $query) => $query->whereNull('store_id')->orWhereIn('store_id', $storeIds));
    }

    /** @param array<string, mixed> $filters */
    public function search(User $user, Organization $organization, array $filters): LengthAwarePaginator
    {
        $query = $this->visible($user, $organization)
            ->with(['customer:id,organization_id,display_name', 'quotation:id,organization_id,store_id,quotation_number,status', 'invoice:id,organization_id,store_id,invoice_number,status', 'salesOrder:id,organization_id,store_id,order_number,status', 'assignee:id,name'])
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('type', $type))
            ->when($filters['domain'] ?? null, fn (Builder $q, string $domain) => $q->where('subject_type', $domain))
            ->when($filters['severity'] ?? null, fn (Builder $q, string $severity) => $q->where('severity', $severity))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', (int) $id))
            ->when($filters['quotation_id'] ?? null, fn (Builder $q, $id) => $q->where('quotation_id', (int) $id))
            ->when($filters['store_id'] ?? null, fn (Builder $q, $id) => $q->where('store_id', (int) $id))
            ->when($filters['focus'] ?? null, fn (Builder $q, $id) => $q->whereKey((int) $id))
            ->when(! empty($filters['escalated']), fn (Builder $q) => $q->whereNotNull('escalated_at'));
        if (! isset($filters['focus'])) {
            $this->applyAssigned($query, $user, $organization, $filters['assigned'] ?? $this->defaultAssigned($user, $organization));
        }

        $this->applyView($query, (string) ($filters['view'] ?? 'open'));

        // Deterministic priority: still-active episodes first, then escalated critical > critical > warning > info,
        // then business urgency (QuotationAttentionDetector::urgency), oldest detection, id.
        return $query->orderByRaw('CASE WHEN active_key IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw("CASE WHEN severity = 'critical' AND escalated_at IS NOT NULL THEN 0 WHEN severity = 'critical' THEN 1 WHEN severity = 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('urgency')->orderBy('detected_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
    }

    /** The canonical status view of an attention list (also used by W5 team counts). */
    public function applyView(Builder $query, string $view): void
    {
        $now = CarbonImmutable::now('UTC');
        match ($view) {
            // A snooze that has elapsed counts as open right away (the detector confirms it on its next scan).
            'open' => $query->where(fn (Builder $q) => $q->where('status', AttentionStatus::Open->value)
                ->orWhere(fn (Builder $s) => $s->where('status', AttentionStatus::Snoozed->value)->where('snoozed_until', '<=', $now))),
            'snoozed' => $query->where('status', AttentionStatus::Snoozed->value)->where('snoozed_until', '>', $now),
            'dismissed' => $query->where('status', AttentionStatus::Dismissed->value),
            'resolved' => $query->where('status', AttentionStatus::Resolved->value),
            default => null,
        };
    }

    /** Normal users default to their own + claimable work; managers to the whole (accessible) team. */
    public function defaultAssigned(User $user, Organization $organization): string
    {
        return $this->access->canManageAll($user, $organization) ? 'all' : 'mine_or_unassigned';
    }

    private function applyAssigned(Builder $query, User $user, Organization $organization, string $assigned): void
    {
        $manager = $this->access->canManageAll($user, $organization);
        match (true) {
            $assigned === 'mine' => $query->where('assigned_user_id', $user->getKey()),
            $assigned === 'unassigned' => $query->whereNull('assigned_user_id'),
            $assigned === 'all' && $manager => null,
            $manager && ctype_digit($assigned) => $query->where('assigned_user_id', (int) $assigned),
            // Everyone else (incl. a normal user asking for "all"/another user): own + unassigned.
            default => $query->where(fn (Builder $q) => $q->where('assigned_user_id', $user->getKey())->orWhereNull('assigned_user_id')),
        };
    }

    public function openCount(User $user, Organization $organization): int
    {
        return $this->search($user, $organization, ['view' => 'open', 'per_page' => 1])->total();
    }

    /** @return array<string, mixed> */
    public function present(User $user, Organization $organization, AttentionItem $item): array
    {
        $quotation = $item->quotation;
        $invoice = $item->invoice;
        $order = $item->salesOrder;
        $customer = $item->customer && $this->access->allows($user, $organization, 'customers.view') ? $item->customer : null;
        $number = $quotation?->quotation_number ?? $invoice?->invoice_number ?? $order?->order_number ?? ($item->metadata['document_number'] ?? $item->metadata['quotation_number'] ?? null);
        $active = $item->active_key !== null;

        return [
            'id' => (int) $item->getKey(),
            'type' => $item->type->value,
            'subject_type' => $item->subject_type,
            'severity' => $item->severity->value,
            'status' => $item->status->value,
            'title' => $item->title,
            'summary' => $item->summary,
            'i18n' => $item->metadata['i18n'] ?? null,
            'quotation' => $quotation ? ['id' => (int) $quotation->getKey(), 'number' => $number,
                'can_open' => $this->access->hasStoreAccess($user, $quotation->store_id)] : null,
            // W4.4: opened through /work/attention/{id}/open-invoice (same safe store switch).
            'invoice' => $invoice ? ['id' => (int) $invoice->getKey(), 'number' => $number,
                'can_open' => $this->access->hasStoreAccess($user, $invoice->store_id)] : null,
            // W4.5: opened through /work/attention/{id}/open-sales-order (same safe store switch).
            'sales_order' => $order ? ['id' => (int) $order->getKey(), 'number' => $number,
                'can_open' => $this->access->hasStoreAccess($user, $order->store_id)] : null,
            'customer' => $customer ? ['id' => (int) $customer->getKey(), 'name' => $customer->display_name] : null,
            'detected_at' => $item->detected_at->toIso8601String(),
            'snoozed_until' => $item->snoozed_until?->toIso8601String(),
            'dismissed_at' => $item->dismissed_at?->toIso8601String(),
            'dismiss_note' => $item->dismiss_note,
            'resolved_at' => $item->resolved_at?->toIso8601String(),
            'follow_up_count' => count($item->metadata['follow_up_ids'] ?? []),
            'notified_at' => $item->notified_at?->toIso8601String(),
            'assignee' => $item->assignee ? ['id' => (int) $item->assignee->getKey(), 'name' => $item->assignee->name] : null,
            'store_id' => $item->store_id === null ? null : (int) $item->store_id,
            'escalated_at' => $item->escalated_at?->toIso8601String(),
            'can_claim' => $active && $item->assigned_user_id === null && in_array($item->status, [AttentionStatus::Open, AttentionStatus::Snoozed], true),
            'can_assign' => $active && $this->access->canManageAll($user, $organization) && in_array($item->status, [AttentionStatus::Open, AttentionStatus::Snoozed], true),
            'is_active' => $active,
            'can_act' => $active && in_array($item->status, [AttentionStatus::Open, AttentionStatus::Snoozed], true),
            'can_create_follow_up' => ($quotation !== null || $invoice !== null || $order !== null) && $this->access->canCreate($user, $organization),
            'suggestion' => ['title' => $this->suggestedTitle($item, $number), 'due_date' => BusinessTime::today($organization)],
            'version' => (int) $item->version,
        ];
    }

    private function suggestedTitle(AttentionItem $item, ?string $number): string
    {
        $number ??= '';

        return trim(match ($item->type) {
            AttentionType::QuotationSentUndecided => "Relancer le client — devis {$number}",
            AttentionType::QuotationExpiringSoon => "Relancer avant expiration — devis {$number}",
            AttentionType::QuotationExpiredUndecided => "Proposer une prolongation ou une révision — devis {$number}",
            AttentionType::QuotationFollowUpBacklog => "Traiter les relances en retard — devis {$number}",
            AttentionType::InvoiceDueSoon => "Rappeler l’échéance au client — facture {$number}",
            AttentionType::InvoiceOverdueUnpaid => "Relancer le paiement — facture {$number}",
            AttentionType::InvoiceOverduePartial => "Relancer le solde restant — facture {$number}",
            AttentionType::InvoicePaymentFollowUpBacklog => "Traiter les relances de paiement — facture {$number}",
            AttentionType::SalesOrderPaymentDueSoon => "Rappeler l’échéance au client — commande {$number}",
            AttentionType::SalesOrderUnpaidOverdue => "Relancer le paiement — commande {$number}",
            AttentionType::SalesOrderPartialOverdue => "Relancer le solde restant — commande {$number}",
            AttentionType::SalesOrderPaymentFollowUpBacklog => "Traiter les relances de paiement — commande {$number}",
            AttentionType::SalesOrderPaidNotInvoiced => "Établir la facture — commande {$number}",
        });
    }
}
