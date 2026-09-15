<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\SaveOrganizationMailSettingAction;
use App\Actions\Settings\SendOrganizationMailTestAction;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMailSetting;
use App\Services\ActiveTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationMailSettingController extends Controller
{
    public function edit(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewSettings', $organization);

        $setting = $organization->mailSetting()->first();

        return Inertia::render('Settings/EmailConfiguration', [
            'organization' => $organization->only(['id', 'name']),
            'setting' => $setting ? [
                ...$setting->only([
                    'id', 'sender_name', 'sender_email', 'smtp_host', 'smtp_port', 'smtp_username',
                    'smtp_encryption', 'reply_to_email', 'reply_to_name', 'is_enabled',
                    'last_test_ok', 'last_test_message',
                ]),
                'last_tested_at' => $setting->last_tested_at?->toIso8601String(),
                'has_password' => filled($setting->getRawOriginal('smtp_password')),
            ] : null,
            'status' => $this->status($setting),
            'canUpdate' => $request->user()->hasPermission($organization, 'settings.update'),
        ]);
    }

    public function update(Request $request, ActiveTenantContext $context, SaveOrganizationMailSettingAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);

        $existing = $organization->mailSetting()->first();

        $data = $request->validate([
            'sender_name' => ['required', 'string', 'max:255'],
            'sender_email' => ['required', 'email:rfc', 'max:254'],
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_username' => ['required', 'string', 'max:255'],
            'smtp_password' => [$existing ? 'nullable' : 'required', 'string', 'max:512'],
            'smtp_encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'reply_to_email' => ['nullable', 'email:rfc', 'max:254'],
            'reply_to_name' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['boolean'],
        ]);

        $action->execute($request->user(), $organization, $data);

        return back()->with('success', 'Configuration e-mail enregistrée.');
    }

    public function test(Request $request, ActiveTenantContext $context, SendOrganizationMailTestAction $action): JsonResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);

        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        return response()->json($action->execute($request->user(), $organization, $data['email']));
    }

    /** @return 'not_configured'|'configured'|'verified' */
    private function status(?OrganizationMailSetting $setting): string
    {
        if (! $setting || ! $setting->isUsable()) {
            return 'not_configured';
        }

        return $setting->last_test_ok === true ? 'verified' : 'configured';
    }
}
