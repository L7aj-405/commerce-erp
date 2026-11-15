<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\ManualStockAdjustmentAction;
use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Support\InventoryTestCase;

/** Set / Add / Remove stock correction (web dialog + shared domain action). */
class ManualStockAdjustmentTest extends InventoryTestCase
{
    private User $owner;

    private $organization;

    private $warehouse;

    private $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->warehouse = $this->createWarehouse($this->organization);
        $this->variant = $this->createProduct($this->organization)->variants->first();
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->variant, '12.0000');
        $this->activate($this->owner, $this->organization);
    }

    public function test_set_computes_the_delta_down_and_up(): void
    {
        $down = $this->apply('set', '8', '12.0000');
        $this->assertSame(InventoryMovementType::AdjustmentOut, $down->movement_type);
        $this->assertSame('-4.0000', $down->quantity);
        $this->assertSame('8.0000', $this->onHand());

        $up = $this->apply('set', '20', '8.0000');
        $this->assertSame(InventoryMovementType::AdjustmentIn, $up->movement_type);
        $this->assertSame('12.0000', $up->quantity);
        $this->assertSame('20.0000', $this->onHand());
    }

    public function test_increase_and_decrease(): void
    {
        $this->apply('increase', '5', '12.0000');
        $this->assertSame('17.0000', $this->onHand());
        $this->apply('decrease', '3', '17.0000');
        $this->assertSame('14.0000', $this->onHand());
    }

    public function test_invalid_results_are_refused_without_a_movement(): void
    {
        $before = InventoryMovement::query()->count();
        foreach ([['decrease', '13'], ['set', '-1'], ['set', '12'], ['increase', '0']] as [$operation, $quantity]) {
            try {
                $this->apply($operation, $quantity, '12.0000');
                $this->fail("{$operation} {$quantity} should be refused");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame($before, InventoryMovement::query()->count());
        $this->assertSame('12.0000', $this->onHand());
    }

    public function test_reserved_stock_cannot_be_removed_by_set_or_decrease(): void
    {
        $this->reserve($this->owner, $this->organization, $this->warehouse, $this->variant, '5.0000');

        $plan = app(ManualStockAdjustmentAction::class)->plan('12.0000', '5.0000', 'set', '4');
        $this->assertSame('exceeds_available', $plan['issues'][0]['code']);
        $this->expectException(ValidationException::class);
        $this->apply('decrease', '8', '12.0000');
    }

    public function test_one_movement_is_created_and_a_double_submit_is_refused_as_stale(): void
    {
        $before = InventoryMovement::query()->count();
        $this->apply('increase', '5', '12.0000');
        $this->assertSame($before + 1, InventoryMovement::query()->count());

        try {
            $this->apply('increase', '5', '12.0000'); // same screen submitted twice
            $this->fail('A stale submit must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_on_hand', $exception->errors());
        }
        $this->assertSame($before + 1, InventoryMovement::query()->count());
        $this->assertSame('17.0000', $this->onHand());
    }

    public function test_preview_is_read_only_and_reports_before_after(): void
    {
        $movements = InventoryMovement::query()->count();
        $preview = app(ManualStockAdjustmentAction::class)->preview($this->owner, $this->organization, $this->warehouse, $this->variant, 'set', '8', 'Inventaire physique');

        $this->assertTrue($preview['valid']);
        $this->assertSame('12.0000', $preview['on_hand_before']);
        $this->assertSame('-4.0000', $preview['delta']);
        $this->assertSame('8.0000', $preview['on_hand_after']);
        $this->assertSame($movements, InventoryMovement::query()->count());
        $this->assertSame('12.0000', $this->onHand());
    }

    public function test_http_preview_and_apply_enforce_permission_and_organization(): void
    {
        $payload = ['warehouse_id' => $this->warehouse->id, 'product_variant_id' => $this->variant->id, 'operation' => 'set', 'quantity' => '8'];

        $this->actingAs($this->owner)->postJson(route('inventory.adjustments.preview'), $payload)
            ->assertOk()->assertJsonPath('delta', '-4.0000')->assertJsonPath('on_hand_after', '8.0000');
        $this->assertSame('12.0000', $this->onHand());

        $this->actingAs($this->owner)->post(route('inventory.adjustments.store'), $payload + ['reason' => 'Inventaire physique', 'expected_on_hand' => '12.0000'])->assertRedirect();
        $this->assertSame('8.0000', $this->onHand());

        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->organization, $viewer, ['inventory.view']);
        $this->activate($viewer, $this->organization);
        $this->actingAs($viewer)->postJson(route('inventory.adjustments.preview'), $payload)->assertForbidden();
        $this->actingAs($viewer)->post(route('inventory.adjustments.store'), $payload + ['reason' => 'x', 'expected_on_hand' => '8.0000'])->assertForbidden();

        $strangerOwner = User::factory()->create();
        $other = $this->createOrganization($strangerOwner, 'Other Org');
        $otherWarehouse = $this->createWarehouse($other, 'Other WH');
        $this->activate($strangerOwner, $other);
        $this->actingAs($strangerOwner)->postJson(route('inventory.adjustments.preview'), [...$payload, 'warehouse_id' => $this->warehouse->id])->assertUnprocessable();
        $this->actingAs($strangerOwner)->postJson(route('inventory.adjustments.preview'), [...$payload, 'warehouse_id' => $otherWarehouse->id])->assertUnprocessable();
        $this->assertSame('8.0000', $this->onHand());
    }

    private function apply(string $operation, string $quantity, string $expected): InventoryMovement
    {
        return app(ManualStockAdjustmentAction::class)->execute($this->owner, $this->organization, $this->warehouse, $this->variant, $operation, $quantity, 'Correction', 'REF-1', $expected);
    }

    private function onHand(): string
    {
        return $this->balance($this->organization, $this->warehouse, $this->variant)->fresh()->on_hand;
    }
}
