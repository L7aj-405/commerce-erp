<?php

namespace App\Services\Timeline;

use App\Models\AttentionItem;
use App\Models\AuditLog;
use App\Models\DocumentEmailDelivery;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\PaymentRefund;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\FollowUps\FollowUpAccess;
use Illuminate\Support\Str;

/**
 * Turns one page of BusinessTimeline rows into business entries: an event key
 * (translated by the client, FR/AR), the actor (a person, or "system" when
 * nobody acted — never a guessed user), safe parameters and an optional link.
 * Raw audit values stay here; only allow-listed facts leave. Lookups are
 * batched per page (no N+1).
 */
final class TimelinePresenter
{
    private const DOCUMENTS = [Quotation::class => 'quotation', SalesOrder::class => 'sales_order', Invoice::class => 'invoice'];

    /** @var array<string, array<int, string|null>> document numbers by type */
    private array $numbers = [];

    /** @var array<int, string> */
    private array $users = [];

    /** @var array<int, FollowUp> */
    private array $followUps = [];

    /** @var array<int, AttentionItem> */
    private array $attention = [];

    /** @var array<int, string> */
    private array $contacts = [];

    public function __construct(
        private readonly FollowUpAccess $access,
        private readonly User $viewer,
        private readonly Organization $organization,
        private readonly string $rootType,
        private readonly int $rootId,
    ) {}

    /** @param list<array{at: \Carbon\CarbonImmutable, rank: int, id: int, model: mixed}> $rows @return list<array<string, mixed>> */
    public function present(array $rows): array
    {
        $this->load($rows);

        return array_map(fn (array $row) => [
            'id' => $this->source($row['rank']).':'.$row['id'],
            'occurred_at' => $row['at']->utc()->toIso8601String(),
            ...$this->entry($row),
        ], $rows);
    }

    private function source(int $rank): string
    {
        return [8 => 'audit', 7 => 'email', 6 => 'payment', 5 => 'reversal', 4 => 'refund', 3 => 'detected', 2 => 'escalated', 1 => 'resolved'][$rank];
    }

    /** @return array<string, mixed> */
    private function entry(array $row): array
    {
        $model = $row['model'];

        return match ($this->source($row['rank'])) {
            'audit' => $this->audit($model),
            'email' => $this->email($model),
            'payment', 'reversal' => $this->payment($model, $row['rank'] === 5),
            'refund' => $this->refund($model),
            default => $this->attentionLifecycle($model, $this->source($row['rank'])),
        };
    }

    /** @return array<string, mixed> */
    private function audit(AuditLog $log): array
    {
        $values = $log->new_values ?? [];
        $id = (int) $log->auditable_id;
        $params = [];
        $subject = null;
        $category = 'documents';

        if (isset(self::DOCUMENTS[$log->auditable_type])) {
            $subject = $this->document(self::DOCUMENTS[$log->auditable_type], $id);
            $params['number'] = $subject['label'];
            match ($log->event) {
                'quotation.rejected' => $params['reason'] = isset($values['reason']) ? Str::limit((string) $values['reason'], 140) : null,
                'quotation.revision_started' => $params['source_number'] = $values['source_quotation_number'] ?? null,
                'quotation.converted' => $params['order_number'] = $values['sales_order_number'] ?? null,
                'invoice.correction_issued' => $params['original_number'] = $values['original_invoice_number'] ?? null,
                default => null,
            };
        } elseif ($log->auditable_type === FollowUp::class) {
            $category = 'follow_ups';
            $followUp = $this->followUps[$id] ?? null;
            $params['title'] = $followUp?->title;
            $subject = ['type' => 'follow_up', 'id' => $id, 'label' => $followUp?->title, 'link' => $this->followUpLink($id)];
            match ($log->event) {
                'follow_up.reassigned' => $params['assignee'] = $this->userName($values['assigned_user_id'] ?? null),
                'follow_up.rescheduled' => [$params['due_date'], $params['due_at']] = [$values['due_date'] ?? null, $values['due_at'] ?? null],
                default => null,
            };
            if ($log->event === 'follow_up.created' && ($values['source'] ?? null) === 'recurring') {
                $params['recurring'] = true;
            }
        } elseif ($log->auditable_type === AttentionItem::class) {
            $category = 'attention';
            $subject = $this->attentionSubject($id);
            $params = [...$params, ...$this->attentionParams($this->attention[$id] ?? null)];
            match ($log->event) {
                'attention.reassigned', 'attention.claimed' => $params['assignee'] = $this->userName($values['new_user_id'] ?? null),
                'attention.snoozed' => $params['until'] = $values['snoozed_until'] ?? null,
                default => null,
            };
        } elseif ($log->auditable_type === OrganizationContact::class) {
            $params['name'] = $this->contacts[$id] ?? null;
        }

        // A converted quotation leads to its sales order (opened through the same safe store switch).
        $link = $subject['link'] ?? null;
        if ($log->event === 'quotation.converted' && isset($values['sales_order_id']) && $this->allows('sales_orders.view')) {
            $link ??= $this->documentLink('sales_order', (int) $values['sales_order_id']);
        }

        return [
            'category' => $category,
            'event' => $log->event,
            'actor' => $this->actor($log->actor_id, $log->actor_channel === 'mcp' ? 'mcp' : null),
            'params' => array_filter($params, fn ($value) => $value !== null),
            'subject' => $subject === null ? null : ['type' => $subject['type'], 'id' => $subject['id'], 'label' => $subject['label']],
            'link' => $link,
        ];
    }

