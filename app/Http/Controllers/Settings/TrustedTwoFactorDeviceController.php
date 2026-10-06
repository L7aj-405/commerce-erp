<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\TrustedTwoFactorDevice;
use App\Services\AuditLogger;
use App\Services\Notifications\OperationalNotificationProducer;
use App\Services\Security\TrustedTwoFactorDeviceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrustedTwoFactorDeviceController extends Controller
{
    public function destroy(
        Request $request,
        string $device,
        TrustedTwoFactorDeviceManager $trustedDevices,
        AuditLogger $audit,
        OperationalNotificationProducer $notifications,
    ): RedirectResponse {
        $user = $request->user();
        $trustedDevice = TrustedTwoFactorDevice::query()
            ->where('user_id', $user->getKey())
            ->whereKey($device)
            ->firstOrFail();

        if ($trustedDevice->revoked_at === null) {
            $trustedDevice->revoked_at = now();
            $trustedDevice->save();

            $log = $audit->record(
                'two_factor.trusted_device_revoked',
                $user,
                $user->activeOrganization,
                auditable: $trustedDevice,
                oldValues: ['device_name' => $trustedDevice->device_name],
                newValues: ['revoked_at' => $trustedDevice->revoked_at->toIso8601String()],
            );
            if ($organization = $user->activeOrganization) {
                $notifications->trustedDevice($user, $organization, $log->getKey(), 'revoked', $trustedDevice->device_name);
            }
        }

        $response = back()->with('success', 'Appareil de confiance révoqué.');
        if ($trustedDevices->currentDeviceId($request) === $trustedDevice->getKey()) {
            $response->withCookie($trustedDevices->forgetCookie());
        }

        return $response;
    }

    public function destroyAll(
        Request $request,
        TrustedTwoFactorDeviceManager $trustedDevices,
        AuditLogger $audit,
        OperationalNotificationProducer $notifications,
    ): RedirectResponse {
        $user = $request->user();
        $count = $trustedDevices->revokeAll($user);

        $log = $audit->record(
            'two_factor.trusted_devices_revoked',
            $user,
            $user->activeOrganization,
            auditable: $user,
            newValues: ['revoked_count' => $count],
        );
        if ($organization = $user->activeOrganization) {
            $notifications->trustedDevice($user, $organization, $log->getKey(), 'revoked_all');
        }

        return back()
            ->with('success', 'Tous les appareils de confiance ont été révoqués.')
            ->withCookie($trustedDevices->forgetCookie());
    }
}
