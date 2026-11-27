<?php

namespace Tests\Unit;

use App\Support\Roles\RoleCapabilitySummary;
use PHPUnit\Framework\TestCase;

class RoleCapabilitySummaryTest extends TestCase
{
    public function test_a_role_combining_take_cancel_and_refund_payments_is_flagged(): void
    {
        $summary = RoleCapabilitySummary::summarize(['payments.create', 'payments.reverse', 'payments.refund', 'payments.view']);

        $this->assertSame(['take_payments', 'cancel_payments', 'refund_payments'], $summary['capabilities']);
        $this->assertSame(['payments_cash_control'], $summary['conflicts']);
    }

    public function test_any_two_of_the_three_payment_rights_is_not_a_conflict(): void
    {
        foreach ([
            ['payments.create', 'payments.reverse'],
            ['payments.create', 'payments.refund'],
            ['payments.reverse', 'payments.refund'],
        ] as $keys) {
            $this->assertSame([], RoleCapabilitySummary::summarize($keys)['conflicts'], implode(',', $keys));
        }
    }

    public function test_view_only_and_unrelated_permissions_produce_no_capability(): void
    {
        $this->assertSame(['capabilities' => [], 'conflicts' => []], RoleCapabilitySummary::summarize(['payments.view', 'customers.view', 'unknown.key']));
        $this->assertSame(['capabilities' => [], 'conflicts' => []], RoleCapabilitySummary::summarize([]));
    }

    public function test_a_capability_is_held_when_any_of_its_permissions_is_granted(): void
    {
        $this->assertSame(['sell'], RoleCapabilitySummary::summarize(['pos.access'])['capabilities']);
        $this->assertSame(['sell'], RoleCapabilitySummary::summarize(['quotations.create'])['capabilities']);
        $this->assertSame(['stock'], RoleCapabilitySummary::summarize(['inventory.transfer'])['capabilities']);
    }

    public function test_summarize_accepts_a_generator_and_ignores_duplicates(): void
    {
        $generator = (function () {
            yield 'payments.create';
            yield 'payments.create';
        })();

        $this->assertSame(['take_payments'], RoleCapabilitySummary::summarize($generator)['capabilities']);
    }
}
