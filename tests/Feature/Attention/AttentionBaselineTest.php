<?php

namespace Tests\Feature\Attention;

use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\AttentionItem;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Attention\AttentionBaselines;
use App\Services\Attention\AttentionScanner;
use App\Services\SalesOrderPaymentCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** W4.5.1: the first Sales Order receivables scan of an organization is a silent baseline. */
class AttentionBaselineTest extends QuotationTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An organization that already has history before its first attention scan. @return array{User, Organization, Store, User, FinancialAccount} */
    private function historicalOrganization(): array
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => 'UTC'];
        $organization->save();
        $store = $this->createStore($organization, $owner, 'Store A');
        $this->activate($owner, $organization, $store);
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);
        $account = $this->createFinancialAccount($organization->fresh(), ['name' => 'Caisse', 'code' => 'CAISSE']);

        return [$owner, $organization->fresh(), $store, $seller, $account];
    }

    private function order(User $owner, Organization $organization, Store $store, User $creator, string $saleDate = '2026-09-01'): SalesOrder
    {
        $order = $this->createDraftOrder($creator, $organization, $store, $this->createCustomer($organization, 'ACME'), ['sale_date' => $saleDate]);
        $this->addCustomLine($creator, $order, ['unit_price_excl_tax' => '100.0000']);
        $this->activate($owner, $organization, $store);

        return app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
    }

    private function tick(Organization $organization, string $at): void
    {
        Carbon::setTestNow($at);
        app(AttentionScanner::class)->scanOrganization($organization->fresh(), CarbonImmutable::now('UTC'));
    }

    private function notifications(): int
    {
        return UserNotification::query()->where('source_type', 'attention_item')->count();
    }

    private function marker(Organization $organization): ?object
    {
        return DB::table('attention_baselines')->where('organization_id', $organization->getKey())->where('subject_type', 'sales_order')->first();
    }

    public function test_first_scan_is_a_silent_visible_owned_baseline_that_never_escalates(): void
    {
        [$owner, $organization, $store, $seller] = $this->historicalOrganization();
        $historical = [];
        foreach (range(1, 3) as $ignored) {
            $historical[] = $this->order($owner, $organization, $store, $seller);
        }

        $this->tick($organization, '2026-11-01 09:00:00');
        $this->assertNotNull($this->marker($organization));
        $items = AttentionItem::query()->where('subject_type', 'sales_order')->get();
        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertSame(['sales_order_unpaid_overdue', 'critical', (int) $seller->getKey()], [$item->type->value, $item->severity->value, (int) $item->assigned_user_id]);
            $this->assertNotNull($item->notification_processed_at);
            $this->assertNull($item->notified_at, 'No fake delivery timestamp.');
            $this->assertTrue($item->escalation_exempt);
        }
        $this->assertSame(0, $this->notifications(), 'No rollout notification burst.');
        $this->actingAs($seller)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page->where('items.total', 3));

        // Repeated scans and two local days later: still nothing pushed, no manager escalation.
        $this->tick($organization, '2026-11-01 09:15:00');
        $this->tick($organization, '2026-11-04 09:00:00');
        $this->assertSame(0, UserNotification::query()->count());
        $this->assertSame(0, AttentionItem::query()->whereNotNull('escalated_at')->count());
        $this->assertSame(1, DB::table('attention_baselines')->count(), 'The baseline is established once.');
    }

    public function test_post_baseline_changes_and_new_orders_behave_normally(): void
    {
        [$owner, $organization, $store, $seller, $account] = $this->historicalOrganization();
        $historical = $this->order($owner, $organization, $store, $seller);
        $resolving = $this->order($owner, $organization, $store, $seller);
        $this->tick($organization, '2026-11-01 09:00:00');
        $this->assertSame(0, $this->notifications());

        // Baseline unpaid → partial: a new critical episode that notifies and escalates normally.
        $this->recordPayment($owner, $historical, $account, '40.0000');
        $this->tick($organization, '2026-11-01 09:15:00');
        $partial = AttentionItem::query()->where('sales_order_id', $historical->getKey())->whereNotNull('active_key')->sole();
        $this->assertSame(['sales_order_partial_overdue', false], [$partial->type->value, $partial->escalation_exempt]);
        $this->assertSame(1, $this->notifications());
        $this->tick($organization, '2026-11-03 09:00:00');
        $this->assertNotNull($partial->fresh()->escalated_at);
        $this->assertSame(1, UserNotification::query()->where('event_type', 'escalation')->count());

        // Baseline episode resolves (paid), then a reversal reopens: new episode, notified.
        $payment = $this->recordPayment($owner, $resolving, $account, '100.0000');
        $this->tick($organization, '2026-11-03 09:15:00');
        app(ReversePaymentAction::class)->execute($owner, $payment, 'Chèque impayé');
        $this->tick($organization, '2026-11-03 09:30:00');
        $reopened = AttentionItem::query()->where('sales_order_id', $resolving->getKey())->whereNotNull('active_key')->sole();
        $this->assertSame([2, false, true], [$reopened->episode, $reopened->escalation_exempt, $reopened->notified_at !== null]);

        // A brand-new order after the baseline: normal.
        $new = $this->order($owner, $organization, $store, $seller, '2026-11-02'); // due 2026-11-09
        $this->tick($organization, '2026-11-10 09:00:00');
        $this->assertNotNull(AttentionItem::query()->where('sales_order_id', $new->getKey())->sole()->notified_at);
    }

    public function test_a_failed_first_scan_records_no_baseline_and_notifies_nothing(): void
    {
        [$owner, $organization, $store, $seller] = $this->historicalOrganization();
        $broken = $this->order($owner, $organization, $store, $seller);
        $this->order($owner, $organization, $store, $seller);
        // One order's payment summary is malformed → that row fails inside the guarded scan.
        $this->app->instance(SalesOrderPaymentCalculator::class, new class($broken->getKey()) extends SalesOrderPaymentCalculator
        {
            public function __construct(private readonly int $brokenId) {}

            public function summaries(iterable $orders, ?\Illuminate\Support\Carbon $asOf = null): array
            {
                $summaries = parent::summaries($orders, $asOf);
                if (isset($summaries[$this->brokenId])) {
                    $summaries[$this->brokenId] = ['broken' => true];
                }

                return $summaries;
            }
        });

        $this->tick($organization, '2026-11-01 09:00:00');
        $this->assertNull($this->marker($organization));
        $this->assertSame(0, $this->notifications(), 'A domain still pending is kept away from notifications.');
        $this->assertSame(1, AttentionItem::query()->where('subject_type', 'sales_order')->count(), 'The healthy order is still visible.');

        $this->app->forgetInstance(SalesOrderPaymentCalculator::class);
        $this->app->forgetInstance(\App\Services\Attention\SalesOrderAttentionDetector::class);
        $this->tick($organization, '2026-11-01 09:15:00');
        $this->assertNotNull($this->marker($organization));
        $this->assertSame(2, AttentionItem::query()->where('subject_type', 'sales_order')->where('escalation_exempt', true)->count());
        $this->assertSame(0, $this->notifications(), 'Both historical items baselined silently on the successful retry.');
    }

    public function test_other_domains_are_unaffected_and_a_late_establish_never_suppresses_new_episodes(): void
    {
        [$owner, $organization, $store, $seller] = $this->historicalOrganization();
        $quotation = $this->createQuotation($owner, $organization, $store);
        DB::table('quotations')->where('id', $quotation->getKey())->update(['status' => 'issued', 'quotation_number' => 'DEV-1', 'valid_until' => '2026-10-01', 'issued_by_user_id' => $seller->getKey()]);
        $this->order($owner, $organization, $store, $seller);

        $this->tick($organization, '2026-11-01 09:00:00');
        $this->assertSame(1, $this->notifications(), 'The expired quotation notifies on the first scan as before; the historical order does not.');
        $this->assertSame('quotation', AttentionItem::query()->whereNotNull('notified_at')->sole()->subject_type);

        // A second establish (e.g. a concurrent or later run) never marks post-baseline episodes historical.
        $new = $this->order($owner, $organization, $store, $seller, '2026-11-02');
        $this->tick($organization, '2026-11-10 09:00:00');
        $item = AttentionItem::query()->where('sales_order_id', $new->getKey())->sole();
        $this->assertSame(0, app(AttentionBaselines::class)->establish($organization, 'sales_order', CarbonImmutable::now('UTC')));
        $this->assertFalse($item->fresh()->escalation_exempt);
        $this->assertSame(1, DB::table('attention_baselines')->count());
    }

    public function test_a_new_organization_baselines_empty_and_then_behaves_normally(): void
    {
        [$owner, $organization, $store, $seller] = $this->historicalOrganization();
        $this->tick($organization, '2026-11-01 09:00:00');
        $this->assertNotNull($this->marker($organization));
        $this->assertSame(0, AttentionItem::query()->count());

        $order = $this->order($owner, $organization, $store, $seller, '2026-11-01'); // due 2026-11-08
        $this->tick($organization, '2026-11-09 09:00:00');
        $this->assertNotNull(AttentionItem::query()->where('sales_order_id', $order->getKey())->sole()->notified_at);
    }
}
