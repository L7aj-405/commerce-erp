<?php

namespace App\Actions\Finance;

use App\Models\FinanceExpectedCollection;
use App\Models\FinanceExpectedCollectionMatch;
use App\Models\Organization;
use App\Models\PaymentAllocation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageFinanceExpectedCollectionAction
{
    public function __construct(private readonly SalesOrderPaymentCalculator $payments, private readonly AuditLogger $audit) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, Organization $organization, array $data): FinanceExpectedCollection
    {
        $this->authorize($actor, $organization);
        return DB::transaction(function () use ($actor, $organization, $data) {
            $order = $this->order($organization, (int) $data['sales_order_id']);
            $this->assertCapacity($order, (string) $data['expected_amount']);
            $row = new FinanceExpectedCollection;
            $row->organization_id = $organization->id;
            $this->apply($row, $order, $data);
            $row->state = 'planned';
            $row->created_by_user_id = $actor->id;
            $row->save();
            $this->audit->record('finance_expected_collection.created', $actor, $organization, $order->store, $row,
                newValues: $row->only(['sales_order_id','expected_date','expected_amount','currency_code','reference']));
            return $row;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, FinanceExpectedCollection $collection, array $data): FinanceExpectedCollection
    {
        $this->authorize($actor, $collection->organization);
        return DB::transaction(function () use ($actor, $collection, $data) {
            $row = FinanceExpectedCollection::query()->withoutGlobalScopes()->where('organization_id', $collection->organization_id)->lockForUpdate()->findOrFail($collection->id);
            if ($row->state === 'cancelled') throw ValidationException::withMessages(['collection' => 'Une prévision annulée ne peut pas être modifiée.']);
            if ($row->matches()->exists() && (int) $data['sales_order_id'] !== $row->sales_order_id) {
                throw ValidationException::withMessages(['sales_order_id' => 'La commande ne peut plus être changée après un rapprochement.']);
            }
            $order = $this->order($row->organization, (int) $data['sales_order_id']);
            $matched = $row->matches()->sum('amount');
            if (Decimal::compare((string) $data['expected_amount'], (string) $matched) < 0) throw ValidationException::withMessages(['expected_amount' => 'Le montant ne peut pas être inférieur au montant déjà rapproché.']);
            $this->assertCapacity($order, Decimal::subtract((string) $data['expected_amount'], (string) $matched), $row->id);
            $old = $row->only(['sales_order_id','expected_date','expected_amount','reference','notes']);
            $this->apply($row, $order, $data); $row->save();
            $this->audit->record('finance_expected_collection.updated', $actor, $row->organization, $order->store, $row, oldValues: $old,
                newValues: $row->only(['sales_order_id','expected_date','expected_amount','reference','notes']));
            return $row;
        });
    }

    public function cancel(User $actor, FinanceExpectedCollection $collection, string $reason): FinanceExpectedCollection
    {
        $this->authorize($actor, $collection->organization);
        return DB::transaction(function () use ($actor, $collection, $reason) {
            $row = FinanceExpectedCollection::query()->withoutGlobalScopes()->where('organization_id', $collection->organization_id)->lockForUpdate()->findOrFail($collection->id);
            if ($row->state === 'cancelled') return $row;
            if ($row->matches()->exists()) throw ValidationException::withMessages(['collection' => 'Une prévision rapprochée ne peut pas être annulée.']);
            $row->state = 'cancelled'; $row->cancelled_at = now(); $row->cancelled_by_user_id = $actor->id; $row->cancellation_reason = $reason; $row->save();
            $this->audit->record('finance_expected_collection.cancelled', $actor, $row->organization, $row->store, $row, newValues: ['reason' => $reason]);
            return $row;
        });
    }

    public function match(User $actor, FinanceExpectedCollection $collection, int $allocationId, string $amount): FinanceExpectedCollectionMatch
    {
        $this->authorize($actor, $collection->organization);
        return DB::transaction(function () use ($actor, $collection, $allocationId, $amount) {
            $row = FinanceExpectedCollection::query()->withoutGlobalScopes()->where('organization_id', $collection->organization_id)->lockForUpdate()->findOrFail($collection->id);
            if ($row->state === 'cancelled') throw ValidationException::withMessages(['collection' => 'Une prévision annulée ne peut pas être rapprochée.']);
            $allocation = PaymentAllocation::query()->where('organization_id', $row->organization_id)->where('sales_order_id', $row->sales_order_id)
                ->whereHas('payment', fn ($query) => $query->where('status', 'posted'))->lockForUpdate()->find($allocationId);
            if (! $allocation) throw ValidationException::withMessages(['payment_allocation_id' => 'Cette allocation de paiement ne correspond pas à la commande.']);
            $alreadyOnCollection = $row->matches()->sum('amount');
            $alreadyOnAllocation = FinanceExpectedCollectionMatch::query()->withoutGlobalScopes()->where('organization_id', $row->organization_id)->where('payment_allocation_id', $allocation->id)->sum('amount');
            if (Decimal::compare($amount, '0.0000') <= 0
                || Decimal::compare(Decimal::add((string) $alreadyOnCollection, $amount), (string) $row->expected_amount) > 0
                || Decimal::compare(Decimal::add((string) $alreadyOnAllocation, $amount), (string) $allocation->amount) > 0) {
                throw ValidationException::withMessages(['amount' => 'Le montant dépasse la capacité disponible de la prévision ou du paiement.']);
            }
            $match = new FinanceExpectedCollectionMatch;
            $match->organization_id = $row->organization_id; $match->expected_collection_id = $row->id;
            $match->payment_allocation_id = $allocation->id; $match->amount = $amount; $match->matched_by_user_id = $actor->id; $match->save();
            $this->audit->record('finance_expected_collection.matched', $actor, $row->organization, $row->store, $row,
                newValues: ['payment_allocation_id' => $allocation->id, 'amount' => $amount]);
            return $match;
        });
    }

    private function authorize(User $actor, Organization $organization): void
    { abort_unless($organization->status === 'active' && $actor->active_organization_id === $organization->id && $actor->hasPermission($organization, 'finance.monthly_situation.manage'), 403); }

    private function order(Organization $organization, int $id): SalesOrder
    {
        $order = SalesOrder::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->lockForUpdate()->find($id);
        if (! $order) throw ValidationException::withMessages(['sales_order_id' => 'Commande introuvable pour cette organisation.']);
        return $order;
    }

    private function assertCapacity(SalesOrder $order, string $requestedUnmatched, ?int $except = null): void
    {
        $outstanding = $this->payments->remainingAmount($order);
        $query = FinanceExpectedCollection::query()->withoutGlobalScopes()->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->id)->where('state', 'planned');
        if ($except) $query->whereKeyNot($except);
        $scheduled = $query->selectRaw("COALESCE(SUM(expected_amount - COALESCE((SELECT SUM(m.amount) FROM finance_expected_collection_matches m WHERE m.organization_id=finance_expected_collections.organization_id AND m.expected_collection_id=finance_expected_collections.id),0)),0) as amount")->value('amount');
        if (Decimal::compare(Decimal::add((string) $scheduled, $requestedUnmatched), $outstanding) > 0) {
            throw ValidationException::withMessages(['expected_amount' => 'Les encaissements planifiés dépassent le solde client restant.']);
        }
    }

    /** @param array<string,mixed> $data */
    private function apply(FinanceExpectedCollection $row, SalesOrder $order, array $data): void
    {
        $row->store_id = $order->store_id; $row->sales_order_id = $order->id; $row->label = $data['label'] ?? null;
        $row->reference = $data['reference'] ?? null; $row->expected_date = $data['expected_date']; $row->expected_amount = $data['expected_amount'];
        $row->currency_code = $order->currency_code; $row->notes = $data['notes'] ?? null;
    }
}