    /** @return array<string, mixed> */
    private function email(DocumentEmailDelivery $delivery): array
    {
        $type = self::DOCUMENTS[$delivery->document_type] ?? 'quotation';
        $subject = $this->document($type, (int) $delivery->document_id);
        $recipients = array_values(array_filter((array) $delivery->to_recipients));
        $params = ['number' => $subject['label'], 'document' => $type, 'recipients' => count($recipients) + count((array) $delivery->cc_recipients)];
        // Addresses only for people allowed to email this kind of document; everyone else sees a count.
        if ($recipients !== [] && $this->allows($type === 'invoice' ? 'invoices.email' : 'quotations.email')) {
            $first = $recipients[0];
            $params['recipient'] = is_array($first) ? ($first['email'] ?? null) : (string) $first;
        }

        return [
            'category' => 'documents',
            'event' => $delivery->status === DocumentEmailDelivery::STATUS_SENT ? 'email.sent' : 'email.failed',
            'actor' => $this->actor($delivery->sender_user_id),
            'params' => array_filter($params, fn ($value) => $value !== null),
            'subject' => ['type' => $type, 'id' => (int) $delivery->document_id, 'label' => $subject['label']],
            'link' => $subject['link'],
        ];
    }

    /** @return array<string, mixed> */
    private function payment(object $allocation, bool $reversal): array
    {
        $subject = $this->document('sales_order', (int) $allocation->sales_order_id);

        return [
            'category' => 'payments',
            'event' => $reversal ? 'payment.reversed' : 'payment.posted',
            'actor' => $this->actor($reversal ? $allocation->reversed_by_user_id : $allocation->received_by_user_id),
            'params' => array_filter([
                'number' => $subject['label'],
                'payment_number' => $allocation->payment_number,
                'method' => $allocation->method,
                ...$this->amount((string) $allocation->amount, (string) $allocation->currency_code),
            ], fn ($value) => $value !== null),
            'subject' => ['type' => 'sales_order', 'id' => (int) $allocation->sales_order_id, 'label' => $subject['label']],
            'link' => $subject['link'],
        ];
    }

    /** @return array<string, mixed> */
    private function refund(PaymentRefund $refund): array
    {
        $subject = $this->document('sales_order', (int) $refund->sales_order_id);

        return [
            'category' => 'payments',
            'event' => 'payment.refunded',
            'actor' => $this->actor($refund->refunded_by_user_id),
            'params' => array_filter([
                'number' => $subject['label'],
                'refund_number' => $refund->refund_number,
                'method' => $refund->method,
                ...$this->amount((string) $refund->amount, (string) $refund->currency_code),
            ], fn ($value) => $value !== null),
            'subject' => ['type' => 'sales_order', 'id' => (int) $refund->sales_order_id, 'label' => $subject['label']],
            'link' => $subject['link'],
        ];
    }

    /** Detection, escalation and resolution are the engine's doing: always a system actor. @return array<string, mixed> */
    private function attentionLifecycle(AttentionItem $item, string $source): array
    {
        $subject = $this->attentionSubject((int) $item->getKey(), $item);

        return [
            'category' => 'attention',
            'event' => 'attention.'.$source,
            'actor' => ['type' => 'system'],
            'params' => $this->attentionParams($item),
            'subject' => ['type' => $subject['type'], 'id' => $subject['id'], 'label' => $subject['label']],
            'link' => $subject['link'],
        ];
    }

    /** @return array<string, mixed> */
    private function attentionParams(?AttentionItem $item): array
    {
        if ($item === null) {
            return [];
        }
        return array_filter([
            'severity' => $item->severity->value,
            'attention_type' => $item->type->value,
            'document' => $item->subject_type,
            'number' => $this->numbers[$item->subject_type][(int) $item->subject_id] ?? ($item->metadata['document_number'] ?? null),
        ], fn ($value) => $value !== null);
    }

    /** @return array{type: string, id: int, label: string|null, link: array<string, string>|null} */
    private function attentionSubject(int $id, ?AttentionItem $item = null): array
    {
        $item ??= $this->attention[$id] ?? null;

        return ['type' => 'attention', 'id' => $id, 'label' => $item?->type->value, 'link' => ['method' => 'get', 'href' => '/work/attention?view=all&focus='.$id]];
    }

