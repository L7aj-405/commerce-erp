<?php

namespace Tests\Feature\Mcp;

use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Mcp\Inventory\StockAdjustmentTools;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use Tests\Support\InventoryTestCase;

/** preview_stock_adjustment / apply_stock_adjustment. */
class McpStockAdjustmentToolsTest extends InventoryTestCase
{
    private const SCOPES = ['inventory.view', 'inventory.adjust'];

    private User $owner;

    private Organization $organization;

    private Warehouse $warehouse;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->organization->settings = ['allow_mcp_connections' => true];
        $this->organization->save();
        $this->organization->refresh();
        $this->warehouse = $this->createWarehouse($this->organization);
        $this->variant = $this->createProduct($this->organization)->variants->first();
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->variant, '12.0000');
        $this->activate($this->owner, $this->organization);
        $this->connect($this->owner);
    }

    public function test_preview_reports_the_delta_and_never_mutates(): void
    {
        $movements = InventoryMovement::query()->count();
        $audits = AuditLog::query()->where('event', 'inventory.adjusted')->count();

        $preview = $this->tool('previewStockAdjustment', $this->args(['operation' => 'set', 'quantity' => '8']));

        $this->assertTrue($preview['ok']);
        $data = $preview['data'];
        $this->assertTrue($data['valid']);
        $this->assertSame('12.0000', $data['on_hand_before']);
        $this->assertSame('-4.0000', $data['delta']);
        $this->assertSame('8.0000', $data['on_hand_after']);
        $this->assertSame('production', $data['environment']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['preview_fingerprint']);
        $this->assertSame($movements, InventoryMovement::query()->count());
        $this->assertSame($audits, AuditLog::query()->where('event', 'inventory.adjusted')->count());
        $this->assertSame('12.0000', $this->onHand());
    }

    public function test_invalid_preview_has_issues_and_no_fingerprint(): void
    {
        $data = $this->tool('previewStockAdjustment', $this->args(['operation' => 'decrease', 'quantity' => '13']))['data'];

        $this->assertFalse($data['valid']);
        $this->assertNull($data['preview_fingerprint']);
        $this->assertSame('negative_result', $data['issues'][0]['code']);
    }

    public function test_apply_creates_exactly_one_movement_and_a_retry_never_duplicates(): void
    {
        $fingerprint = $this->fingerprint(['operation' => 'set', 'quantity' => '8']);
        $arguments = $this->args(['operation' => 'set', 'quantity' => '8', 'preview_fingerprint' => $fingerprint, 'idempotency_key' => 'adj-1']);
        $before = InventoryMovement::query()->count();

        $first = $this->tool('applyStockAdjustment', $arguments);
        $retry = $this->tool('applyStockAdjustment', $arguments);

        $this->assertTrue($first['ok']);
        $this->assertSame('12.0000', $first['data']['on_hand_before']);
        $this->assertSame('8.0000', $first['data']['on_hand_after']);
        $this->assertSame('-4.0000', $first['data']['movement']['quantity']);
        $this->assertFalse($first['data']['idempotent_replay']);
        $this->assertTrue($retry['data']['idempotent_replay']);
        $this->assertSame($first['data']['movement']['id'], $retry['data']['movement']['id']);
        $this->assertSame($before + 1, InventoryMovement::query()->count());
        $this->assertSame('8.0000', $this->onHand());
        $this->assertSame(1, AuditLog::query()->where('event', 'inventory.adjusted')->count());
    }

    public function test_same_fingerprint_with_another_key_is_refused_and_key_reuse_with_other_payload_conflicts(): void
    {
        $fingerprint = $this->fingerprint(['operation' => 'increase', 'quantity' => '5']);
        $arguments = $this->args(['operation' => 'increase', 'quantity' => '5', 'preview_fingerprint' => $fingerprint, 'idempotency_key' => 'k1']);
        $this->assertTrue($this->tool('applyStockAdjustment', $arguments)['ok']);

        $this->assertSame('MCP_PREVIEW_STALE', $this->tool('applyStockAdjustment', [...$arguments, 'idempotency_key' => 'k2'])['error']['code']);
        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $this->tool('applyStockAdjustment', [...$arguments, 'quantity' => '6'])['error']['code']);
        $this->assertSame('17.0000', $this->onHand());
    }

    public function test_stale_preview_is_refused_when_stock_changed(): void
    {
        $fingerprint = $this->fingerprint(['operation' => 'set', 'quantity' => '8']);
        app(\App\Actions\Inventory\AdjustInventoryAction::class)->execute($this->owner, $this->organization, $this->warehouse, $this->variant, \App\Enums\InventoryMovementType::AdjustmentIn, '1.0000', 'Concurrent change');
        $before = InventoryMovement::query()->count();

        $result = $this->tool('applyStockAdjustment', $this->args(['operation' => 'set', 'quantity' => '8', 'preview_fingerprint' => $fingerprint, 'idempotency_key' => 'stale-1']));

        $this->assertSame('MCP_PREVIEW_STALE', $result['error']['code']);
        $this->assertSame($before, InventoryMovement::query()->count());
        $this->assertSame('13.0000', $this->onHand());
    }

    public function test_apply_without_a_preview_or_with_a_forged_one_is_refused(): void
    {
        $result = $this->tool('applyStockAdjustment', $this->args(['operation' => 'increase', 'quantity' => '1', 'preview_fingerprint' => str_repeat('a', 64), 'idempotency_key' => 'forged']));

        $this->assertSame('MCP_PREVIEW_STALE', $result['error']['code']);
        $this->assertSame('12.0000', $this->onHand());
    }

    public function test_permission_scope_and_organization_are_enforced(): void
    {
        // Connection without the adjust scope.
        $this->connect($this->owner, ['inventory.view']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('previewStockAdjustment', $this->args(['operation' => 'set', 'quantity' => '8']))['error']['code']);

        // Scope granted but the ERP permission is only inventory.view (cannot even hold the scope; revoke at call time).
        $staff = User::factory()->create();
        $this->addOrganizationMember($this->organization, $staff, ['inventory.view', 'inventory.adjust']);
        $this->activate($staff, $this->organization);
        $this->connect($staff);
        $this->assertTrue($this->tool('previewStockAdjustment', $this->args(['operation' => 'set', 'quantity' => '8']))['ok']);
        $this->organization->memberships()->where('user_id', $staff->id)->update(['status' => 'suspended']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('previewStockAdjustment', $this->args(['operation' => 'set', 'quantity' => '8']))['error']['code']);

        // A foreign organization's warehouse or variant is "not found".
        $this->connect($this->owner);
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Foreign Org');
        $foreignWarehouse = $this->createWarehouse($foreign, 'Foreign WH');
        $foreignVariant = $this->createProduct($foreign)->variants->first();
        $this->assertSame('MCP_STOCK_TARGET_NOT_FOUND', $this->tool('previewStockAdjustment', $this->args(['operation' => 'set', 'quantity' => '8', 'warehouse_id' => $foreignWarehouse->id]))['error']['code']);
        $this->assertSame('MCP_STOCK_TARGET_NOT_FOUND', $this->tool('previewStockAdjustment', $this->args(['operation' => 'set', 'quantity' => '8', 'variant_id' => $foreignVariant->id]))['error']['code']);
    }

    public function test_mcp_and_web_share_the_same_domain_logic(): void
    {
        $this->assertSame(
            \App\Actions\Inventory\ManualStockAdjustmentAction::class,
            (new \ReflectionProperty(\App\Services\Mcp\Inventory\McpStockAdjustmentService::class, 'adjustment'))->getType()->getName(),
        );
        $web = app(\App\Actions\Inventory\ManualStockAdjustmentAction::class)->plan('12.0000', '0.0000', 'set', '8');
        $mcp = $this->tool('previewStockAdjustment', $this->args(['operation' => 'set', 'quantity' => '8']))['data'];

        $this->assertSame($web['delta'], $mcp['delta']);
        $this->assertSame($web['on_hand_after'], $mcp['on_hand_after']);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function args(array $overrides = []): array
    {
        return ['variant_id' => $this->variant->id, 'warehouse_id' => $this->warehouse->id, 'reason' => 'Inventaire physique', ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function fingerprint(array $overrides): string
    {
        return $this->tool('previewStockAdjustment', $this->args($overrides))['data']['preview_fingerprint'];
    }

    private function onHand(): string
    {
        return $this->balance($this->organization, $this->warehouse, $this->variant)->fresh()->on_hand;
    }

    /** @param list<string> $scopes */
    private function connect(User $user, array $scopes = self::SCOPES): void
    {
        app()->forgetScopedInstances();
        app(McpExecutionContext::class)->reset();
        $issued = app(McpConnectionCredentials::class)->issue($user, $this->organization, 'Agent', $scopes, null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function tool(string $method, array $arguments): array
    {
        return app(StockAdjustmentTools::class)->{$method}(...$arguments)->structuredContent;
    }
}
