<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

trait ScopesToActiveOrganization
{
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->when(Auth::check(), fn ($query) => $query
                ->where('organization_id', Auth::user()->active_organization_id)
                ->whereHas('organization.memberships', fn ($query) => $query
                    ->where('user_id', Auth::id())
                    ->where('status', 'active')));
    }
}
