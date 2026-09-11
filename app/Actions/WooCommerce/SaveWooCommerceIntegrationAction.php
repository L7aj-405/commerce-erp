<?php

namespace App\Actions\WooCommerce;

use App\Models\Organization;
use App\Models\User;
use App\Models\WooCommerceIntegration;
use App\Services\AuditLogger;

/**
 * Creates or updates a WooCommerce integration. The consumer secret is only
 * written when a fresh non-empty value is supplied; it is stored via the model's
 * `encrypted` cast and never returned to the client or written to the audit log.
 */
class SaveWooCommerceIntegrationAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, array $data, ?WooCommerceIntegration $integration = null): WooCommerceIntegration
    {
        $creating = $integration === null;
        $integration ??= new WooCommerceIntegration;
        $integration->organization_id = $organization->getKey();

        $secretChanged = false;
        foreach ([
            'name', 'store_url', 'consumer_key', 'default_warehouse_id', 'default_store_id',
            'sync_stock', 'prices_include_tax', 'brand_source', 'brand_taxonomy',
            'brand_attribute_name', 'brand_meta_key', 'reference_meta_key',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $integration->{$field} = $data[$field];
            }
        }

        if (filled($data['consumer_secret'] ?? null)) {
            $integration->consumer_secret = trim((string) $data['consumer_secret']);
            $secretChanged = true;
        }

        $integration->save();

        $this->audit->record(
            $creating ? 'woocommerce.integration_created' : 'woocommerce.integration_updated',
            $actor, $organization, auditable: $integration,
            newValues: [
                'name' => $integration->name,
                'store_url' => $integration->store_url,
                'sync_stock' => $integration->sync_stock,
                'default_warehouse_id' => $integration->default_warehouse_id,
                'secret_replaced' => $secretChanged,
            ],
        );

        return $integration;
    }
}
