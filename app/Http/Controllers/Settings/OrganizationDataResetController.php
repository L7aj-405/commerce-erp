<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\OrganizationDataReset\OrganizationDataResetBlockedException;
use App\Services\OrganizationDataReset\OrganizationDataResetAlreadyExecutedException;
use App\Services\OrganizationDataReset\OrganizationDataResetConfirmation;
use App\Services\OrganizationDataReset\OrganizationDataResetException;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetPlan;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use App\Services\OrganizationDataReset\OrganizationDataResetStalePlanException;
use App\Services\Security\FreshAuthentication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationDataResetController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorizeActor($request, $organization);

        return Inertia::render('Settings/DataReset', [
            'organization' => [
                'id' => (int) $organization->getKey(),
                'name' => $organization->name,
            ],
        ]);
    }

    public function preview(
        Request $request,
        ActiveTenantContext $context,
        OrganizationDataResetPreviewService $previews,
    ): JsonResponse {
        $organization = $context->organizationOrFail();
        $this->authorizeActor($request, $organization);
        $validated = $request->validate([
            'scope' => ['required', 'string', Rule::enum(OrganizationDataResetScope::class)],
            'organization_id' => ['prohibited'],
            'tables' => ['prohibited'],
        ]);

        $plan = $previews->preview($organization, OrganizationDataResetScope::from($validated['scope']));

        return response()->json(['data' => $this->presentPlan($plan)]);
    }

    public function execute(
        Request $request,
        ActiveTenantContext $context,
        OrganizationDataResetPreviewService $previews,
        OrganizationDataResetExecutor $executor,
        FreshAuthentication $freshAuthentication,
    ): JsonResponse {
        $organization = $context->organizationOrFail();
        $this->authorizeActor($request, $organization);
        $freshAuthentication->ensure($request, FreshAuthentication::LEVEL_TWO_FACTOR);

        $validated = $request->validate([
            'scope' => ['required', 'string', Rule::enum(OrganizationDataResetScope::class)],
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[0-9a-f]{64}\z/'],
            'confirmation_phrase' => ['required', 'string', 'max:128'],
            'organization_name' => ['required', 'string', 'max:255'],
            'organization_id' => ['prohibited'],
            'tables' => ['prohibited'],
        ]);

        if (! OrganizationDataResetConfirmation::matches($validated['confirmation_phrase'])) {
            return response()->json([
                'message' => 'The data reset confirmation phrase is incorrect.',
                'errors' => ['confirmation_phrase' => ['The data reset confirmation phrase is incorrect.']],
            ], 422);
        }
        if (! hash_equals((string) $organization->name, $validated['organization_name'])) {
            return response()->json([
                'message' => 'The organization name does not match.',
                'errors' => ['organization_name' => ['The organization name does not match.']],
            ], 422);
        }

        $scope = OrganizationDataResetScope::from($validated['scope']);
        $plan = $previews->preview($organization, $scope);

        if (! hash_equals($plan->fingerprint, $validated['fingerprint'])) {
            return $this->staleResponse();
        }
        if (! $plan->isExecutable()) {
            return $this->blockedResponse($plan->blockers);
        }

        try {
            $result = $executor->execute($organization, $request->user(), $plan);
        } catch (OrganizationDataResetAlreadyExecutedException) {
            return response()->json([
                'code' => 'RESET_ALREADY_EXECUTED',
                'message' => 'This reset preview has already been executed.',
            ], 409);
        } catch (OrganizationDataResetStalePlanException) {
            return $this->staleResponse();
        } catch (OrganizationDataResetBlockedException $exception) {
            return $this->blockedResponse($exception->blockers);
        } catch (OrganizationDataResetException) {
            return response()->json([
                'code' => 'RESET_REJECTED',
                'message' => 'The organization data reset could not be completed safely.',
            ], 409);
        }

        return response()->json([
            'data' => [
                'scope' => $result->scope,
                'affected_records' => array_sum($result->affected),
                'completed' => true,
            ],
        ]);
    }

    private function authorizeActor(Request $request, \App\Models\Organization $organization): void
    {
        $user = $request->user();
        abort_unless(
            (int) $organization->owner_id === (int) $user->getKey()
                || $user->hasPermission($organization, 'system.data_reset'),
            403,
        );
    }

    /** @return array<string,mixed> */
    private function presentPlan(OrganizationDataResetPlan $plan): array
    {
        $affected = [];
        foreach ($plan->operations as $operation) {
            $scope = $operation['scope'];
            $count = $plan->counts[$operation['key']] ?? 0;
            $isUpdate = in_array($operation['action'], [
                'set_null', 'clear_non_owner_active_context',
            ], true);
            $affected[$scope] ??= [
                'scope' => $scope,
                'affected_records' => 0,
                'deleted_records' => 0,
                'updated_records' => 0,
                'operations' => 0,
            ];
            $affected[$scope]['affected_records'] += $count;
            $affected[$scope][$isUpdate ? 'updated_records' : 'deleted_records'] += $count;
            $affected[$scope]['operations']++;
        }

        return [
            'scope' => $plan->requestedScope,
            'included_scopes' => $plan->scopes,
            'fingerprint' => $plan->fingerprint,
            'previewed_at' => $plan->previewedAt->toIso8601String(),
            'executable' => $plan->isExecutable(),
            'affected_records' => $plan->totalAffected(),
            'affected' => array_values($affected),
            'blockers' => $plan->blockers,
            'preserved' => [
                'organization', 'primary_owner_security', 'stores_and_warehouses',
                'credentials_and_integrations', 'numbering_and_sequences', 'audit_history',
            ],
            'confirmation_phrase' => OrganizationDataResetConfirmation::PHRASE,
        ];
    }

    /** @param list<array{code:string,message:string,count:int,required_scopes:list<string>}> $blockers */
    private function blockedResponse(array $blockers): JsonResponse
    {
        return response()->json([
            'code' => 'RESET_BLOCKED',
            'message' => 'The organization data reset is blocked by retained dependencies.',
            'blockers' => $blockers,
        ], 409);
    }

    private function staleResponse(): JsonResponse
    {
        return response()->json([
            'code' => 'STALE_RESET_PREVIEW',
            'message' => 'The reset preview is stale. Generate a new preview before continuing.',
        ], 409);
    }
}
