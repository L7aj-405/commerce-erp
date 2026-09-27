<?php

namespace App\Actions\OrganizationBackups;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationBackups\OrganizationBackupArchive;
use App\Services\OrganizationBackups\OrganizationBackupExporter;

class CreateOrganizationBackupAction
{
    public function __construct(
        private readonly OrganizationBackupExporter $exporter,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Organization $organization): OrganizationBackupArchive
    {
        abort_unless($actor->hasPermission($organization, 'organization_backups.create'), 403);

        $archive = $this->exporter->create($organization, $actor);
        $this->audit->record('organization_backup.created', $actor, $organization, newValues: [
            'filename' => $archive->filename,
            'size' => $archive->size,
            'counts' => $archive->counts,
        ]);

        return $archive;
    }
}
