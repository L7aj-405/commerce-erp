<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\ActiveTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PlatformController extends Controller
{
    public function __invoke(Request $request, ActiveTenantContext $context): Response
    {
        $user = $request->user();
        $organization = $context->organization();
        $store = $context->store();

        $organizations = Organization::query()
            ->whereHas('memberships', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->where('status', 'active'))
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

        $stores = $organization
            ? $organization->stores()
                ->where('status', 'active')
                ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->getKey()))
                ->orderBy('name')
                ->get(['id', 'organization_id', 'name', 'code', 'status'])
            : collect();

        $organizationId = $organization?->getKey();
        $storeId = $store?->getKey();
        $canViewProducts = $organization && $user->hasPermission($organization, 'products.view');
        $canViewInventory = $organization && $user->hasPermission($organization, 'inventory.view');
        $canViewSales = $organization && $store && $user->hasPermission($organization, 'sales_orders.view');
        $canViewPayments = $organization && $store && $user->hasPermission($organization, 'payments.view');
        $canViewWooStockTasks = $organization && $user->hasPermission($organization, 'integrations.woocommerce.stock_tasks.view');
        $canViewOutOfStockArticles = $organization && $user->hasPermission($organization, 'procurement.view');

        $productCount = $canViewProducts
            ? DB::table('products')->where('organization_id', $organizationId)->count()
            : null;
        $hasStockActivity = $canViewInventory
            ? DB::table('inventory_movements')->where('organization_id', $organizationId)->exists()
            : false;
        $stockedItemCount = $canViewInventory
            ? DB::table('inventory_balances')
                ->where('organization_id', $organizationId)
                ->where('on_hand', '>', 0)
                ->distinct()
                ->count('product_variant_id')
            : null;
        $salesToday = $canViewSales
            ? DB::table('sales_orders')
                ->where('organization_id', $organizationId)
                ->where('store_id', $storeId)
                ->whereDate('sale_date', today())
                ->where('status', '!=', 'cancelled')
                ->count()
            : null;
        $paymentsToReceive = $canViewPayments
            ? DB::table('sales_orders')
                ->where('organization_id', $organizationId)
                ->where('store_id', $storeId)
                ->where('status', 'confirmed')
                ->where('payment_status', '!=', 'paid')
                ->count()
            : null;
        $hasSale = $canViewSales
            ? DB::table('sales_orders')
                ->where('organization_id', $organizationId)
                ->where('store_id', $storeId)
                ->where('status', '!=', 'cancelled')
                ->exists()
            : false;
        $wooStockTasksPending = $canViewWooStockTasks
            ? DB::table('woocommerce_stock_tasks')
                ->where('organization_id', $organizationId)
                ->where('status', 'pending')
                ->count()
            : null;
        $outOfStockArticlesPending = $canViewOutOfStockArticles
            ? DB::table('out_of_stock_articles')
                ->where('organization_id', $organizationId)
                ->where('status', 'unresolved')
                ->count()
            : null;

        return Inertia::render('Platform/Index', [
            'organizations' => $organizations,
            'stores' => $stores,
            'dashboard' => [
                'product_count' => $productCount,
                'stocked_item_count' => $stockedItemCount,
                'sales_today' => $salesToday,
                'payments_to_receive' => $paymentsToReceive,
                'woo_stock_tasks_pending' => $wooStockTasksPending,
                'out_of_stock_articles_pending' => $outOfStockArticlesPending,
            ],
            'onboarding' => [
                'organization' => $organization !== null,
                'store' => $store !== null,
                'products' => ($productCount ?? 0) > 0,
                'stock' => $hasStockActivity,
                'first_sale' => $hasSale,
            ],
        ]);
    }
}
