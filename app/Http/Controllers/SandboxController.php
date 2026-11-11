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
        return Inertia::render('Sandbox/Index', [
            'enabled' => (bool) (($org->settings ?? [])['sandbox_enabled'] ?? false),
            'canManage' => $request->user()->hasPermission($org, 'sandbox.manage'),
            'canSwitch' => $request->user()->hasPermission($org, 'sandbox.switch'),
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
            $audit->record('sandbox.entered', $user, $org, auditable: $sandbox);
            return $sandbox;
        });
        $request->session()->put(SandboxContext::SESSION_KEY, ['id' => $sandbox->id, 'organization_id' => $org->id, 'user_id' => $user->id, 'generation' => $sandbox->generation]);
        return redirect('/test-mode');
    }
    public function leave(Request $request, ActiveTenantContext $tenant, AuditLogger $audit)
    {
        if ($request->session()->has(SandboxContext::SESSION_KEY)) {
            $audit->record('sandbox.left', $request->user(), $tenant->organization());
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
