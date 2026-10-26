<?php

namespace Tests\Feature\Attention;

use App\Actions\Attention\HandleAttentionItemAction;
use App\Models\AttentionItem;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Attention\AttentionEscalator;
use App\Services\Attention\AttentionNotifier;
use App\Services\Attention\AttentionOwnership;
use App\Services\Attention\QuotationAttentionDetector;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Work\AttentionTools;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\QuotationTestCase;

/** W6.1: an inactive store qualifies nobody for attention ownership, fallback, repair, claim or assignment. */
class AttentionOwnershipActiveStoreTest extends QuotationTestCase
{
    private const MANAGER = ['quotations.view', 'follow_ups.view', 'follow_ups.update', 'follow_ups.manage_all'];

    private int $sequence = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, Organization, Store, User} owner, organization, Store A, seller (Store A) */
    private function fixture(): array
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => 'UTC', 'allow_mcp_connections' => true];
        $organization->save();
        $store = $this->createStore($organization, $owner, 'Store A');
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);

        return [$owner, $organization->fresh(), $store, $seller];
    }

    /** An issued quotation whose validity ended: a CRITICAL attention once scanned. */
    private function expiredQuotation(User $owner, Organization $organization, Store $store, User $issuer): Quotation
    {
        $this->activate($owner, $organization, $store);
        $quotation = $this->createQuotation($owner, $organization, $store);
        DB::table('quotations')->where('id', $quotation->getKey())->update([
            'status' => 'issued', 'quotation_number' => 'DEV-'.(++$this->sequence), 'valid_until' => '2026-11-05',
            'issued_by_user_id' => $issuer->getKey(), 'created_by_user_id' => $issuer->getKey(),
        ]);

        return $quotation->fresh();
    }

    private function manager(Organization $organization, Store $store): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($organization, $user, self::MANAGER);
        $this->addStoreMember($store, $user);

        return $user;
    }

    /** The scheduled attention pipeline for one organization. */
    private function tick(Organization $organization): void
    {
        $organization = $organization->fresh();
        app(QuotationAttentionDetector::class)->scanOrganization($organization);
        app(AttentionOwnership::class)->repairOrganization($organization);
        app(AttentionNotifier::class)->notifyOrganization($organization);
        app(AttentionEscalator::class)->escalateOrganization($organization);
    }

    private function item(Quotation $quotation): AttentionItem
    {
        return AttentionItem::query()->where('quotation_id', $quotation->getKey())->orderByDesc('id')->firstOrFail();
    }

    private function deactivate(Store $store): void
    {
        DB::table('stores')->where('id', $store->getKey())->update(['status' => 'inactive']);
    }

    public function test_active_store_owner_stays_valid_and_loses_ownership_when_the_store_is_deactivated_without_fallback_or_new_notification(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        $manager = $this->manager($organization, $store);
        $quotation = $this->expiredQuotation($owner, $organization, $store, $seller);
        $this->tick($organization);

        $item = $this->item($quotation);
        $this->assertSame([(int) $seller->getKey(), 'issuer'], [(int) $item->assigned_user_id, $item->assignment_source], 'Active-store owner is valid.');
        $ownership = app(AttentionOwnership::class);
        $this->assertTrue($ownership->isValidOwner($organization, $item, (int) $seller->getKey()));
        $this->assertSame((int) $manager->getKey() < (int) $owner->getKey() ? (int) $manager->getKey() : (int) $owner->getKey(),
            $ownership->fallbackManager($organization, (int) $store->getKey()), 'An active-store manager still qualifies as fallback.');
        $notifications = UserNotification::query()->count();

        $this->deactivate($store);
        $this->assertFalse($ownership->isValidOwner($organization, $item->fresh(), (int) $seller->getKey()));
        $this->assertNull($ownership->fallbackManager($organization, (int) $store->getKey()), 'No manager qualifies through an inactive store.');
        $this->assertSame([], $ownership->eligibleUserIds($organization, (int) $store->getKey(), [(int) $seller->getKey(), (int) $manager->getKey(), (int) $owner->getKey()]));

        app(AttentionOwnership::class)->repairOrganization($organization->fresh());
        $item = $item->fresh();
        $this->assertNull($item->assigned_user_id, 'Owner released; critical item stays unassigned (nobody eligible).');
        $this->assertNull($item->assignment_source);
        $this->assertSame($notifications, UserNotification::query()->count(), 'Deactivating a store sends nothing.');
    }

    public function test_historical_items_keep_their_owner(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        $quotation = $this->expiredQuotation($owner, $organization, $store, $seller);
        $this->tick($organization);
        DB::table('attention_items')->where('quotation_id', $quotation->getKey())->update(['status' => 'resolved', 'active_key' => null, 'resolved_at' => now()]);

        $this->deactivate($store);
        app(AttentionOwnership::class)->repairOrganization($organization->fresh());
        $this->assertSame((int) $seller->getKey(), (int) $this->item($quotation)->assigned_user_id);
    }

    public function test_manual_assignment_and_claims_use_the_same_active_store_rule(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        $manager = $this->manager($organization, $store);
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $onlyInactiveB = User::factory()->create();
        $this->addOrganizationMember($organization, $onlyInactiveB, ['quotations.view', 'follow_ups.view']);
        $this->addStoreMember($storeB, $onlyInactiveB);
        $this->deactivate($storeB);
        $quotation = $this->expiredQuotation($owner, $organization, $store, $seller);
        $this->tick($organization);
        $item = $this->item($quotation);
        $actions = app(HandleAttentionItemAction::class);

        try {
            $actions->assign($manager, $organization, $item, ['assigned_user_id' => (int) $onlyInactiveB->getKey(), 'expected_version' => (int) $item->version]);
            $this->fail('A user who only belongs to an inactive store cannot own the item.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assigned_user_id', $exception->errors());
        }
        $assigned = $actions->assign($manager, $organization, $item->fresh(), ['assigned_user_id' => (int) $manager->getKey(), 'expected_version' => (int) $item->fresh()->version]);
        $this->assertSame((int) $manager->getKey(), (int) $assigned->assigned_user_id, 'Active-store manager still works.');

        // Once the item's own store is inactive nobody may act on it at all (visibility first).
        $this->deactivate($store);
        $this->expectException(AuthorizationException::class);
        app(HandleAttentionItemAction::class)->assign($manager, $organization, $item->fresh(), ['assigned_user_id' => null, 'expected_version' => (int) $item->fresh()->version]);
    }

    public function test_mcp_inherits_the_shared_rule(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        $manager = $this->manager($organization, $store);
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $onlyInactiveB = User::factory()->create();
        $this->addOrganizationMember($organization, $onlyInactiveB, ['quotations.view', 'follow_ups.view']);
        $this->addStoreMember($storeB, $onlyInactiveB);
        $this->deactivate($storeB);
        $quotation = $this->expiredQuotation($owner, $organization, $store, $seller);
        $this->tick($organization);
        $item = $this->item($quotation);

        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($manager, $organization, 'Agent', ['attention.view', 'attention.update', 'follow_ups.manage_all'], null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
        $result = app(AttentionTools::class)->assignAttention((int) $item->getKey(), (int) $item->version, (int) $onlyInactiveB->getKey())->structuredContent;
        $this->assertSame('MCP_VALIDATION_FAILED', $result['error']['code']);
        $this->assertSame((int) $seller->getKey(), (int) $item->fresh()->assigned_user_id, 'Nothing changed.');

        // Item store deactivated: the item disappears for MCP too (never revealed, never owned).
        $this->deactivate($store);
        app()->forgetScopedInstances();
        app(McpExecutionContext::class)->bind($issued['connection']->fresh()->load(['organization', 'user', 'scopeRecords']));
        $this->assertSame('MCP_ATTENTION_NOT_FOUND', app(AttentionTools::class)->claimAttention((int) $item->getKey(), (int) $item->fresh()->version)->structuredContent['error']['code']);
        $this->assertSame([], app(AttentionTools::class)->getAttention(assigned: 'all')->structuredContent['data']['items']);
    }
}
