<?php

namespace App\Actions\OrganizationBackups;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationBackups\OrganizationBackupRestorer;
use App\Support\SensitiveDataRedactor;

class RestoreOrganizationBackupAction
{
    public function __construct(
        private readonly OrganizationBackupRestorer $restorer,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{pre_restore_path:string, manifest:array<string, mixed>} */
    public function execute(User $actor, Organization $organization, string $path): array
    {
        abort_unless($actor->hasPermission($organization, 'organization_backups.restore'), 403);

        $this->audit->record('organization_backup.restore_started', $actor, $organization);

        try {
            $result = $this->restorer->restore($path, $organization, $actor);
            $this->audit->record('organization_backup.restored', $actor, $organization, newValues: [
                'backup_created_at' => $result['manifest']['created_at'] ?? null,
                'source_organization_id' => $result['manifest']['source_organization_id'] ?? null,
                'pre_restore_snapshot' => basename($result['pre_restore_path']),
            ]);

            return $result;
        } catch (\Throwable $exception) {
            $this->audit->record('organization_backup.restore_failed', $actor, $organization, newValues: [
                'reason' => SensitiveDataRedactor::text($exception->getMessage()),
            ]);

            throw $exception;
        }
    }
}
