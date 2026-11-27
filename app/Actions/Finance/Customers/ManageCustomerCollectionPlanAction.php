<?php

namespace App\Actions\Finance\Customers;

use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\Sandbox\SandboxContext;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer collection plan: payment schedules (single or several installments) and payment promises.
 *
 * A schedule belongs to ONE confirmed order of the customer and must RECONCILE to that order's remaining balance at the moment
 * it is saved (sum of installments == remaining): it is a split of an existing obligation, never new debt. Saving again replaces
 * the schedule (previous installments are cancelled, kept for history). Installments hold no paid amount: what the order has
 * received since is applied oldest-installment-first when read (CustomerCollectionForecast).
 * A promise is a follow-up aid (who promised to pay what, when); it never changes a balance. No external message is ever sent.
 */
class ManageCustomerCollectionPlanAction
{
    public function __construct(private readonly SalesOrderPaymentCalculator $calculator, private readonly AuditLogger $audit) {}

    /** @param list<array{due_date:string, amount:string}> $installments */
    public function replaceSchedule(User $actor, Customer $customer, SalesOrder $order, array $installments): void
    {
        $this->authorize($actor, $customer);
        if (count($installments) < 1 || count($installments) > 24) {
            throw ValidationException::withMessages(['installments' => 'Un échéancier compte entre 1 et 24 échéances.']);
        }
        $rows = collect($installments)->map(fn (array $r) => ['due_date' => $r['due_date'], 'amount' => Decimal::positive($r['amount'], 'installments')])->sortBy('due_date')->values();
        DB::transaction(function () use ($actor, $customer, $order, $rows) {
            $locked = SalesOrder::query()->where('organization_id', $customer->organization_id)->where('customer_id', $customer->getKey())->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesOrderStatus::Confirmed || $locked->sandbox_id !== null) {
                throw ValidationException::withMessages(['installments' => 'Seule une commande confirmée peut recevoir un échéancier.']);
            }
            $remaining = $this->calculator->remainingAmount($locked);
            $sum = $rows->reduce(fn (string $s, array $r) => Decimal::add($s, $r['amount']), '0.0000');
            if (Decimal::compare($sum, $remaining) !== 0) {
                throw ValidationException::withMessages(['installments' => "La somme des échéances ($sum) doit être égale au reste à payer de la commande ($remaining)."]);
            }
            DB::table('customer_payment_installments')->where('organization_id', $customer->organization_id)->where('sales_order_id', $locked->getKey())->whereNull('cancelled_at')
                ->update(['cancelled_at' => now(), 'updated_at' => now()]);
            foreach ($rows as $i => $row) {
                DB::table('customer_payment_installments')->insert([
                    'organization_id' => $customer->organization_id, 'customer_id' => $customer->getKey(), 'sales_order_id' => $locked->getKey(), 'sequence' => $i + 1,
                    'due_date' => $row['due_date'], 'amount' => $row['amount'], 'currency_code' => $locked->currency_code, 'created_by_user_id' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit->record('customer_schedule.saved', $actor, $customer->organization, $locked->store, $locked, newValues: [
                'order_number' => $locked->order_number, 'remaining' => $remaining, 'installments' => $rows->all(),
            ]);
        });
    }

    public function createPromise(User $actor, Customer $customer, ?SalesOrder $order, string $date, string $amount, ?string $note): int
    {
        $this->authorize($actor, $customer);
        $amount = Decimal::positive($amount, 'amount');
        if ($order !== null && ($order->customer_id !== $customer->getKey() || $order->organization_id !== $customer->organization_id)) {
            throw ValidationException::withMessages(['sales_order_id' => 'La commande doit appartenir à ce client.']);
        }
        $id = DB::table('customer_payment_promises')->insertGetId([
            'organization_id' => $customer->organization_id, 'customer_id' => $customer->getKey(), 'sales_order_id' => $order?->getKey(), 'promised_date' => $date, 'amount' => $amount,
            'currency_code' => $order?->currency_code ?? (string) config('platform.currency_code', 'MAD'), 'status' => 'open', 'note' => $note, 'created_by_user_id' => $actor->getKey(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('customer_promise.created', $actor, $customer->organization, null, $customer, newValues: ['promise_id' => $id, 'promised_date' => $date, 'amount' => $amount, 'order_id' => $order?->getKey()]);

        return $id;
    }

    public function setPromiseStatus(User $actor, Customer $customer, int $promiseId, string $status): void
    {
        $this->authorize($actor, $customer);
        if (! in_array($status, ['kept', 'broken', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => 'Statut inconnu.']);
        }
        DB::transaction(function () use ($actor, $customer, $promiseId, $status) {
            $row = DB::table('customer_payment_promises')->where('organization_id', $customer->organization_id)->where('customer_id', $customer->getKey())->where('id', $promiseId)->lockForUpdate()->first();
            abort_if($row === null, 404);
            if ($row->status !== 'open') {
                throw ValidationException::withMessages(['status' => 'Cette promesse est déjà clôturée.']);
            }
            DB::table('customer_payment_promises')->where('id', $promiseId)->update(['status' => $status, 'updated_at' => now()]);
            $this->audit->record('customer_promise.'.$status, $actor, $customer->organization, null, $customer, oldValues: ['status' => 'open'], newValues: ['promise_id' => $promiseId, 'status' => $status]);
        });
    }

    private function authorize(User $actor, Customer $customer): void
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        abort_unless($actor->active_organization_id === $customer->organization_id && $actor->hasPermission($customer->organization_id, 'finance.customers.schedules.manage'), 403);
    }
}
