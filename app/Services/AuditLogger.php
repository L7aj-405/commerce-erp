<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Support\SensitiveDataRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(
        string $event,
        ?User $actor = null,
        ?Organization $organization = null,
        ?Store $store = null,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
    ): AuditLog {
        $log = new AuditLog;
        $log->organization_id = $organization?->getKey();
        $log->store_id = $store?->getKey();
        $log->actor_id = $actor?->getKey();
        $log->event = $event;
        $log->auditable_type = $auditable?->getMorphClass();
        $log->auditable_id = $auditable?->getKey();
        $log->old_values = $this->sanitize($oldValues);
        $log->new_values = $this->sanitize($newValues);
        $log->ip_address = $this->request->ip();
        $log->user_agent = $this->request->userAgent();
        $log->save();

        return $log;
    }

    /** @param array<string, mixed> $values */
    private function sanitize(array $values): array
    {
        return SensitiveDataRedactor::sanitizeArray($values);
    }
}
