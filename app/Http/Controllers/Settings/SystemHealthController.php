<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemHealthController extends Controller
{
    public function __invoke(Request $request, ActiveTenantContext $context, SystemHealthService $health): Response|JsonResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'system.health.view'), 403);
        $canManage = $request->user()->hasPermission($organization, 'system.health.manage');
        $snapshot = $health->snapshot($organization, $canManage);

        if ($request->expectsJson()) return response()->json($snapshot);

        return Inertia::render('SystemHealth/Index', [
            'snapshot' => $snapshot,
            'pollSeconds' => max(15, min(60, (int) config('system-health.poll_seconds', 20))),
            'canManage' => $canManage,
        ]);
    }
}
