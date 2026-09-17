<?php

namespace App\Http\Controllers\Integrations;

use App\Actions\WooCommerce\SaveWooCommerceIntegrationAction;
use App\Actions\WooCommerce\TestWooCommerceConnectionAction;
use App\Exceptions\Security\UnsafeOutboundDestinationException;
use App\Http\Controllers\Controller;
use App\Jobs\SyncWooCommerceProductsJob;
use App\Models\Organization;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WooCommerceIntegration;
use App\Models\WooCommerceSyncRun;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Security\OutboundDestinationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WooCommerceIntegrationController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [WooCommerceIntegration::class, $organization]);

        $integration = WooCommerceIntegration::query()
            ->where('organization_id', $organization->getKey())
            ->orderBy('id')
            ->first();

        $runs = $integration
            ? WooCommerceSyncRun::query()
                ->where('woocommerce_integration_id', $integration->getKey())
                ->latest('id')->limit(10)->get([
                    'id', 'type', 'mode', 'status', 'started_at', 'completed_at',
                    'products_read', 'products_created', 'products_updated', 'products_skipped',
                    'products_failed', 'variants_synced', 'categories_synced', 'stock_adjustments', 'message', 'errors',
                ])
            : collect();

        return Inertia::render('Settings/Integrations/WooCommerce', [
            'integration' => $integration ? [
                ...$integration->only([
                    'id', 'name', 'store_url', 'consumer_key', 'default_warehouse_id', 'default_store_id',
                    'sync_stock', 'prices_include_tax', 'brand_source', 'brand_taxonomy',
                    'brand_attribute_name', 'brand_meta_key', 'reference_meta_key',
                    'last_connection_ok', 'synced_product_count',
                ]),
                'last_connection_check_at' => $integration->last_connection_check_at?->toIso8601String(),
                'last_product_sync_completed_at' => $integration->last_product_sync_completed_at?->toIso8601String(),
                'has_secret' => filled($integration->getRawOriginal('consumer_secret')),
            ] : null,
            'runs' => $runs,
            'warehouses' => Warehouse::query()->where('organization_id', $organization->getKey())->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'stores' => $organization->stores()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'taxRates' => TaxRate::query()->where('organization_id', $organization->getKey())->orderBy('name')->get(['id', 'name', 'rate', 'is_default']),
            'can' => [
                'manage' => $request->user()->hasPermission($organization, 'integrations.manage'),
                'sync' => $request->user()->hasPermission($organization, 'integrations.sync'),
                'syncStock' => $request->user()->hasPermission($organization, 'inventory.adjust')
                    || $request->user()->hasPermission($organization, 'inventory.opening'),
            ],
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, SaveWooCommerceIntegrationAction $action, OutboundDestinationGuard $guard, AuditLogger $audit): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [WooCommerceIntegration::class, $organization]);

        $data = $this->validated($request, $organization->getKey(), creating: true);
        $this->assertSafeStoreUrl($data['store_url'], $guard, $audit, $request->user(), $organization);
        $action->execute($request->user(), $organization, $data);

        return redirect()->route('integrations.woocommerce.index')->with('success', 'Intégration WooCommerce enregistrée.');
    }

    public function update(Request $request, ActiveTenantContext $context, WooCommerceIntegration $integration, SaveWooCommerceIntegrationAction $action, OutboundDestinationGuard $guard, AuditLogger $audit): RedirectResponse
    {
        $context->organizationOrFail();
        $this->authorize('update', $integration);

        $data = $this->validated($request, $integration->organization_id, creating: false);
        $this->assertSafeStoreUrl($data['store_url'], $guard, $audit, $request->user(), $integration->organization);
        $action->execute($request->user(), $integration->organization, $data, $integration);

        return back()->with('success', 'Intégration WooCommerce mise à jour.');
    }

    /**
     * SSRF guard at save-time (fail fast with a friendly field error). The
     * WooCommerceClient re-validates again at request-time — see its class
     * doc — since DNS can change between saving and using the integration.
     */
    private function assertSafeStoreUrl(string $storeUrl, OutboundDestinationGuard $guard, AuditLogger $audit, User $actor, Organization $organization): void
    {
        try {
            $guard->assertPublicUrl($storeUrl, ['https'], 'WooCommerce');
        } catch (UnsafeOutboundDestinationException $exception) {
            $audit->record('woocommerce.destination_rejected', $actor, $organization, newValues: [
                'category' => $exception->category,
            ]);

            throw ValidationException::withMessages(['store_url' => $exception->userMessage()]);
        }
    }

    public function test(Request $request, ActiveTenantContext $context, WooCommerceIntegration $integration, TestWooCommerceConnectionAction $action): JsonResponse
    {
        $context->organizationOrFail();
        $this->authorize('update', $integration);

        return response()->json($action->execute($request->user(), $integration));
    }

    public function sync(Request $request, ActiveTenantContext $context, WooCommerceIntegration $integration): RedirectResponse
    {
        $context->organizationOrFail();
        $this->authorize('sync', $integration);

        foreach (['products.view', 'products.create', 'products.update'] as $permission) {
            abort_unless($request->user()->hasPermission($integration->organization_id, $permission), 403);
        }

        $data = $request->validate(['mode' => ['nullable', 'in:full,incremental']]);

        if ($integration->sync_stock) {
            abort_unless(
                $request->user()->hasPermission($integration->organization_id, 'inventory.adjust')
                || $request->user()->hasPermission($integration->organization_id, 'inventory.opening'),
                403,
            );
            if (! $integration->default_warehouse_id) {
                throw ValidationException::withMessages(['sync' => 'Sélectionnez un entrepôt cible avant de synchroniser le stock.']);
            }
        }

        $running = WooCommerceSyncRun::query()
            ->where('woocommerce_integration_id', $integration->getKey())
            ->where('status', WooCommerceSyncRun::STATUS_RUNNING)
            ->exists();
        if ($running) {
            throw ValidationException::withMessages(['sync' => 'Une synchronisation est déjà en cours.']);
        }

        SyncWooCommerceProductsJob::dispatch($integration->getKey(), $request->user()->getKey(), $data['mode'] ?? 'full');

        return back()->with('success', 'Synchronisation WooCommerce lancée.');
    }

    public function runStatus(ActiveTenantContext $context, WooCommerceIntegration $integration): JsonResponse
    {
        $context->organizationOrFail();
        $this->authorize('view', $integration);

        $run = WooCommerceSyncRun::query()
            ->where('woocommerce_integration_id', $integration->getKey())
            ->latest('id')
            ->first([
                'id', 'mode', 'status', 'started_at', 'completed_at',
                'products_read', 'products_created', 'products_updated', 'products_skipped',
                'products_failed', 'variants_synced', 'categories_synced', 'stock_adjustments', 'message', 'errors',
            ]);

        return response()->json([
            'run' => $run,
            'synced_product_count' => $integration->synced_product_count,
            'last_product_sync_completed_at' => $integration->last_product_sync_completed_at?->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $organizationId, bool $creating): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'store_url' => ['required', 'url:https', 'max:2048'],
            'consumer_key' => ['required', 'string', 'max:255'],
            'consumer_secret' => [$creating ? 'required' : 'nullable', 'string', 'max:512'],
            'default_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)],
            'default_store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')->where('organization_id', $organizationId)],
            'sync_stock' => ['boolean'],
            'prices_include_tax' => ['nullable', 'boolean'],
            'brand_source' => ['nullable', 'in:taxonomy,attribute,meta'],
            'brand_taxonomy' => ['nullable', 'string', 'max:64'],
            'brand_attribute_name' => ['nullable', 'string', 'max:128'],
            'brand_meta_key' => ['nullable', 'string', 'max:128'],
            'reference_meta_key' => ['nullable', 'string', 'max:128'],
        ]);
    }
}
