<?php

namespace App\Policies;

use App\Models\CreditNote;
use App\Models\User;
use App\Services\ActiveTenantContext;

class CreditNotePolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}
    public function view(User $user, CreditNote $note): bool { return $this->context->organization()?->id === $note->organization_id && $this->context->store()?->id === $note->store_id && $user->hasPermission($note->organization_id, 'credit_notes.view'); }
}
