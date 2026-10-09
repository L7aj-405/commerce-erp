<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CommissionRuleSet;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Commissions\CommissionRuleManager;
use App\Services\Commissions\CommissionSimulationService;
use App\Services\Security\FreshAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionRuleController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorizePermission($request, $organization, 'commissions.rules.view');

        return $this->page($request, $organization);
    }

    public function store(Request $request, ActiveTenantContext $context, CommissionRuleManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorizePermission($request, $organization, 'commissions.rules.manage');
        $data = $this->validatedRule($request);
        $manager->create($request->user(), $organization, $data, $data['tiers']);

        return back()->with('success', 'Règle de commission créée en brouillon.');
    }

    public function update(Request $request, ActiveTenantContext $context, CommissionRuleSet $commissionRule, CommissionRuleManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->assertTenant($organization, $commissionRule);
        $this->authorizePermission($request, $organization, 'commissions.rules.manage');
        $data = $this->validatedRule($request);
        $manager->update($request->user(), $commissionRule, $data, $data['tiers']);

        return back()->with('success', 'Brouillon mis à jour.');
    }

    public function duplicate(Request $request, ActiveTenantContext $context, CommissionRuleSet $commissionRule, CommissionRuleManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->assertTenant($organization, $commissionRule);
        $this->authorizePermission($request, $organization, 'commissions.rules.manage');
        $manager->duplicate($request->user(), $commissionRule);

        return back()->with('success', 'Une copie brouillon a été créée.');
    }

    public function activate(Request $request, ActiveTenantContext $context, CommissionRuleSet $commissionRule, CommissionRuleManager $manager, FreshAuthentication $fresh): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->assertTenant($organization, $commissionRule);
        $this->authorizePermission($request, $organization, 'commissions.rules.activate');
        $fresh->ensure($request, FreshAuthentication::LEVEL_ACCOUNT);
        $manager->activate($request->user(), $commissionRule);

        return back()->with('success', 'Règle de commission activée.');
    }

    public function archive(Request $request, ActiveTenantContext $context, CommissionRuleSet $commissionRule, CommissionRuleManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->assertTenant($organization, $commissionRule);
        $this->authorizePermission($request, $organization, 'commissions.rules.manage');
        $manager->archive($request->user(), $commissionRule);

        return back()->with('success', 'Brouillon archivé.');
    }

    public function simulate(Request $request, ActiveTenantContext $context, CommissionRuleSet $commissionRule, CommissionSimulationService $simulation, AuditLogger $audit): Response
    {
        $organization = $context->organizationOrFail();
        $this->assertTenant($organization, $commissionRule);
        $this->authorizePermission($request, $organization, 'commissions.simulate');
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer'],
            'salesperson_id' => ['nullable', 'integer'],
        ]);
        $store = empty($data['store_id']) ? null : Store::query()
            ->where('organization_id', $organization->getKey())->whereKey($data['store_id'])->firstOrFail();
        if (! empty($data['salesperson_id'])) {
            OrganizationMembership::query()->where('organization_id', $organization->getKey())
                ->where('user_id', $data['salesperson_id'])->where('status', 'active')->firstOrFail();
        }
        $result = $simulation->simulate($organization, $commissionRule, $data['from'], $data['to'], $store, $data['salesperson_id'] ?? null);
        $audit->record('commission_rule.simulated', $request->user(), $organization, auditable: $commissionRule, newValues: [
            'from' => $data['from'], 'to' => $data['to'], 'store_id' => $store?->getKey(), 'salesperson_id' => $data['salesperson_id'] ?? null,
        ]);

        return $this->page($request, $organization, ['filters' => $data, 'result' => $result, 'rule_set_id' => $commissionRule->getKey()]);
    }

    /** @return array<string,mixed> */
    private function validatedRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'effective_from' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tiers' => ['required', 'array', 'min:1', 'max:50'],
            'tiers.*.min_margin_rate' => ['nullable', 'numeric'],
            'tiers.*.max_margin_rate' => ['nullable', 'numeric'],
            'tiers.*.commission_rate' => ['required', 'numeric', 'between:0,100'],
        ]);
    }

    /** @param array<string,mixed>|null $simulation */
    private function page(Request $request, Organization $organization, ?array $simulation = null): Response
    {
        $sets = CommissionRuleSet::query()->where('organization_id', $organization->getKey())
            ->with(['tiers', 'createdBy:id,name', 'activatedBy:id,name'])
            ->orderByDesc('effective_from')->orderByDesc('id')->get();
        $stores = Store::query()->where('organization_id', $organization->getKey())->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']);
        $salespeople = OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('status', 'active')
            ->with('user:id,name')->get()->pluck('user')->filter()->unique('id')->sortBy('name')->values();

        return Inertia::render('Finance/CommissionRules', [
            'organization' => $organization->only(['id', 'name']),
            'ruleSets' => $sets,
            'stores' => $stores,
            'salespeople' => $salespeople,
            'simulation' => $simulation,
            'can' => [
                'manage' => $request->user()->hasPermission($organization, 'commissions.rules.manage'),
                'activate' => $request->user()->hasPermission($organization, 'commissions.rules.activate'),
                'simulate' => $request->user()->hasPermission($organization, 'commissions.simulate'),
            ],
        ]);
    }

    private function authorizePermission(Request $request, Organization $organization, string $permission): void
    {
        abort_unless($request->user()->hasPermission($organization, $permission), 403);
    }

    private function assertTenant(Organization $organization, CommissionRuleSet $set): void
    {
        abort_unless((int) $set->organization_id === (int) $organization->getKey(), 404);
    }
}
