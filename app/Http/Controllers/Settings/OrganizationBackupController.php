<?php

namespace App\Http\Controllers\Settings;

use App\Actions\OrganizationBackups\CreateOrganizationBackupAction;
use App\Actions\OrganizationBackups\RestoreOrganizationBackupAction;
use App\Http\Controllers\Controller;
use App\Models\OrganizationBackup;
use App\Models\OrganizationBackupCloudCopy;
use App\Models\OrganizationCloudBackupConnection;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\OrganizationBackups\OrganizationBackupException;
use App\Services\OrganizationBackups\OrganizationBackupSchedule;
use App\Services\OrganizationBackups\OrganizationBackupSettingsService;
use App\Services\OrganizationBackups\OrganizationBackupStorage;
use App\Services\OrganizationBackups\OrganizationBackupValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrganizationBackupController extends Controller
{
    public function index(
        Request $request,
        ActiveTenantContext $context,
        OrganizationBackupSettingsService $settings,
        OrganizationBackupSchedule $schedule,
    ): Response
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.view'), 403);
        $setting = $settings->forOrganization($organization);

        return Inertia::render('Settings/OrganizationBackups', [
            'organization' => $organization->only(['id', 'name']),
            'validatedBackup' => session('organization_backup.validated'),
            'settings' => [
                'enabled' => $setting->enabled,
                'frequency' => $setting->frequency,
                'time_of_day' => substr((string) $setting->time_of_day, 0, 5),
                'day_of_week' => $setting->day_of_week,
                'timezone' => $setting->timezone,
                'retention_count' => $setting->retention_count,
                'notify_on_failure' => $setting->notify_on_failure,
                'next_backup_at' => $schedule->nextRun($setting)?->toIso8601String(),
                'last_success_at' => $setting->last_success_at?->toIso8601String(),
                'last_failure_at' => $setting->last_failure_at?->toIso8601String(),
                'last_failure_message' => $setting->last_failure_message,
            ],
            'history' => $organization->backups()
                ->with(['cloudCopies' => fn ($query) => $query->where('provider', OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE)])
                ->latest('created_at')
                ->limit(30)
                ->get()
                ->map(function (OrganizationBackup $backup) {
                    $cloudCopy = $backup->cloudCopies->first();

                    return [
                        'id' => $backup->id,
                        'uuid' => $backup->uuid,
                        'type' => $backup->type,
                        'status' => $backup->status,
                        'created_at' => $backup->created_at?->toIso8601String(),
                        'scheduled_for' => $backup->scheduled_for?->toIso8601String(),
                        'completed_at' => $backup->completed_at?->toIso8601String(),
                        'size_bytes' => $backup->size_bytes,
                        'storage' => $backup->storage_disk && $backup->storage_path ? 'external' : null,
                        'failure_message' => $backup->status === OrganizationBackup::STATUS_FAILED ? $backup->failure_message : null,
                        'downloadable' => $backup->status === OrganizationBackup::STATUS_COMPLETED,
                        'google_drive' => $cloudCopy ? [
                            'id' => $cloudCopy->id,
                            'status' => $cloudCopy->status,
                            'failure_message' => $cloudCopy->status === OrganizationBackupCloudCopy::STATUS_FAILED ? $cloudCopy->failure_message : null,
                            'completed_at' => $cloudCopy->completed_at?->toIso8601String(),
                        ] : null,
                    ];
                }),
            'googleDrive' => $this->googleDriveProps($organization->getKey()),
            'can' => [
                'create' => $request->user()->hasPermission($organization, 'organization_backups.create'),
                'restore' => $request->user()->hasPermission($organization, 'organization_backups.restore'),
            ],
        ]);
    }

    public function updateSettings(Request $request, ActiveTenantContext $context, OrganizationBackupSettingsService $settings): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.create'), 403);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'frequency' => ['required', 'in:daily,weekly'],
            'time_of_day' => ['required', 'date_format:H:i'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'timezone' => ['required', 'timezone'],
            'retention_count' => ['required', 'integer', 'min:7', 'max:90'],
            'notify_on_failure' => ['sometimes', 'boolean'],
        ]);

        $setting = $settings->forOrganization($organization);
        $setting->enabled = (bool) ($data['enabled'] ?? false);
        $setting->frequency = $data['frequency'];
        $setting->time_of_day = $data['time_of_day'].':00';
        $setting->day_of_week = $data['frequency'] === 'weekly' ? (int) ($data['day_of_week'] ?? 1) : null;
        $setting->timezone = $data['timezone'];
        $setting->retention_count = (int) $data['retention_count'];
        $setting->notify_on_failure = (bool) ($data['notify_on_failure'] ?? false);
        $setting->save();

        return back()->with('status', 'Paramètres de sauvegarde automatique enregistrés.');
    }

    public function store(Request $request, ActiveTenantContext $context, CreateOrganizationBackupAction $action, AuditLogger $audit): BinaryFileResponse
    {
        $organization = $context->organizationOrFail();
        $archive = $action->execute($request->user(), $organization);
        $audit->record('organization_backup.downloaded', $request->user(), $organization, newValues: [
            'filename' => $archive->filename,
            'size' => $archive->size,
        ]);

        return response()->download($archive->path, $archive->filename, [
            'Content-Type' => 'application/octet-stream',
        ])->deleteFileAfterSend(true);
    }

    public function validateUpload(Request $request, ActiveTenantContext $context, OrganizationBackupValidator $validator, AuditLogger $audit): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.restore'), 403);
        $data = $request->validate(['backup' => ['required', 'file', 'max:204800']]);

        try {
            $validated = $validator->validateUpload($data['backup'], $organization);
            session(['organization_backup.validated' => [
                'token' => $validated['token'],
                'source' => 'private',
                'source_label' => 'Sauvegarde privée',
                'summary' => $validated['summary'],
                'manifest' => [
                    'created_at' => $validated['manifest']['created_at'] ?? null,
                    'version' => $validated['manifest']['version'] ?? null,
                    'source_organization_id' => $validated['manifest']['source_organization_id'] ?? null,
                    'source_organization_name' => $validated['manifest']['source_organization_name'] ?? null,
                ],
            ]]);

            return back()->with('status', 'Sauvegarde vérifiée. Confirmez la restauration pour continuer.');
        } catch (OrganizationBackupException $exception) {
            $audit->record('organization_backup.validation_failed', $request->user(), $organization, newValues: [
                'reason' => $exception->getMessage(),
            ]);

            return back()->withErrors(['backup' => $exception->getMessage()]);
        } catch (\Throwable) {
            $audit->record('organization_backup.validation_failed', $request->user(), $organization, newValues: [
                'reason' => 'unexpected_validation_error',
            ]);

            return back()->withErrors(['backup' => 'La sauvegarde est invalide ou corrompue.']);
        }
    }

    public function validateHistory(
        Request $request,
        ActiveTenantContext $context,
        OrganizationBackup $organizationBackup,
        OrganizationBackupStorage $storage,
        OrganizationBackupValidator $validator,
        AuditLogger $audit,
    ): RedirectResponse {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.restore'), 403);
        abort_unless((int) $organizationBackup->organization_id === (int) $organization->getKey(), 404);
        abort_unless($organizationBackup->status === OrganizationBackup::STATUS_COMPLETED, 404);

        $directory = storage_path('app/private/organization-backups/history');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.$organizationBackup->uuid.'.erpbackup';

        try {
            $storage->downloadToTemporaryFile($organizationBackup, $path);
            $validated = $validator->stageValidatedPath($path, $organization);
            @unlink($path);

            session(['organization_backup.validated' => [
                'token' => $validated['token'],
                'source' => 'private',
                'source_label' => 'Sauvegarde privée',
                'summary' => $validated['summary'],
                'manifest' => [
                    'created_at' => $validated['manifest']['created_at'] ?? null,
                    'version' => $validated['manifest']['version'] ?? null,
                    'source_organization_id' => $validated['manifest']['source_organization_id'] ?? null,
                    'source_organization_name' => $validated['manifest']['source_organization_name'] ?? null,
                ],
            ]]);

            return back()->with('status', 'Sauvegarde vérifiée. Confirmez la restauration pour continuer.');
        } catch (OrganizationBackupException $exception) {
            @unlink($path);
            $audit->record('organization_backup.validation_failed', $request->user(), $organization, newValues: [
                'backup_uuid' => $organizationBackup->uuid,
                'reason' => $exception->getMessage(),
            ]);

            return back()->withErrors(['backup' => $exception->getMessage()]);
        }
    }

    public function downloadHistory(
        Request $request,
        ActiveTenantContext $context,
        OrganizationBackup $organizationBackup,
        OrganizationBackupStorage $storage,
        AuditLogger $audit,
    ): BinaryFileResponse {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.view'), 403);
        abort_unless((int) $organizationBackup->organization_id === (int) $organization->getKey(), 404);
        abort_unless($organizationBackup->status === OrganizationBackup::STATUS_COMPLETED, 404);

        $directory = storage_path('app/private/organization-backups/downloads');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.$organizationBackup->uuid.'.erpbackup';
        $storage->downloadToTemporaryFile($organizationBackup, $path);

        $audit->record('organization_backup.downloaded', $request->user(), $organization, newValues: [
            'backup_uuid' => $organizationBackup->uuid,
            'size' => $organizationBackup->size_bytes,
        ]);

        return response()->download($path, $organizationBackup->uuid.'.erpbackup', [
            'Content-Type' => 'application/octet-stream',
        ])->deleteFileAfterSend(true);
    }

    public function restore(Request $request, ActiveTenantContext $context, RestoreOrganizationBackupAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.restore'), 403);
        $data = $request->validate([
            'token' => ['required', 'string'],
            'confirm' => ['accepted'],
        ]);

        $validated = session('organization_backup.validated');
        abort_unless(is_array($validated) && hash_equals((string) ($validated['token'] ?? ''), $data['token']), 403);

        $path = storage_path('app/private/organization-backups/validated/'.$data['token'].'.erpbackup');
        try {
            $action->execute($request->user(), $organization, $path);
            session()->forget('organization_backup.validated');
            @unlink($path);

            return redirect()->route('organization-backups.index')->with('status', 'Sauvegarde restaurée avec succès.');
        } catch (OrganizationBackupException $exception) {
            return back()->withErrors(['restore' => $exception->getMessage()]);
        } catch (\Throwable) {
            return back()->withErrors(['restore' => 'La restauration a échoué. La sauvegarde de sécurité pré-restauration a été conservée.']);
        }
    }

    /** @return array<string, mixed> */
    private function googleDriveProps(int $organizationId): array
    {
        $connection = OrganizationCloudBackupConnection::query()
            ->where('organization_id', $organizationId)
            ->where('provider', OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE)
            ->first();

        if (! $connection) {
            return [
                'connected' => false,
                'enabled' => false,
                'account' => null,
                'folder' => null,
                'last_sync_at' => null,
                'last_sync_status' => null,
                'last_sync_error' => null,
            ];
        }

        return [
            'connected' => $connection->is_enabled && filled($connection->refresh_token),
            'enabled' => $connection->is_enabled,
            'account' => $connection->provider_account_identifier,
            'folder' => filled($connection->provider_folder_id) ? '10xScale ERP / Sauvegardes' : null,
            'last_sync_at' => $connection->last_sync_status === 'completed' ? $connection->last_sync_at?->toIso8601String() : null,
            'last_sync_status' => $connection->last_sync_status,
            'last_sync_error' => $connection->last_sync_error,
        ];
    }
}
