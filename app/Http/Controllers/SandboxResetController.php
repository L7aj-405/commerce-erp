<?php

namespace App\Http\Controllers;

use App\Models\Sandbox;
use App\Services\ActiveTenantContext;
use App\Services\Sandbox\SandboxContext;
use App\Services\Sandbox\SandboxResetException;
use App\Services\Sandbox\SandboxResetExecutor;
use App\Services\Sandbox\SandboxResetPlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9 — dedicated Sandbox reset flow (preview → confirm → reset).
 * The target is always the active organization's own sandbox, resolved
 * server-side: no sandbox or organization id is accepted from the request,
 * so another tenant's sandbox can never be addressed. Requires sandbox.manage.
 */
class SandboxResetController extends Controller
{
    public function show(Request $request, ActiveTenantContext $tenant, SandboxResetPlanner $planner): Response
    {
        $sandbox = $this->sandbox($request, $tenant);

        return Inertia::render('Sandbox/Reset', [
            'preview' => $planner->preview($sandbox),
            'phrase' => SandboxResetExecutor::PHRASE,
        ]);
    }

    public function execute(Request $request, ActiveTenantContext $tenant, SandboxResetExecutor $executor): RedirectResponse
    {
        $organization = $tenant->organizationOrFail();
        $sandbox = $this->sandbox($request, $tenant);
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:64'],
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[0-9a-f]{64}\z/'],
            'sandbox_id' => ['prohibited'],
            'organization_id' => ['prohibited'],
        ]);
        if (! hash_equals(SandboxResetExecutor::PHRASE, $data['confirmation'])) {
            return back()->withErrors(['confirmation' => 'Saisissez exactement « '.SandboxResetExecutor::PHRASE.' » pour confirmer.']);
        }

        try {
            $result = $executor->execute($organization, $sandbox, $request->user(), $data['fingerprint']);
        } catch (SandboxResetException $e) {
            abort_if($e->reason === 'forbidden', 404);

            return back()->withErrors(['reset' => $e->getMessage()]);
        }

        // The acting session follows its sandbox into the new generation; every
        // other Test session still holds the old generation and stops resolving.
        $selection = $request->session()->get(SandboxContext::SESSION_KEY);
        if (is_array($selection) && (int) ($selection['id'] ?? 0) === (int) $sandbox->getKey()
            && (int) ($selection['organization_id'] ?? 0) === (int) $organization->getKey()) {
            $request->session()->put(SandboxContext::SESSION_KEY, [...$selection, 'generation' => $result['generation']]);
        }

        return redirect('/test-mode')->with('success', "Mode test réinitialisé : {$result['total']} enregistrement(s) test supprimé(s). Génération {$result['generation']}. Aucune donnée de Production n’a été modifiée.");
    }

    private function sandbox(Request $request, ActiveTenantContext $tenant): Sandbox
    {
        $organization = $tenant->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'sandbox.manage'), 403);

        return Sandbox::query()->where('organization_id', $organization->getKey())->firstOr(fn () => abort(404));
    }
}
