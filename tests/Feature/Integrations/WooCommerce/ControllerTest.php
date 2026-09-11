<?php

namespace Tests\Feature\Integrations\WooCommerce;

use App\Jobs\SyncWooCommerceProductsJob;
use App\Models\User;
use App\Models\WooCommerceIntegration;
use App\Models\WooCommerceSyncRun;
use Illuminate\Support\Facades\Bus;
use Tests\Support\WooCommerceTestCase;

class ControllerTest extends WooCommerceTestCase
{
    public function test_saving_an_integration_encrypts_the_secret_and_never_stores_it_in_clear(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('integrations.woocommerce.store'), [
            'name' => 'Ma boutique',
            'store_url' => 'https://shop.example',
            'consumer_key' => 'ck_live_1',
            'consumer_secret' => 'cs_live_secret_value',
        ])->assertRedirect();

        $integration = WooCommerceIntegration::query()->firstOrFail();
        $this->assertSame('cs_live_secret_value', $integration->consumer_secret);
        $this->assertNotSame('cs_live_secret_value', $integration->getRawOriginal('consumer_secret'));
        $this->assertStringNotContainsString('cs_live_secret_value', json_encode($integration->toArray()));
    }

    public function test_updating_without_a_new_secret_keeps_the_existing_one(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $cipher = $integration->getRawOriginal('consumer_secret');

        $this->actingAs($owner)->patch(route('integrations.woocommerce.update', $integration), [
            'name' => 'Renommée',
            'store_url' => 'https://shop.test',
            'consumer_key' => 'ck_test_key',
            'consumer_secret' => '',
        ])->assertRedirect();

        $integration->refresh();
        $this->assertSame('Renommée', $integration->name);
        $this->assertSame($cipher, $integration->getRawOriginal('consumer_secret'));
    }

    public function test_sync_dispatches_the_queued_job(): void
    {
        Bus::fake();
        [$owner, , , , $integration] = $this->wooContext();

        $this->actingAs($owner)
            ->post(route('integrations.woocommerce.sync', $integration), ['mode' => 'full'])
            ->assertRedirect();

        Bus::assertDispatched(SyncWooCommerceProductsJob::class, fn ($job) => $job->integrationId === $integration->getKey()
            && $job->actorId === $owner->getKey()
            && $job->mode === 'full');
    }

    public function test_sync_is_rejected_while_a_run_is_already_in_progress(): void
    {
        Bus::fake();
        [$owner, $organization, , , $integration] = $this->wooContext();

        $run = new WooCommerceSyncRun;
        $run->organization_id = $organization->getKey();
        $run->woocommerce_integration_id = $integration->getKey();
        $run->status = WooCommerceSyncRun::STATUS_RUNNING;
        $run->started_at = now();
        $run->save();

        $this->actingAs($owner)
            ->post(route('integrations.woocommerce.sync', $integration), ['mode' => 'full'])
            ->assertSessionHasErrors('sync');

        Bus::assertNotDispatched(SyncWooCommerceProductsJob::class);
    }

    public function test_sync_requires_a_target_warehouse_when_stock_sync_is_enabled(): void
    {
        Bus::fake();
        [$owner, $organization, , , $integration] = $this->wooContext();
        $integration->forceFill(['sync_stock' => true, 'default_warehouse_id' => null])->save();

        $this->actingAs($owner)
            ->post(route('integrations.woocommerce.sync', $integration), ['mode' => 'full'])
            ->assertSessionHasErrors('sync');

        Bus::assertNotDispatched(SyncWooCommerceProductsJob::class);
    }

    public function test_a_pos_level_member_cannot_open_the_integration_settings_page(): void
    {
        [, $organization] = $this->wooContext();
        $member = User::factory()->create();
        $this->addOrganizationMember($organization, $member, ['pos.access']);
        $this->activate($member, $organization);

        $this->actingAs($member)
            ->get(route('integrations.woocommerce.index'))
            ->assertForbidden();
    }
}