    /** @return array{type: string, id: int, label: string|null, link: array<string, string>|null} */
    private function document(string $type, int $id): array
    {
        return ['type' => $type, 'id' => $id, 'label' => $this->numbers[$type][$id] ?? null,
            'link' => $type === $this->rootType && $id === $this->rootId ? null : $this->documentLink($type, $id)];
    }

    /** @return array{method: string, href: string} */
    private function documentLink(string $type, int $id): array
    {
        return ['method' => 'post', 'href' => "/timeline/open/{$type}/{$id}"];
    }

    /** @return array{method: string, href: string} */
    private function followUpLink(int $id): array
    {
        $all = $this->access->canManageAll($this->viewer, $this->organization) ? '&assigned_to=all' : '';

        return ['method' => 'get', 'href' => '/work?tab=all&focus='.$id.$all];
    }

    /** @return array{amount?: string, currency?: string} */
    private function amount(string $amount, string $currency): array
    {
        return $this->allows('payments.view') ? ['amount' => $amount, 'currency' => $currency] : [];
    }

    /** @return array{type: string, name?: string, channel?: string} */
    private function actor(mixed $userId, ?string $channel = null): array
    {
        $name = $userId === null ? null : ($this->users[(int) $userId] ?? null);

        return $name === null ? ['type' => 'system'] : array_filter(['type' => 'user', 'name' => $name, 'channel' => $channel]);
    }

    private function userName(mixed $userId): ?string
    {
        return $userId === null ? null : ($this->users[(int) $userId] ?? null);
    }

    private function allows(string $permission): bool
    {
        return $this->access->allows($this->viewer, $this->organization, $permission);
    }

    /** One batched query per lookup kind for the whole page. */
    private function load(array $rows): void
    {
        $documents = ['quotation' => [], 'sales_order' => [], 'invoice' => []];
        $users = [];
        $followUps = [];
        $attention = [];
        $contacts = [];
        foreach ($rows as $row) {
            $model = $row['model'];
            if ($model instanceof AuditLog) {
                $users[] = $model->actor_id;
                $values = $model->new_values ?? [];
                $users[] = $values['assigned_user_id'] ?? null;
                $users[] = $values['new_user_id'] ?? null;
                $id = (int) $model->auditable_id;
                match (true) {
                    isset(self::DOCUMENTS[$model->auditable_type]) => $documents[self::DOCUMENTS[$model->auditable_type]][] = $id,
                    $model->auditable_type === FollowUp::class => $followUps[] = $id,
                    $model->auditable_type === AttentionItem::class => $attention[] = $id,
                    $model->auditable_type === OrganizationContact::class => $contacts[] = $id,
                    default => null,
                };
            } elseif ($model instanceof DocumentEmailDelivery) {
                $users[] = $model->sender_user_id;
                $documents[self::DOCUMENTS[$model->document_type] ?? 'quotation'][] = (int) $model->document_id;
            } elseif ($model instanceof PaymentRefund) {
                $users[] = $model->refunded_by_user_id;
                $documents['sales_order'][] = (int) $model->sales_order_id;
            } elseif ($model instanceof AttentionItem) {
                $this->attention[(int) $model->getKey()] = $model;
            } else {
                $users[] = $row['rank'] === 5 ? $model->reversed_by_user_id : $model->received_by_user_id;
                $documents['sales_order'][] = (int) $model->sales_order_id;
            }
        }

        $orgId = $this->organization->getKey();
        $attention = array_diff(array_unique($attention), array_keys($this->attention));
        if ($attention !== []) {
            foreach (AttentionItem::query()->where('organization_id', $orgId)->whereIn('id', $attention)->get() as $item) {
                $this->attention[(int) $item->getKey()] = $item;
            }
        }
        foreach ($this->attention as $item) {
            $documents[$item->subject_type][] = (int) $item->subject_id;
        }

        foreach ([['quotation', Quotation::class, 'quotation_number'], ['sales_order', SalesOrder::class, 'order_number'], ['invoice', Invoice::class, 'invoice_number']] as [$type, $model, $column]) {
            $ids = array_values(array_unique(array_filter($documents[$type])));
            $this->numbers[$type] = $ids === [] ? [] : $model::query()->where('organization_id', $orgId)->whereIn('id', $ids)->pluck($column, 'id')->all();
        }
        $users = array_values(array_unique(array_filter(array_map(fn ($id) => $id === null ? null : (int) $id, $users))));
        $this->users = $users === [] ? [] : User::query()->whereIn('id', $users)->pluck('name', 'id')->all();
        $this->followUps = $followUps === [] ? [] : FollowUp::query()->where('organization_id', $orgId)->whereIn('id', array_unique($followUps))->get(['id', 'title'])->keyBy('id')->all();
        $this->contacts = $contacts === [] ? [] : OrganizationContact::query()->where('organization_id', $orgId)->whereIn('id', array_unique($contacts))->pluck('full_name', 'id')->all();
    }
}
