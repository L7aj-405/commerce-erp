<?php

namespace App\Http\Controllers\Integrations;

use App\Actions\WooCommerce\CompleteWooCommerceStockTaskAction;
use App\Http\Controllers\Controller;
use App\Models\WooCommerceStockTask;
use App\Services\ActiveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WooCommerceStockTaskController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [WooCommerceStockTask::class, $organization]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['pending', 'completed', 'cancelled', 'all'])],
        ]);
        $status = $filters['status'] ?? 'pending';

        $tasks = WooCommerceStockTask::query()
            ->where('organization_id', $organization->getKey())
            ->with(['productVariant:id,label,sku,reference,product_id', 'productVariant.product:id,name', 'completedBy:id,name'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('source_reference', 'like', "%{$search}%")
                ->orWhereHas('productVariant', fn ($variant) => $variant->where('sku', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%"))
                ->orWhereHas('productVariant.product', fn ($product) => $product->where('name', 'like', "%{$search}%"))))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (WooCommerceStockTask $task) => [
                'id' => $task->id,
                'product_name' => $task->productVariant?->product?->name,
                'variant_label' => $task->productVariant?->label,
                'sku' => $task->productVariant?->sku,
                'quantity_delta' => $task->quantity_delta,
                'source_reference' => $task->source_reference,
                'reason' => $task->reason,
                'status' => $task->status->value,
                'status_label' => $task->status->label(),
                'created_at' => $task->created_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'completed_by' => $task->completedBy?->name,
            ]);

        $pendingCount = WooCommerceStockTask::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', 'pending')
            ->count();

        return Inertia::render('Settings/Integrations/WooCommerceStockTasks', [
            'tasks' => $tasks,
            'filters' => ['search' => $filters['search'] ?? null, 'status' => $status],
            'pendingCount' => $pendingCount,
            'can' => [
                'manage' => $request->user()->hasPermission($organization, 'integrations.woocommerce.stock_tasks.manage'),
            ],
        ]);
    }

    public function complete(Request $request, WooCommerceStockTask $task, CompleteWooCommerceStockTaskAction $action): RedirectResponse
    {
        $this->authorize('complete', $task);

        $action->execute($request->user(), $task);

        return back()->with('success', 'Tâche WooCommerce marquée comme mise à jour.');
    }
}
