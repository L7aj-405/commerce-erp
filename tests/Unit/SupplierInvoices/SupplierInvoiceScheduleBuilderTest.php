<?php

namespace Tests\Unit\SupplierInvoices;

use App\Services\SupplierInvoices\SupplierInvoiceScheduleBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierInvoiceScheduleBuilderTest extends TestCase
{
    private SupplierInvoiceScheduleBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SupplierInvoiceScheduleBuilder;
    }

    private function assertInvalid(callable $fn, string $key): void
    {
        try {
            $fn();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_valid_single_installment(): void
    {
        $out = $this->builder->validate('2026-03-01', '120.00', [['due_date' => '2026-03-01', 'amount' => '120']]);

        $this->assertSame([['due_date' => '2026-03-01', 'amount' => '120.0000', 'position' => 1]], $out);
    }

    public function test_unsorted_input_is_ordered_by_due_date_and_keeps_extra_keys(): void
    {
        $out = $this->builder->validate('2026-03-01', '100.0000', [
            ['due_date' => '2026-05-01', 'amount' => '30.00', 'planned_payment_method' => 'transfer'],
            ['due_date' => '2026-03-15', 'amount' => '40.00'],
            ['due_date' => '2026-04-01', 'amount' => '30.00'],
        ]);

        $this->assertSame(['2026-03-15', '2026-04-01', '2026-05-01'], array_column($out, 'due_date'));
        $this->assertSame([1, 2, 3], array_column($out, 'position'));
        $this->assertSame('transfer', $out[2]['planned_payment_method']);
    }

    public function test_equal_due_dates_keep_input_order(): void
    {
        $out = $this->builder->validate('2026-03-01', '30.00', [
            ['due_date' => '2026-04-01', 'amount' => '10.00', 'tag' => 'a'],
            ['due_date' => '2026-04-01', 'amount' => '20.00', 'tag' => 'b'],
        ]);

        $this->assertSame(['a', 'b'], array_column($out, 'tag'));
    }

    public function test_sum_must_equal_total_exactly(): void
    {
        foreach (['99.9999', '100.0001', '100.01', '90'] as $sum) {
            $this->assertInvalid(fn () => $this->builder->validate('2026-03-01', '100.00', [['due_date' => '2026-03-01', 'amount' => $sum]]), 'installments');
        }
    }

    public function test_amounts_must_be_positive(): void
    {
        $this->assertInvalid(fn () => $this->builder->validate('2026-03-01', '100.00', [
            ['due_date' => '2026-03-01', 'amount' => '100.00'], ['due_date' => '2026-03-02', 'amount' => '0'],
        ]), 'installments.1.amount');
        $this->assertInvalid(fn () => $this->builder->validate('2026-03-01', '100.00', [
            ['due_date' => '2026-03-01', 'amount' => '110.00'], ['due_date' => '2026-03-02', 'amount' => '-10.00'],
        ]), 'installments.1.amount');
        $this->assertInvalid(fn () => $this->builder->validate('2026-03-01', '100.00', [['due_date' => '2026-03-01', 'amount' => 'abc']]), 'installments.0.amount');
        $this->assertInvalid(fn () => $this->builder->validate('2026-03-01', '100.00', [['due_date' => '2026-03-01', 'amount' => 100.0]]), 'installments.0.amount');
        $this->assertInvalid(fn () => $this->builder->validate('2026-03-01', '100.00', [['due_date' => '2026-03-01']]), 'installments.0.amount');
    }

    public function test_due_date_cannot_precede_invoice_date(): void
    {
        $this->assertInvalid(fn () => $this->builder->validate('2026-03-01', '100.00', [['due_date' => '2026-02-28', 'amount' => '100.00']]), 'installments.0.due_date');
    }

    public function test_due_date_equal_to_invoice_date_is_allowed(): void
    {
        $this->assertCount(1, $this->builder->validate(CarbonImmutable::parse('2026-03-01 18:30:00'), '1', [['due_date' => '2026-03-01', 'amount' => '1']]));
    }

    public function test_invalid_dates_are_rejected(): void
    {
        foreach (['2026-02-30', '2026-13-01', '01/03/2026', '', 'abc'] as $bad) {
            $this->assertInvalid(fn () => $this->builder->validate('2026-03-01', '1', [['due_date' => $bad, 'amount' => '1']]), 'installments.0.due_date');
        }
        $this->assertInvalid(fn () => $this->builder->validate('2026-03-01', '1', [['amount' => '1']]), 'installments.0.due_date');
        $this->assertInvalid(fn () => $this->builder->validate('nope', '1', [['due_date' => '2026-03-01', 'amount' => '1']]), 'invoice_date');
    }

    public function test_empty_schedule_is_rejected(): void
    {
        $this->assertInvalid(fn () => $this->builder->validate('2026-03-01', '100.00', []), 'installments');
    }

    public function test_many_installments_sum_exactly(): void
    {
        $installments = [];
        for ($i = 0; $i < 36; $i++) {
            $installments[] = ['due_date' => CarbonImmutable::parse('2026-01-31')->addMonthsNoOverflow($i)->format('Y-m-d'), 'amount' => '33.3333'];
        }
        // 36 * 33.3333 = 1199.9988 ; make the last one absorb the remainder to reach 1200.0000.
        $installments[35]['amount'] = '33.3345';

        $out = $this->builder->validate('2026-01-01', '1200.0000', $installments);

        $this->assertCount(36, $out);
        $this->assertSame(range(1, 36), array_column($out, 'position'));
        $this->assertSame('33.3345', $out[35]['amount']);

        $installments[35]['amount'] = '33.3346';
        $this->assertInvalid(fn () => $this->builder->validate('2026-01-01', '1200.0000', $installments), 'installments');
    }

    public function test_default_schedule_with_term(): void
    {
        $this->assertSame(
            [['position' => 1, 'due_date' => '2026-03-31', 'amount' => '120.0000']],
            $this->builder->defaultSchedule('2026-03-01', '120.00', 30),
        );
        $this->assertSame('2026-03-01', $this->builder->defaultSchedule('2026-03-01', '120.00', 0)[0]['due_date']);
        $this->assertSame('2027-01-30', $this->builder->defaultSchedule(CarbonImmutable::parse('2026-12-31 10:00'), '1', 30)[0]['due_date']);
        $this->assertSame('2028-02-29', $this->builder->defaultSchedule('2028-01-30', '1', 30)[0]['due_date']);
    }

    public function test_default_schedule_is_itself_valid(): void
    {
        $schedule = $this->builder->defaultSchedule('2026-03-01', '77.7700', 45);
        $this->assertSame($schedule, $this->builder->validate('2026-03-01', '77.7700', $schedule));
    }

    public function test_default_schedule_without_term_requires_explicit_due_date(): void
    {
        $this->assertInvalid(fn () => $this->builder->defaultSchedule('2026-03-01', '120.00', null), 'installments');
    }

    public function test_default_schedule_rejects_negative_term_and_non_positive_total(): void
    {
        $this->assertInvalid(fn () => $this->builder->defaultSchedule('2026-03-01', '120.00', -1), 'installments');
        $this->assertInvalid(fn () => $this->builder->defaultSchedule('2026-03-01', '0', 30), 'installments');
    }
}
