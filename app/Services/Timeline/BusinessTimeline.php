<?php

namespace App\Services\Timeline;

use App\Enums\AttentionStatus;
use App\Models\AttentionItem;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DocumentEmailDelivery;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\PaymentRefund;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Attention\AttentionQuery;
use App\Services\DocumentVisibility;
use App\Services\FollowUps\FollowUpAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * W5 business activity timeline for a customer, quotation, sales order or
 * invoice (web now; MCP/API/desktop later — "what happened with X?").
 *
 * Read-only projection over records that already exist; no event table is
 * added and nothing is inferred from current state:
 * - audit_logs (allow-listed lifecycle events of the entity and of the
 *   documents, contacts, follow-ups and attention items linked to it);
 * - document_email_deliveries (real send attempts: sent / failed);
 * - payment allocations, payment reversals and refunds (posted financial rows);
 * - attention episode timestamps written once by the engine (detected_at,
 *   escalated_at, resolved_at) — system events, never attributed to a person.
 *
 * Security: the root entity needs its view permission and (documents) store
 * membership; every linked source is resolved through the same rules —
 * document view permission + the viewer's stores, FollowUpAccess::visible,
 * AttentionQuery::visible — BEFORE any query, so a page never contains an
 * event the viewer could not open, and pagination never filters afterwards.
 * Amounts need payments.view; email addresses need the document's email
 * permission. Raw audit values never leave this class: entries carry an event
 * key, safe parameters and a link.
 *
 * Pagination is a k-way keyset merge: every source returns at most
 * per_page + 1 rows after the cursor in the global order
 * (occurred_at DESC, source rank DESC, id DESC), so pages are stable, never
 * overlap and never skip. Timestamps are UTC; clients display them in the
 * organization timezone returned with each page.
 */
final class BusinessTimeline
{
    public const SUBJECTS = ['customer', 'quotation', 'sales_order', 'invoice'];

    public const CATEGORIES = ['documents', 'follow_ups', 'payments', 'attention'];

    public const PER_PAGE = 25;

    /** A customer timeline follows at most this many most recent linked records per kind (see SCALABILITY_ARCHITECTURE §17). */
    public const SUBJECT_CAP = 1000;

    // Source ranks: the tie-breaker after occurred_at, before the source row id.
    private const AUDIT = 8;
    private const EMAIL = 7;
    private const PAYMENT = 6;
    private const REVERSAL = 5;
    private const REFUND = 4;
    private const DETECTED = 3;
    private const ESCALATED = 2;
    private const RESOLVED = 1;

    private const SOURCES = [self::AUDIT => 'audit', self::EMAIL => 'email', self::PAYMENT => 'payment', self::REVERSAL => 'reversal',
        self::REFUND => 'refund', self::DETECTED => 'detected', self::ESCALATED => 'escalated', self::RESOLVED => 'resolved'];

    /** Business lifecycle only — edits of drafts, stamps and previews stay in the technical audit. */
    private const QUOTATION_EVENTS = ['quotation.created', 'quotation.issued', 'quotation.revision_started', 'quotation.accepted', 'quotation.rejected', 'quotation.converted'];
    private const ORDER_EVENTS = ['sales_order.created', 'sales_order.confirmed', 'sales_order.fulfilled', 'sales_order.cancelled', 'sales_order.correction_started', 'sales_order.correction_completed',
        'sales_order.shipment_shared_email', 'sales_order.shipment_shared_whatsapp'];
    private const INVOICE_EVENTS = ['invoice.issued', 'invoice.draft_cancelled'];
    private const CUSTOMER_EVENTS = ['customer.created', 'customer.updated'];
    private const CONTACT_EVENTS = ['contact.created', 'contact.updated', 'contact.archived'];
    private const FOLLOW_UP_EVENTS = ['follow_up.created', 'follow_up.reassigned', 'follow_up.rescheduled', 'follow_up.completed', 'follow_up.cancelled', 'follow_up.reopened'];
    private const ATTENTION_EVENTS = ['attention.claimed', 'attention.reassigned', 'attention.unassigned', 'attention.snoozed', 'attention.dismissed', 'attention.follow_up_created'];

