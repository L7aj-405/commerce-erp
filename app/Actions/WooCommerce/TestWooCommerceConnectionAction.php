<?php

namespace App\Actions\WooCommerce;

use App\Models\User;
use App\Models\WooCommerceIntegration;
use App\Services\AuditLogger;
use App\Services\WooCommerce\WooCommerceApiException;
use App\Services\WooCommerce\WooCommerceClient;

class TestWooCommerceConnectionAction
{
    private ?WooCommerceClient $clientOverride = null;

    public function __construct(private readonly AuditLogger $audit) {}

    public function usingClient(WooCommerceClient $client): self
    {
        $this->clientOverride = $client;

        return $this;
    }

    /** @return array{ok: bool, message: string} */
    public function execute(User $actor, WooCommerceIntegration $integration): array
    {
        $client = $this->clientOverride ?? WooCommerceClient::for($integration);

        try {
            $client->ping();
            $result = ['ok' => true, 'message' => 'Connexion réussie.'];
        } catch (WooCommerceApiException $exception) {
            $result = ['ok' => false, 'message' => $exception->userMessage()];
        }

        $integration->forceFill([
            'last_connection_check_at' => now(),
            'last_connection_ok' => $result['ok'],
        ])->save();

        $this->audit->record('woocommerce.connection_tested', $actor, $integration->organization, auditable: $integration, newValues: [
            'ok' => $result['ok'],
        ]);

        return $result;
    }
}
