<?php

namespace App\Actions\Settings;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Turns off the organization's active stamp configuration. Documents already
 * stamped are unaffected — they keep their own frozen DocumentStampApposition
 * and keep rendering with it; only NEW appositions become impossible until a
 * stamp is configured again.
 */
class DeactivateOrganizationDocumentStampAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Organization $organization): void
    {
        $current = $organization->activeDocumentStamp()->first();
        if (! $current) {
            return;
        }

        // $current is guarded ($guarded = ['*']); forceFill() is this
        // project's established way to make a deliberate, targeted write to
        // a guarded model — see SaveOrganizationDocumentStampAction.
        $current->forceFill(['active' => false])->save();

        $this->audit->record('organization_document_stamp.deactivated', $actor, $organization, auditable: $current);
    }
}
