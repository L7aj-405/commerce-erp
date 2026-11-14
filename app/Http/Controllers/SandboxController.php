<?php
namespace App\Http\Controllers;
use App\Models\Sandbox;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Sandbox\SandboxContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
class SandboxController extends Controller
{
    public function show(Request $request, ActiveTenantContext $tenant, SandboxContext $context)
    {
        $org = $tenant->organizationOrFail();
        abort_unless($context->isRestricted() || $request->user()->hasPermission($org, 'sandbox.access'), 403);
        $catalog = app(\App\Services\Sandbox\SandboxModuleCatalog::class);
        $sandbox = $context->sandbox();

        return Inertia::render('Sandbox/Index', [
            'current' => $sandbox ? ['name' => $sandbox->name, 'status' => $sandbox->status, 'generation' => $context->generation(),
                // Phase 10: Test stock snapshot state (taken from Production on first Test use of a generation).
                'stockInitializedAt' => $sandbox->inventory_initialized_at?->toIso8601String()] : null,
            // Phase 10: the session was left on an old generation by a reset — explicit re-entry required.
            'stale' => $context->isStale() || $request->session()->has('sandbox_stale'),
            'modules' => $sandbox ? $catalog->available($request->user(), $org) : [],
            'unavailable' => $catalog->unavailable(),
            'enabled' => (bool) (($org->settings ?? [])['sandbox_enabled'] ?? false),
            'canManage' => $request->user()->hasPermission($org, 'sandbox.manage'),
            'canSwitch' => $request->user()->hasPermission($org, 'sandbox.switch'),
            // Phase 9: the dedicated reset flow targets this organization's own sandbox.
            'canReset' => $request->user()->hasPermission($org, 'sandbox.manage')
                && Sandbox::query()->where('organization_id', $org->id)->where('status', 'active')->exists(),
        ]);
    }
    public function enter(Request $request, ActiveTenantContext $tenant, AuditLogger $audit)
    {
        $org = $tenant->organizationOrFail();
        $user = $request->user();
        abort_unless($user->hasPermission($org, 'sandbox.access') && $user->hasPermission($org, 'sandbox.switch'), 403);
        $sandbox = DB::transaction(function () use ($org, $user, $audit) {
            $locked = $org->newQuery()->whereKey($org->id)->lockForUpdate()->firstOrFail();
            abort_unless(($locked->settings ?? [])['sandbox_enabled'] ?? false, 403);
            $sandbox = Sandbox::query()->where('organization_id', $org->id)->first();
            if (!$sandbox) {
                $sandbox = new Sandbox;
                $sandbox->organization_id = $org->id;
                $sandbox->name = 'Test';
                $sandbox->status = 'active';
                $sandbox->generation = 1;
                $sandbox->created_by_user_id = $user->id;
                $sandbox->save();
                $audit->record('sandbox.created', $user, $org, auditable: $sandbox);
            }
            abort_unless($sandbox->status === 'active', 403);
            $audit->record('sandbox.entered', $user, $org, auditable: $sandbox, newValues: ['sandbox_id' => (int) $sandbox->id, 'generation' => (int) $sandbox->generation]);
            return $sandbox;
        });
        // Phase 4: explicit Test stock snapshot when Test Mode starts (idempotent).
        app(\App\Services\Sandbox\SandboxInventoryInitializer::class)->ensure($sandbox);
        $request->session()->put(SandboxContext::SESSION_KEY, ['id' => $sandbox->id, 'organization_id' => $org->id, 'user_id' => $user->id, 'generation' => $sandbox->generation]);
        return redirect('/test-mode');
    }
    public function leave(Request $request, ActiveTenantContext $tenant, AuditLogger $audit)
    {
        if ($request->session()->has(SandboxContext::SESSION_KEY)) {
            $selection = (array) $request->session()->get(SandboxContext::SESSION_KEY);
            $audit->record('sandbox.left', $request->user(), $tenant->organization(),
                auditable: isset($selection['id']) ? Sandbox::query()->find($selection['id']) : null,
                oldValues: ['sandbox_id' => $selection['id'] ?? null, 'generation' => $selection['generation'] ?? null]);
            $request->session()->forget(SandboxContext::SESSION_KEY);
        }
        return redirect('/platform');
    }
    public function policy(Request $request, ActiveTenantContext $tenant, AuditLogger $audit)
    {
        $org = $tenant->organizationOrFail();
        abort_unless($request->user()->hasPermission($org, 'sandbox.manage'), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        DB::transaction(function () use ($org, $request, $data, $audit) {
            $locked = $org->newQuery()->whereKey($org->id)->lockForUpdate()->firstOrFail();
            $settings = $locked->settings ?? [];
            $settings['sandbox_enabled'] = (bool) $data['enabled'];
            $locked->settings = $settings;
            $locked->save();
            $audit->record('sandbox.policy_changed', $request->user(), $locked, newValues: ['enabled' => $settings['sandbox_enabled']]);
        });
        return redirect('/test-mode');
    }
}
