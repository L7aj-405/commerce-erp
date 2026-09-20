<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\SaveOrganizationMailSettingAction;
use App\Actions\Settings\SendOrganizationMailTestAction;
use App\Exceptions\Security\UnsafeOutboundDestinationException;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMailSetting;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Security\OutboundDestinationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function update(Request $request, ActiveTenantContext $context, SaveOrganizationMailSettingAction $action, OutboundDestinationGuard $guard, AuditLogger $audit): RedirectResponse
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

        $data['smtp_host'] = $this->normalizeSmtpHost($data['smtp_host']);

        try {
            $guard->assertPublicHost($data['smtp_host'], 'SMTP');
        } catch (UnsafeOutboundDestinationException $exception) {
            $audit->record('smtp.destination_rejected', $request->user(), $organization, newValues: [
                'category' => $exception->category,
            ]);

            throw ValidationException::withMessages(['smtp_host' => $exception->userMessage()]);
        }

        $action->execute($request->user(), $organization, $data);

        return back()->with('success', 'Configuration e-mail enregistrée.');
    }

    private function normalizeSmtpHost(string $host): string
    {
        $host = trim($host);

        if (str_contains($host, '://')) {
            $parts = parse_url($host);

            $path = $parts['path'] ?? null;

            if (
                $parts === false
                || empty($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || ($path !== null && $path !== '' && $path !== '/')
                || isset($parts['query'])
                || isset($parts['fragment'])
            ) {
                return $host;
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));

            if (! in_array($scheme, ['smtp', 'smtps', 'ssl', 'tls'], true)) {
                return $host;
            }

            return trim($parts['host']);
        }

        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $host, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^([^:]+):\d+$/', $host, $matches) === 1) {
            return trim($matches[1]);
        }

        return $host;
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
