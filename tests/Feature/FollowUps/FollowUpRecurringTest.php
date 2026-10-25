<?php

namespace Tests\Feature\FollowUps;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Actions\FollowUps\SaveFollowUpRecurringRuleAction;
use App\Actions\FollowUps\SaveFollowUpTemplateAction;
use App\Actions\FollowUps\TransitionFollowUpAction;
use App\Actions\FollowUps\TransitionFollowUpRecurringRuleAction;
use App\Actions\Settings\UpdateOrganizationTimezoneAction;
use App\Enums\FollowUpRecurrenceFrequency;
use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\FollowUpRecurringRule;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Services\FollowUps\FollowUpQuery;
use App\Services\FollowUps\FollowUpRecurrence;
use App\Services\FollowUps\FollowUpRecurringGenerator;
use App\Services\MembershipService;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** W3: recurring follow-up rules (generated occurrences) and follow-up templates. */
class FollowUpRecurringTest extends QuotationTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, Organization, Store, User} owner, organization, store, seller */
    private function fixture(string $timezone = 'UTC'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => $timezone];
        $organization->save();
        $store = $this->createStore($organization, $owner, 'Store A');
        $this->activate($owner, $organization, $store);
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);

        return [$owner, $organization->fresh(), $store, $seller];
    }

    /** @param array<string, mixed> $data */
    private function rule(User $actor, Organization $organization, array $data): FollowUpRecurringRule
    {
        return app(SaveFollowUpRecurringRuleAction::class)->create($actor, $organization, ['title' => 'Routine', ...$data]);
    }

    /** @return list<string> */
    private function keys(FollowUpRecurringRule $rule): array
    {
        return FollowUp::query()->where('recurring_rule_id', $rule->getKey())->orderBy('recurrence_key')->pluck('recurrence_key')->all();
    }

    private function generateDue(): array
    {
        return app(FollowUpRecurringGenerator::class)->generateDue();
    }

    /** @param array<string, mixed> $attributes @return list<string> */
    private function sequence(array $attributes, string $from, int $count): array
    {
        $rule = new FollowUpRecurringRule;
        $rule->frequency = FollowUpRecurrenceFrequency::from($attributes['frequency']);
        $rule->interval_count = $attributes['interval_count'] ?? 1;
        $rule->weekdays = $attributes['weekdays'] ?? null;
        $rule->month_day = $attributes['month_day'] ?? null;
        $rule->starts_on = $attributes['starts_on'];
        $rule->ends_on = $attributes['ends_on'] ?? null;
        $recurrence = new FollowUpRecurrence;
        $dates = [];
        $date = $recurrence->firstOnOrAfter($rule, $from);
        while ($date !== null && count($dates) < $count) {
            $dates[] = $date;
            $date = $recurrence->after($rule, $date);
        }

        return $dates;
    }

    public function test_recurrence_calendar_daily_weekly_and_monthly_with_month_end_clamp(): void
    {
        $this->assertSame(['2026-11-02', '2026-11-04', '2026-11-06'], $this->sequence(['frequency' => 'daily', 'interval_count' => 2, 'starts_on' => '2026-11-02'], '2026-11-01', 3));
        // Every Monday and Thursday (2026-11-04 is a Wednesday).
        $this->assertSame(['2026-11-05', '2026-11-09', '2026-11-12'], $this->sequence(['frequency' => 'weekly', 'weekdays' => [1, 4], 'starts_on' => '2026-11-04'], '2026-11-04', 3));
        // Every 2 weeks on Friday, weeks counted from the Monday of starts_on.
        $this->assertSame(['2026-11-06', '2026-11-20', '2026-12-04'], $this->sequence(['frequency' => 'weekly', 'interval_count' => 2, 'weekdays' => [5], 'starts_on' => '2026-11-02'], '2026-11-02', 3));
        // Day 31 → last day of shorter months (never skipped), leap year included.
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $this->sequence(['frequency' => 'monthly', 'month_day' => 31, 'starts_on' => '2026-01-31'], '2026-01-01', 4));
        $this->assertSame(['2028-01-31', '2028-02-29'], $this->sequence(['frequency' => 'monthly', 'month_day' => 31, 'starts_on' => '2028-01-01'], '2028-01-01', 2));
        // Every 3 months on the 15th.
        $this->assertSame(['2026-01-15', '2026-04-15', '2026-07-15'], $this->sequence(['frequency' => 'monthly', 'interval_count' => 3, 'month_day' => 15, 'starts_on' => '2026-01-10'], '2026-01-01', 3));
        // ends_on bounds the schedule.
        $this->assertSame(['2026-11-02', '2026-11-03'], $this->sequence(['frequency' => 'daily', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-03'], '2026-11-01', 5));
    }

    public function test_daily_rule_keeps_today_and_one_occurrence_ahead_without_duplicates(): void
    {
        [, $organization, , $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-02 08:00:00');
        $rule = $this->rule($seller, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-02']);

        $this->assertSame(['2026-11-02', '2026-11-03'], $this->keys($rule));
        $this->assertSame(0, $this->generateDue()['created'], 'Nothing more until the next local day starts.');
        $this->assertSame(0, $this->generateDue()['created']);

        Carbon::setTestNow('2026-11-03 00:00:00');
        $this->assertSame(1, $this->generateDue()['created']);
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-04'], $this->keys($rule));

        // A stale cursor pointing at an existing occurrence cannot duplicate it (exists check + unique key).
        FollowUpRecurringRule::query()->whereKey($rule->getKey())->update(['next_occurrence_on' => '2026-11-04', 'last_generated_on' => '2026-11-03', 'next_generate_at' => now()]);
        $this->generateDue();
        $this->assertSame(1, FollowUp::query()->where('recurring_rule_id', $rule->getKey())->where('recurrence_key', '2026-11-04')->count());

        $occurrence = FollowUp::query()->where('recurring_rule_id', $rule->getKey())->where('recurrence_key', '2026-11-04')->sole();
        $this->assertSame((int) $seller->getKey(), (int) $occurrence->assigned_user_id);
        $this->assertSame('2026-11-04', $occurrence->due_date);
        // W2 owns the reminder: date-only → 09:00 local.
        $this->assertSame('2026-11-04 09:00:00', $occurrence->remind_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('recurring', AuditLog::query()->where('event', 'follow_up.created')->where('auditable_id', (string) $occurrence->getKey())->sole()->new_values['source']);
    }

    public function test_timed_weekly_rule_uses_the_organization_timezone_across_dst(): void
    {
        [, $organization, , $seller] = $this->fixture('Europe/Paris');
        Carbon::setTestNow('2027-03-21 00:30:00'); // Sunday 01:30 in Paris (CET)
        $rule = $this->rule($seller, $organization, ['frequency' => 'weekly', 'weekdays' => [7], 'local_time' => '02:30', 'starts_on' => '2027-03-21']);

        $due = fn (string $key) => FollowUp::query()->where('recurring_rule_id', $rule->getKey())->where('recurrence_key', $key)->sole()->due_at->utc()->format('Y-m-d H:i');
        $this->assertSame('2027-03-21 01:30', $due('2027-03-21')); // 02:30 CET
        $this->assertSame('2027-03-28 01:30', $due('2027-03-28')); // 02:30 skipped by DST → 03:30 CEST

        Carbon::setTestNow('2027-03-27 23:00:00'); // 00:00 Paris on the 28th
        $this->generateDue();
        $this->assertSame('2027-04-04 00:30', $due('2027-04-04')); // 02:30 CEST
    }

    public function test_pause_resume_and_scheduler_downtime_never_create_a_backlog(): void
    {
        [, $organization, , $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-02 08:00:00');
        $rule = $this->rule($seller, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-02']);
        $transition = app(TransitionFollowUpRecurringRuleAction::class);

        $transition->execute($seller, $organization, $rule, ['transition' => 'pause']);
        $this->assertSame('paused', $rule->fresh()->status->value);
        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->assertSame(0, $this->generateDue()['created']);

        $transition->execute($seller, $organization, $rule->fresh(), ['transition' => 'resume']);
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-22', '2026-11-23'], $this->keys($rule), 'Resume starts from today, not 19 missed days.');

        // Scheduler down for 10 days: one catch-up occurrence (today) plus the one ahead.
        Carbon::setTestNow('2026-12-03 10:00:00');
        $this->generateDue();
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-22', '2026-11-23', '2026-12-03', '2026-12-04'], $this->keys($rule));

        $this->assertSame(['follow_up_recurring.created', 'follow_up_recurring.paused', 'follow_up_recurring.resumed'],
            AuditLog::query()->where('event', 'like', 'follow_up_recurring.%')->orderBy('id')->pluck('event')->all());
    }

    public function test_max_occurrences_and_end_date_end_the_rule(): void
    {
        [, $organization, , $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-02 08:00:00');
        $capped = $this->rule($seller, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-02', 'max_occurrences' => 3]);
        $bounded = $this->rule($seller, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-03']);
        $this->assertSame('ended', $bounded->fresh()->status->value, 'Both dates generated; nothing left.');
        $this->assertSame(['2026-11-02', '2026-11-03'], $this->keys($bounded));

        Carbon::setTestNow('2026-11-03 00:00:00');
        $this->generateDue();
        $capped->refresh();
        $this->assertSame(3, $capped->occurrences_generated);
        $this->assertSame('ended', $capped->status->value);
        $this->assertSame('schedule_complete', $capped->status_reason);
        Carbon::setTestNow('2026-11-10 00:00:00');
        $this->generateDue();
        $this->assertCount(3, $this->keys($capped));

        $this->expectException(ValidationException::class);
        app(TransitionFollowUpRecurringRuleAction::class)->execute($seller, $organization, $capped, ['transition' => 'resume']);
    }

    public function test_snoozing_or_completing_an_occurrence_never_shifts_or_stops_the_rule(): void
    {
        [, $organization, , $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-02 08:00:00'); // Monday
        $rule = $this->rule($seller, $organization, ['frequency' => 'weekly', 'weekdays' => [1], 'starts_on' => '2026-11-02']);
        $today = FollowUp::query()->where('recurring_rule_id', $rule->getKey())->where('recurrence_key', '2026-11-02')->sole();

        app(SaveFollowUpAction::class)->update($seller, $organization, $today, ['due_date' => '2026-11-03']); // snooze to Tuesday
        $next = FollowUp::query()->where('recurring_rule_id', $rule->getKey())->where('recurrence_key', '2026-11-09')->sole();
        app(TransitionFollowUpAction::class)->execute($seller, $organization, $next, ['transition' => 'complete']);

        Carbon::setTestNow('2026-11-09 00:00:00');
        $this->generateDue();
        $this->assertSame(['2026-11-02', '2026-11-09', '2026-11-16'], $this->keys($rule));
        $this->assertSame('2026-11-03', $today->fresh()->due_date);
        $this->assertSame('active', $rule->fresh()->status->value);
    }

    public function test_assignee_losing_access_unassigns_and_pauses_the_rule(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-02 08:00:00');
        $rule = $this->rule($seller, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-02']);

        app(MembershipService::class)->removeOrganizationMember($owner, OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())->where('user_id', $seller->getKey())->firstOrFail());
        $rule->refresh();
        $this->assertNull($rule->assigned_user_id);
        $this->assertSame('paused', $rule->status->value);
        $this->assertSame('assignee_lost', $rule->status_reason);
        $this->assertSame(1, AuditLog::query()->where('event', 'follow_up_recurring.reassigned')->count());
        Carbon::setTestNow('2026-11-05 00:00:00');
        $this->assertSame(0, $this->generateDue()['created']);

        // Generation-time check for stale state (store access gone without the release path having run).
        $other = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $other);
        $quotation = $this->createQuotation($owner, $organization, $store);
        $linked = $this->rule($other, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-05', 'quotation_id' => $quotation->getKey()]);
        StoreMembership::query()->where('store_id', $store->getKey())->where('user_id', $other->getKey())->delete();
        Carbon::setTestNow('2026-11-06 00:00:00');
        $this->generateDue();
        $this->assertSame('assignee_lost', $linked->fresh()->status_reason);
        $this->assertNull($linked->fresh()->assigned_user_id);
    }

    public function test_quotation_linked_rule_pauses_when_the_quotation_is_no_longer_current(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        Carbon::setTestNow('2026-11-02 08:00:00');
        $quotation = $this->createQuotation($owner, $organization, $store);
        $rule = $this->rule($owner, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-02', 'quotation_id' => $quotation->getKey()]);
        $this->assertSame((int) $store->getKey(), (int) $rule->store_id);
        $this->assertCount(2, $this->keys($rule));

        DB::table('quotations')->where('id', $quotation->getKey())->update(['status' => 'superseded']);
        Carbon::setTestNow('2026-11-03 00:00:00');
        $this->generateDue();
        $this->assertSame('quotation_not_current', $rule->fresh()->status_reason);
        $this->assertCount(2, $this->keys($rule));

        foreach ([fn () => app(TransitionFollowUpRecurringRuleAction::class)->execute($owner, $organization, $rule->fresh(), ['transition' => 'resume']),
            fn () => $this->rule($owner, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-03', 'quotation_id' => $quotation->getKey()])] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a validation error.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_timezone_change_moves_only_the_generation_gate(): void
    {
        [$owner, $organization, , $seller] = $this->fixture('UTC');
        Carbon::setTestNow('2026-11-02 08:00:00');
        $rule = $this->rule($seller, $organization, ['frequency' => 'daily', 'local_time' => '10:00', 'starts_on' => '2026-11-02']);
        $generated = FollowUp::query()->where('recurring_rule_id', $rule->getKey())->orderBy('id')->pluck('due_at', 'recurrence_key')->map(fn ($at) => (string) $at)->all();
        $this->assertSame('2026-11-03 00:00:00', $rule->fresh()->next_generate_at->utc()->format('Y-m-d H:i:s'));

        app(UpdateOrganizationTimezoneAction::class)->execute($owner, $organization, 'Asia/Tokyo');

        $this->assertSame('2026-11-02 15:00:00', $rule->fresh()->next_generate_at->utc()->format('Y-m-d H:i:s')); // 00:00 Tokyo, 3 Nov
        $this->assertSame($generated, FollowUp::query()->where('recurring_rule_id', $rule->getKey())->orderBy('id')->pluck('due_at', 'recurrence_key')->map(fn ($at) => (string) $at)->all());
        Carbon::setTestNow('2026-11-02 15:00:00');
        $this->generateDue();
        $this->assertSame('2026-11-04 01:00', FollowUp::query()->where('recurring_rule_id', $rule->getKey())->where('recurrence_key', '2026-11-04')->sole()->due_at->utc()->format('Y-m-d H:i')); // 10:00 Tokyo
    }

    public function test_rule_permissions_follow_follow_up_permissions(): void
    {
        [$owner, $organization, , $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-02 08:00:00');
        $own = $this->rule($seller, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-10', 'assigned_user_id' => null]);
        $this->assertSame((int) $seller->getKey(), (int) $own->assigned_user_id, 'A normal user cannot create Unassigned rules.');

        $this->expectStatus(fn () => $this->rule($seller, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-10', 'assigned_user_id' => $owner->getKey()]), AuthorizationException::class);
        $ownerRule = $this->rule($owner, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-10', 'assigned_user_id' => $seller->getKey()]);
        $pool = $this->rule($owner, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-10', 'assigned_user_id' => null]);
        $this->assertNull($pool->assigned_user_id);
        $this->expectStatus(fn () => app(TransitionFollowUpRecurringRuleAction::class)->execute($seller, $organization, $pool, ['transition' => 'pause']), AuthorizationException::class);

        $this->actingAs($seller)->get(route('follow-ups.recurring.index', ['assigned_to' => 'all']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Work/Recurring')->where('rules.total', 2));
        $this->actingAs($owner)->get(route('follow-ups.recurring.index', ['assigned_to' => 'unassigned']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rules.total', 1)->where('rules.data.0.id', (int) $pool->getKey()));

        $this->actingAs($seller)->post(route('follow-ups.recurring.store'), ['title' => 'HTTP', 'frequency' => 'weekly', 'starts_on' => '2026-11-10'])
            ->assertSessionHasErrors('weekdays');
        $this->actingAs($seller)->post(route('follow-ups.recurring.transition', $own), ['transition' => 'end', 'expected_version' => $own->version])
            ->assertSessionHasNoErrors();
        $this->assertSame('ended', $own->fresh()->status->value);
        $this->actingAs($seller)->patch(route('follow-ups.recurring.update', $own), ['title' => 'After end'])->assertSessionHasErrors('status');
        $this->assertNotNull($ownerRule->fresh());
    }

    public function test_templates_are_managed_by_manage_all_and_only_prefill(): void
    {
        [$owner, $organization, , $seller] = $this->fixture();
        $templates = app(SaveFollowUpTemplateAction::class);
        $call = $templates->create($owner, $organization, ['name' => 'Appel', 'title' => 'Appeler le client', 'default_priority' => 'high', 'default_due_offset_days' => 2, 'default_time' => '10:00']);
        $this->expectStatus(fn () => $templates->create($owner, $organization, ['name' => 'Appel', 'title' => 'Dup']), ValidationException::class);
        $this->expectStatus(fn () => $templates->create($seller, $organization, ['name' => 'Seller', 'title' => 'X']), AuthorizationException::class);
        $old = $templates->create($owner, $organization, ['name' => 'Ancien', 'title' => 'Old']);
        $templates->update($owner, $organization, $old, ['active' => false]);
        $templates->update($owner, $organization, $call, ['title' => 'Appeler le client (relance)']);

        $context = app(FollowUpQuery::class)->formContext($seller, $organization);
        $this->assertSame(['Appel'], array_column($context['templates'], 'name'));
        $this->assertSame('Appeler le client (relance)', $context['templates'][0]['title']);
        $this->assertArrayNotHasKey('assigned_user_id', $context['templates'][0]);

        $this->actingAs($seller)->get(route('follow-ups.templates.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('follow-ups.templates.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Work/Templates')->where('templates.total', 2));
        // Using a template is a normal create: assignment rules still apply.
        $this->actingAs($seller)->post(route('follow-ups.store'), ['title' => 'Appeler le client', 'priority' => 'high', 'assigned_user_id' => $owner->getKey()])->assertForbidden();
        $this->assertSame(['follow_up_template.created', 'follow_up_template.created', 'follow_up_template.updated', 'follow_up_template.updated'],
            AuditLog::query()->where('event', 'like', 'follow_up_template.%')->orderBy('id')->pluck('event')->all());
    }

    public function test_resets_unlink_or_pause_rules_and_keep_templates(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-02 08:00:00');
        $customer = $this->createCustomer($organization, 'ACME');
        $quotation = $this->createQuotation($owner, $organization, $store);
        $sellerRule = $this->rule($seller, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-10', 'customer_id' => $customer->getKey()]);
        $quoteRule = $this->rule($owner, $organization, ['frequency' => 'daily', 'starts_on' => '2026-11-10', 'quotation_id' => $quotation->getKey()]);
        app(SaveFollowUpTemplateAction::class)->create($owner, $organization, ['name' => 'Appel', 'title' => 'Appeler']);

        $reset = fn (OrganizationDataResetScope $scope) => app(OrganizationDataResetExecutor::class)
            ->execute($organization, $owner, app(OrganizationDataResetPreviewService::class)->preview($organization, $scope));
        $reset(OrganizationDataResetScope::SalesAndDocuments);
        $this->assertNull($quoteRule->fresh()->quotation_id);
        Carbon::setTestNow('2026-11-10 00:00:00');
        $this->generateDue();
        $this->assertSame('quotation_not_current', $quoteRule->fresh()->status_reason);

        $reset(OrganizationDataResetScope::Customers);
        $this->assertNull($sellerRule->fresh()->customer_id);
        $reset(OrganizationDataResetScope::UsersAndAccess);
        $this->assertNull($sellerRule->fresh()->assigned_user_id);
        $this->assertSame('paused', $sellerRule->fresh()->status->value);
        $this->assertDatabaseHas('follow_up_templates', ['organization_id' => $organization->getKey(), 'name' => 'Appel']);
    }

    /** @param class-string<\Throwable> $exception */
    private function expectStatus(callable $callback, string $exception): void
    {
        try {
            $callback();
            $this->fail("Expected {$exception}.");
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown);
        }
    }
}
