<?php

namespace App\Services\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;

/** Common audit trail for typed policy changes: one `organization.policy_changed` event per key, old -> new + reason. */
final class PolicyAudit
{
    public const EVENT = 'organization.policy_changed';

    public function __construct(private readonly AuditLogger $audit) {}

    public function record(User $actor, Organization $organization, string $policy, mixed $old, mixed $new, string $reason): void
    {
        $this->audit->record(self::EVENT, $actor, $organization, auditable: $organization,
            oldValues: ['policy' => $policy, 'value' => $old],
            newValues: ['policy' => $policy, 'value' => $new, 'reason' => $reason, 'effect' => 'future_documents_only'],
        );
    }
}