    public function __construct(
        private readonly FollowUpAccess $access,
        private readonly AttentionQuery $attention,
        private readonly DocumentVisibility $documents,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * One page of the timeline, or null when the viewer may not see the root
     * entity (callers answer 404 and reveal nothing).
     *
     * @param  array<string, mixed>  $filters
     * @return array{entries: list<array<string, mixed>>, next_cursor: string|null, timezone: string}|null
     */
    public function page(User $user, Organization $organization, string $subject, int $id, array $filters = []): ?array
    {
        $root = $this->root($user, $organization, $subject, $id);
        if ($root === null) {
            return null;
        }
        $categories = isset($filters['category']) ? [(string) $filters['category']] : self::CATEGORIES;
        $limit = (int) ($filters['per_page'] ?? self::PER_PAGE) + 1;
        $cursor = self::decodeCursor($filters['cursor'] ?? null);
        $scope = $this->scope($user, $organization, $subject, $root);
        $orgId = (int) $organization->getKey();

        $rows = [];
        $add = function (int $rank, iterable $models, string $time) use (&$rows): void {
            foreach ($models as $model) {
                $rows[] = ['at' => CarbonImmutable::parse($model->getRawOriginal($time) ?? $model->{$time}, 'UTC'), 'rank' => $rank, 'id' => (int) $model->getKey(), 'model' => $model];
            }
        };

        $groups = $this->auditGroups($scope, $categories);
        if ($groups !== []) {
            // Two steps: pick the page's ids from the covering subject index — one simple statement per subject
            // group (OR-ing large IN lists with the cursor makes MySQL's range analysis explode) — then read only
            // that page's full rows. Without the hint MySQL prefers (organization_id, created_at) for
            // ORDER BY … LIMIT and walks the organization's whole history (measured: ~600k rows, 0.5–0.8 s).
            $candidates = [];
            foreach ($groups as [$type, $ids, $events]) {
                // auditable_id is a string column: compare strings so the index is used.
                $query = AuditLog::query()->forceIndex('audit_subject_timeline_idx')->select(['id', 'created_at'])
                    ->where('auditable_type', $type)->whereIn('auditable_id', array_map('strval', $ids))
                    ->where('organization_id', $orgId)->whereIn('event', $events);
                foreach ($this->window($query, 'created_at', 'id', self::AUDIT, $cursor, $limit)->toBase()->get() as $row) {
                    $candidates[(int) $row->id] = [CarbonImmutable::parse($row->created_at, 'UTC')->getTimestamp(), (int) $row->id];
                }
            }
            usort($candidates, fn (array $a, array $b) => $b <=> $a);
            $ids = array_column(array_slice($candidates, 0, $limit), 1);
            $add(self::AUDIT, $ids === [] ? [] : AuditLog::query()->whereIn('id', $ids)->get(), 'created_at');
        }

        if (in_array('documents', $categories, true) && ($scope['emails'] !== [])) {
            $query = DocumentEmailDelivery::query()->where('organization_id', $orgId)
                ->where(function (Builder $query) use ($scope) {
                    foreach ($scope['emails'] as $type => $ids) {
                        $query->orWhere(fn (Builder $group) => $group->where('document_type', $type)->whereIn('document_id', $ids));
                    }
                });
            $add(self::EMAIL, $this->window($query, 'created_at', 'id', self::EMAIL, $cursor, $limit)->get(), 'created_at');
        }

        if (in_array('payments', $categories, true) && $scope['payment_orders'] !== []) {
            $allocations = fn () => DB::table('payment_allocations as a')->join('payments as p', fn ($join) => $join
                ->on('p.id', '=', 'a.payment_id')->on('p.organization_id', '=', 'a.organization_id'))
                ->where('a.organization_id', $orgId)->whereIn('a.sales_order_id', $scope['payment_orders'])
                ->select(['a.id', 'a.sales_order_id', 'a.amount', 'p.id as payment_id', 'p.payment_number', 'p.method', 'p.currency_code',
                    'p.created_at', 'p.received_by_user_id', 'p.reversed_at', 'p.reversed_by_user_id']);
            foreach ([self::PAYMENT => 'p.created_at', self::REVERSAL => 'p.reversed_at'] as $rank => $column) {
                $query = $allocations()->when($rank === self::REVERSAL, fn ($query) => $query->whereNotNull('p.reversed_at'));
                foreach ($this->window($query, $column, 'a.id', $rank, $cursor, $limit)->get() as $row) {
                    $rows[] = ['at' => CarbonImmutable::parse($rank === self::PAYMENT ? $row->created_at : $row->reversed_at, 'UTC'), 'rank' => $rank, 'id' => (int) $row->id, 'model' => $row];
                }
            }
            $refunds = PaymentRefund::query()->where('organization_id', $orgId)->whereIn('sales_order_id', $scope['payment_orders']);
            $add(self::REFUND, $this->window($refunds, 'created_at', 'id', self::REFUND, $cursor, $limit)->get(), 'created_at');
        }

        if (in_array('attention', $categories, true) && $scope['attention'] !== null) {
            foreach ([self::DETECTED => 'detected_at', self::ESCALATED => 'escalated_at', self::RESOLVED => 'resolved_at'] as $rank => $column) {
                $query = ($scope['attention'])()->whereNotNull($column)
                    // A dismissed episode also gets resolved_at when it ends; only a real resolution is an event.
                    ->when($rank === self::RESOLVED, fn (Builder $query) => $query->where('status', AttentionStatus::Resolved->value));
                $add($rank, $this->window($query, $column, 'id', $rank, $cursor, $limit)->get(), $column);
            }
        }

        usort($rows, fn (array $a, array $b) => [$b['at']->getTimestamp(), $b['rank'], $b['id']] <=> [$a['at']->getTimestamp(), $a['rank'], $a['id']]);
        $more = count($rows) >= $limit;
        $rows = array_slice($rows, 0, $limit - 1);
        $last = end($rows);

        return [
            'entries' => (new TimelinePresenter($this->access, $user, $organization, $subject, (int) $root->getKey()))->present($rows),
            'next_cursor' => $more && $last ? self::encodeCursor($last) : null,
            'timezone' => $organization->businessTimezone(),
        ];
    }

    /** The root entity, if the viewer may see it: view permission, and store membership for documents. */
    private function root(User $user, Organization $organization, string $subject, int $id): ?Model
    {
        [$model, $permission] = match ($subject) {
            'customer' => [Customer::class, 'customers.view'],
            'quotation' => [Quotation::class, 'quotations.view'],
            'sales_order' => [SalesOrder::class, 'sales_orders.view'],
            'invoice' => [Invoice::class, 'invoices.view'],
        };
        if (! $this->access->allows($user, $organization, $permission)) {
            return null;
        }
        $root = $model::query()->where('organization_id', $organization->getKey())->whereKey($id)->first();

        return $root !== null && ($subject === 'customer' || $this->documents->hasStore($user, $organization, $root->store_id)) ? $root : null;
    }

    /**
     * Everything linked to the root that the viewer may see, resolved up front.
     *
     * @return array{quotations: list<int>, revisions: list<int>, converted: list<int>, orders: list<int>, invoices: list<int>, corrections: list<int>,
     *     customers: list<int>, contacts: list<int>, follow_ups: list<int>, attention_ids: list<int>, attention: (callable(): Builder)|null,
     *     payment_orders: list<int>, emails: array<class-string, list<int>>}
     */
    private function scope(User $user, Organization $organization, string $subject, Model $root): array
    {
        $orgId = (int) $organization->getKey();
        $id = (int) $root->getKey();
        // W5.1: the same document scope as the customer profile (DocumentVisibility: active member stores).
        $stores = $this->documents->storeIds($user, $organization) ?: [0];
        $can = fn (string $permission) => $this->access->allows($user, $organization, $permission);
        // Linked documents of a kind: view permission + the viewer's stores, newest first, bounded.
        $documents = fn (string $model, string $permission, string $column, int $value) => $can($permission)
            ? $this->ids($model::query()->where('organization_id', $orgId)->where($column, $value)->whereIn('store_id', $stores)) : [];

        $scope = ['quotations' => [], 'revisions' => [], 'converted' => [], 'orders' => [], 'invoices' => [], 'corrections' => [],
            'customers' => [], 'contacts' => [], 'payment_orders' => []];
        match ($subject) {
            'quotation' => $scope = [...$scope, 'quotations' => [$id], 'revisions' => $documents(Quotation::class, 'quotations.view', 'revised_from_quotation_id', $id)],
            'sales_order' => $scope = [...$scope, 'orders' => [$id], 'payment_orders' => [$id],
                'invoices' => $documents(Invoice::class, 'invoices.view', 'sales_order_id', $id),
                'converted' => $documents(Quotation::class, 'quotations.view', 'converted_sales_order_id', $id)],
            'invoice' => $scope = [...$scope, 'invoices' => [$id], 'corrections' => $documents(Invoice::class, 'invoices.view', 'corrected_invoice_id', $id)],
            'customer' => $scope = [...$scope, 'customers' => [$id],
                'contacts' => $can('contacts.view') ? $this->ids(OrganizationContact::query()->where('organization_id', $orgId)->where('customer_id', $id)) : [],
                'quotations' => $documents(Quotation::class, 'quotations.view', 'customer_id', $id),
                'orders' => $orders = $documents(SalesOrder::class, 'sales_orders.view', 'customer_id', $id),
                'payment_orders' => $orders,
                'invoices' => $documents(Invoice::class, 'invoices.view', 'customer_id', $id)],
        };

        $link = match ($subject) {
            'customer' => 'customer_id',
            'quotation' => 'quotation_id',
            'sales_order' => 'sales_order_id',
            'invoice' => 'invoice_id',
        };
        $scope['follow_ups'] = $this->access->canViewAny($user, $organization)
            ? $this->ids($this->access->visible($user, $organization)->where($link, $id)) : [];
        $scope['attention'] = $this->attention->canViewAny($user, $organization)
            ? fn (): Builder => $this->attention->visible($user, $organization)->where($link, $id) : null;
        $scope['attention_ids'] = $scope['attention'] === null ? [] : $this->ids(($scope['attention'])());
        $scope['emails'] = array_filter([Quotation::class => $scope['quotations'], Invoice::class => $scope['invoices']]);

        return $scope;
    }

    /** @return list<array{0: string, 1: list<int>, 2: list<string>}> */
    private function auditGroups(array $scope, array $categories): array
    {
        $groups = [];
        if (in_array('documents', $categories, true)) {
            $groups[] = [Quotation::class, $scope['quotations'], self::QUOTATION_EVENTS];
            $groups[] = [Quotation::class, $scope['revisions'], ['quotation.revision_started']];
            $groups[] = [Quotation::class, $scope['converted'], ['quotation.converted']];
            $groups[] = [SalesOrder::class, $scope['orders'], self::ORDER_EVENTS];
            $groups[] = [Invoice::class, $scope['invoices'], self::INVOICE_EVENTS];
            // A correction issued on the root invoice's behalf: recorded on the correction, it supersedes the root.
            $groups[] = [Invoice::class, $scope['corrections'], ['invoice.correction_issued']];
            $groups[] = [Customer::class, $scope['customers'], self::CUSTOMER_EVENTS];
            $groups[] = [OrganizationContact::class, $scope['contacts'], self::CONTACT_EVENTS];
        }
        if (in_array('follow_ups', $categories, true)) {
            $groups[] = [FollowUp::class, $scope['follow_ups'], self::FOLLOW_UP_EVENTS];
        }
        if (in_array('attention', $categories, true)) {
            $groups[] = [AttentionItem::class, $scope['attention_ids'], self::ATTENTION_EVENTS];
        }

        return array_values(array_filter($groups, fn (array $group) => $group[1] !== []));
    }

    /**
     * Rows strictly after the cursor in the global order (occurred_at DESC, rank DESC, id DESC), newest first.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     * @param  array{at: string, rank: int, id: int}|null  $cursor
     */
    private function window($query, string $time, string $id, int $rank, ?array $cursor, int $limit)
    {
        // Written as one range on the time column plus a residual tie-break: an OR on the time column doubled
        // MySQL's index ranges (measured ×5 slower on large subject lists).
        if ($cursor !== null && $rank > $cursor['rank']) {
            $query->where($time, '<', $cursor['at']);
        } elseif ($cursor !== null) {
            $query->where($time, '<=', $cursor['at']);
            if ($rank === $cursor['rank']) {
                $query->where(fn ($same) => $same->where($time, '<', $cursor['at'])->orWhere($id, '<', $cursor['id']));
            }
        }

        return $query->orderByDesc($time)->orderByDesc($id)->limit($limit);
    }

    /** @return list<int> */
    private function ids(Builder $query): array
    {
        return $query->reorder()->orderByDesc('id')->limit(self::SUBJECT_CAP)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param array{at: CarbonImmutable, rank: int, id: int} $row */
    private static function encodeCursor(array $row): string
    {
        return rtrim(strtr(base64_encode($row['at']->format('Y-m-d H:i:s').'|'.$row['rank'].'|'.$row['id']), '+/', '-_'), '=');
    }

    /** An unreadable cursor is rejected (never silently restarted at page one). @return array{at: string, rank: int, id: int}|null */
    private static function decodeCursor(mixed $cursor): ?array
    {
        if (! is_string($cursor) || $cursor === '') {
            return null;
        }
        $parts = explode('|', (string) base64_decode(strtr($cursor, '-_', '+/'), true));
        if (count($parts) !== 3 || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $parts[0]) || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])
            || ! isset(self::SOURCES[(int) $parts[1]])) {
            throw ValidationException::withMessages(['cursor' => 'Invalid timeline cursor.']);
        }

        return ['at' => $parts[0], 'rank' => (int) $parts[1], 'id' => (int) $parts[2]];
    }
}
