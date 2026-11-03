<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\OrganizationDataReset\OrganizationDataResetBlockedException;
use App\Services\OrganizationDataReset\OrganizationDataResetAlreadyExecutedException;
use App\Services\OrganizationDataReset\OrganizationDataResetConfirmation;
use App\Services\OrganizationDataReset\OrganizationDataResetException;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetFailedException;
use App\Services\OrganizationDataReset\OrganizationDataResetPlan;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use App\Services\OrganizationDataReset\OrganizationDataResetStalePlanException;
use App\Services\Security\FreshAuthentication;
use App\Models\Warehouse;
use App\Services\OrganizationDataReset\Selective\SelectiveResetCategoryRegistry;
use App\Services\OrganizationDataReset\Selective\SelectiveResetExecutor;
use App\Services\OrganizationDataReset\Selective\SelectiveResetPlan;
use App\Services\OrganizationDataReset\Selective\SelectiveResetPlanner;
use App\Services\OrganizationDataReset\Selective\SelectiveResetSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationDataResetController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, SelectiveResetCategoryRegistry $registry): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorizeActor($request, $organization);
        $organizationId = (int) $organization->getKey();

        return Inertia::render('Settings/DataReset', [
            'organization' => [
                'id' => $organizationId,
                'name' => $organization->name,
            ],
            // Selective cleanup catalogue: categories, their root documents and live counts.
            'categories' => collect($registry->all())->map(fn (array $category, string $key) => [
                'key' => $key,
                'module' => $category['module'],
                'roots' => array_map(fn (array $root) => [
                    'table' => $root['table'],
                    'count' => Schema::hasTable($root['table']) ? DB::table($root['table'])->where('organization_id', $organizationId)->count() : 0,
                    'filters' => [
                        'status' => $root['status'] !== null,
                        'warehouse' => $root['warehouses'] !== [],
                    ],
                ], $category['roots']),
            ])->values(),
            'warehouses' => Warehouse::query()->where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name']),
            'selectiveConfirmationPhrase' => SelectiveResetExecutor::PHRASE,
        ]);
    }

    /**
     * Paginated, searchable root documents of one category table for record
     * selection, with the total matching the filters ("select all filtered").
     */
    public function records(Request $request, ActiveTenantContext $context, SelectiveResetCategoryRegistry $registry, SelectiveResetPlanner $planner): JsonResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorizeActor($request, $organization);
        $roots = $registry->roots();
        $validated = $request->validate([
            'table' => ['required', 'string', Rule::in(array_keys($roots))],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:40'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'organization_id' => ['prohibited'],
        ]);
        $root = $roots[$validated['table']];
        $table = $root['table'];
        if (! Schema::hasTable($table)) {
            return response()->json(['data' => [], 'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1], 'statuses' => []]);
        }

        $query = DB::table($table)->where($table.'.organization_id', (int) $organization->getKey());
        $planner->applyDateRange($query, $organization, $this->qualify($table, $root['date']), $validated['date_from'] ?? null, $validated['date_to'] ?? null);
        $planner->applyFilters($query, $root, array_filter([
            'search' => $validated['search'] ?? null,
            'status' => $validated['status'] ?? null,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        $select = [$table.'.id', $table.'.'.$root['number'].' as reference', DB::raw($this->qualify($table, $root['date']).' as business_date')];
        if ($root['status'] !== null) {
            $select[] = $table.'.'.$root['status'].' as status';
        }
        if ($root['party'] !== null) {
            [$partyTable, $column, $name] = $root['party'];
            $query->leftJoin($partyTable.' as party', 'party.id', '=', $table.'.'.$column);
            $select[] = 'party.'.$name.' as party';
        }
        foreach ($root['warehouses'] as $index => $column) {
            $query->leftJoin('warehouses as w'.$index, 'w'.$index.'.id', '=', $table.'.'.$column);
            $select[] = 'w'.$index.'.name as warehouse_'.$index;
        }

        $page = $query->select($select)->orderByDesc($table.'.id')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn ($row) => [
                'id' => (int) $row->id,
                'reference' => $row->reference !== null ? (string) $row->reference : '#'.$row->id,
                'date' => $row->business_date !== null ? substr((string) $row->business_date, 0, 10) : null,
                'status' => $row->status ?? null,
                'party' => $row->party ?? null,
                'warehouses' => array_values(array_filter(array_map(fn ($i) => $row->{'warehouse_'.$i} ?? null, array_keys($root['warehouses'])))),
            ]),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'statuses' => $root['status'] !== null
                ? DB::table($table)->where('organization_id', (int) $organization->getKey())->distinct()->orderBy($root['status'])->pluck($root['status'])
                : [],
        ]);
    }

    public function selectivePreview(Request $request, ActiveTenantContext $context, SelectiveResetCategoryRegistry $registry, SelectiveResetPlanner $planner): JsonResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorizeActor($request, $organization);
        $validated = $request->validate([
            'selection' => ['required', 'array'],
            'organization_id' => ['prohibited'],
            'tables' => ['prohibited'],
        ]);

        $plan = $planner->plan($organization, SelectiveResetSelection::fromArray($validated['selection'], $registry));

        return response()->json(['data' => $this->presentSelectivePlan($plan)]);
    }

    public function selectiveExecute(
        Request $request,
        ActiveTenantContext $context,
        SelectiveResetCategoryRegistry $registry,
        SelectiveResetPlanner $planner,
        SelectiveResetExecutor $executor,
        FreshAuthentication $freshAuthentication,
    ): JsonResponse {
        $organization = $context->organizationOrFail();
        $this->authorizeActor($request, $organization);
        $freshAuthentication->ensure($request, FreshAuthentication::LEVEL_TWO_FACTOR);
        $validated = $request->validate([
            'selection' => ['required', 'array'],
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[0-9a-f]{64}\z/'],
            'confirmation_phrase' => ['required', 'string', 'max:128'],
            'organization_name' => ['required', 'string', 'max:255'],
            'confirmed_total' => ['required', 'integer', 'min:1'],
            'organization_id' => ['prohibited'],
            'tables' => ['prohibited'],
        ]);

        if (! hash_equals(SelectiveResetExecutor::PHRASE, $validated['confirmation_phrase'])) {
            return response()->json(['message' => 'La phrase de confirmation est incorrecte.', 'errors' => ['confirmation_phrase' => ['La phrase de confirmation est incorrecte.']]], 422);
        }
        if (! hash_equals((string) $organization->name, $validated['organization_name'])) {
            return response()->json(['message' => 'Le nom de l’organisation ne correspond pas.', 'errors' => ['organization_name' => ['Le nom de l’organisation ne correspond pas.']]], 422);
        }

        $selection = SelectiveResetSelection::fromArray($validated['selection'], $registry);
        $plan = $planner->plan($organization, $selection);
        if (! hash_equals($plan->fingerprint, $validated['fingerprint'])) {
            return $this->staleResponse();
        }
        // The operation scope the owner confirmed must be the exact one executed.
        if ((int) $validated['confirmed_total'] !== $plan->deleteTotal()) {
            return response()->json(['message' => 'Le nombre confirmé ne correspond pas à l’aperçu.', 'errors' => ['confirmed_total' => ['Le nombre confirmé ne correspond pas à l’aperçu.']]], 422);
        }
        if (! $plan->isExecutable()) {
            return response()->json(['code' => 'RESET_BLOCKED', 'message' => 'The selective reset is blocked by retained dependencies.', 'blockers' => $plan->blockers], 409);
        }

        try {
            $result = $executor->execute($organization, $request->user(), $selection, $plan->fingerprint);
        } catch (OrganizationDataResetAlreadyExecutedException) {
            return response()->json(['code' => 'RESET_ALREADY_EXECUTED', 'message' => 'This reset preview has already been executed.'], 409);
        } catch (OrganizationDataResetStalePlanException) {
            return $this->staleResponse();
        } catch (OrganizationDataResetFailedException $exception) {
            return $this->failedResponse($exception->reference);
        } catch (OrganizationDataResetException) {
            return $this->failedResponse(null);
        }

        return response()->json(['data' => [
            'completed' => true,
            'deleted_records' => array_sum($result['deleted']),
            'unlinked_records' => array_sum($result['unlinked']),
            'released_reservations' => $result['released'],
            'deleted' => $result['deleted'],
            'audit_log_id' => $result['audit_log_id'],
        ]]);
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
        } catch (OrganizationDataResetFailedException $exception) {
            return $this->failedResponse($exception->reference);
        } catch (OrganizationDataResetException) {
            return $this->failedResponse(null);
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
    private function presentSelectivePlan(SelectiveResetPlan $plan): array
    {
        return [
            'mode' => 'selective',
            'fingerprint' => $plan->fingerprint,
            'previewed_at' => $plan->previewedAt->toIso8601String(),
            'executable' => $plan->isExecutable(),
            'selected' => $plan->selected,
            'selected_total' => $plan->selectedTotal(),
            'delete_total' => $plan->deleteTotal(),
            'unlink_total' => $plan->unlinkTotal(),
            'released_reservations' => count($plan->release),
            'breakdown' => $plan->breakdown,
            'samples' => $plan->samples,
            'blockers' => $plan->blockers,
            'protected' => $plan->protected,
        ];
    }

    /** Qualify the column names of a root date expression with their table (joins add columns). */
    private function qualify(string $table, string $expression): string
    {
        return preg_replace_callback('/\b([a-z_]+_(?:at|date))\b/', fn ($m) => $table.'.'.$m[1], $expression);
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

    /** Rolled back; `reference` points to the server log entry with the real cause. */
    private function failedResponse(?string $reference): JsonResponse
    {
        return response()->json([
            'code' => 'RESET_REJECTED',
            'message' => 'The organization data reset could not be completed safely. Nothing was changed.',
            'reference' => $reference,
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
