<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

trait ScopesToActiveStore
{
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->when(Auth::check(), fn ($query) => $query
                ->where('organization_id', Auth::user()->active_organization_id)
                ->where('store_id', Auth::user()->active_store_id)
                ->whereHas('organization', fn ($query) => $query
                    ->where('status', 'active')
                    ->whereHas('memberships', fn ($query) => $query
                        ->where('user_id', Auth::id())
                        ->where('status', 'active')))
                ->whereHas('store', fn ($query) => $query
                    ->where('status', 'active')
                    ->whereHas('memberships', fn ($query) => $query->where('user_id', Auth::id()))));
    }
}
