<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreMembershipController extends Controller
{
    public function store(Request $request, Store $store, MembershipService $memberships): RedirectResponse
    {
        $this->authorize('create', [StoreMembership::class, $store]);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $memberships->addStoreMember(
            $request->user(),
            $store,
            User::query()->findOrFail($data['user_id']),
        );

        return back();
    }

    public function destroy(Request $request, StoreMembership $storeMembership, MembershipService $memberships): RedirectResponse
    {
        $this->authorize('delete', $storeMembership);
        $memberships->removeStoreMember($request->user(), $storeMembership);

        return back();
    }
}
