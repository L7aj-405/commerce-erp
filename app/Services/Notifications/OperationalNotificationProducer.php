<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\Organization;
use App\Models\OrganizationBackup;
use App\Models\OrganizationBackupCloudCopy;
use App\Models\User;
use App\Models\WooCommerceSyncRun;

final class OperationalNotificationProducer
{
    public function __construct(private readonly NotificationPublisher $publisher) {}

    public function wooCommerce(Organization $organization, WooCommerceSyncRun $run): void
    {
        $severity = $run->status === WooCommerceSyncRun::STATUS_FAILED
            ? NotificationSeverity::Critical
            : ($run->status === WooCommerceSyncRun::STATUS_COMPLETED_WITH_ERRORS ? NotificationSeverity::Warning : NotificationSeverity::Success);
        $this->publisher->publish($organization, $this->publisher->recipientsWithPermission($organization, 'integrations.view'),
            NotificationCategory::WooCommerce, $severity,
            $run->status === WooCommerceSyncRun::STATUS_FAILED ? 'Synchronisation WooCommerce échouée' : 'Synchronisation WooCommerce terminée',
            $run->status === WooCommerceSyncRun::STATUS_FAILED ? 'La synchronisation des produits a échoué.' : "{$run->products_read} produit(s) traité(s), {$run->products_failed} échec(s).",
            '/integrations/woocommerce', 'woocommerce_sync_run', $run->getKey(), $run->status,
            $run->only(['products_read', 'products_created', 'products_updated', 'products_skipped', 'products_failed']));
    }

    public function backupCompleted(Organization $organization, OrganizationBackup $backup): void
    {
        $this->backup($organization, $backup, NotificationSeverity::Success, 'Sauvegarde terminée', 'La sauvegarde planifiée de l’organisation est disponible.', 'completed');
    }

    public function backupFailed(Organization $organization, OrganizationBackup $backup): void
    {
        $this->backup($organization, $backup, NotificationSeverity::Critical, 'Échec de la sauvegarde', 'La sauvegarde planifiée n’a pas pu être terminée.', 'failed');
    }

    public function cloudCopyFailed(Organization $organization, OrganizationBackupCloudCopy $copy): void
    {
        $this->publisher->publish($organization, $this->publisher->recipientsWithPermission($organization, 'organization_backups.view'),
            NotificationCategory::Backup, NotificationSeverity::Critical, 'Échec de la copie Google Drive',
            'La copie de sauvegarde vers Google Drive a échoué.', '/organization-backups', 'organization_backup_cloud_copy', $copy->getKey(), 'failed');
    }

    public function security(User $user, Organization $organization, int $auditLogId, string $event): void
    {
        [$title, $message, $severity] = match ($event) {
            'two_factor.enabled' => [
                'Double authentification activée',
                'La double authentification protège désormais votre compte.',
                NotificationSeverity::Success,
            ],
            'two_factor.disabled' => [
                'Double authentification désactivée',
                'La double authentification de votre compte a été désactivée.',
                NotificationSeverity::Critical,
            ],
            default => [
                'Codes de récupération renouvelés',
                'De nouveaux codes de récupération ont été générés pour votre compte.',
                NotificationSeverity::Critical,
            ],
        };
        $this->publisher->publishToUser($user, $organization, NotificationCategory::Security, $severity,
            $title, $message, '/security', 'audit_log', $auditLogId, $event);
    }

    public function trustedDevice(
        User $user,
        Organization $organization,
        int $auditLogId,
        string $event,
        ?string $deviceName = null,
    ): void {
        [$title, $message, $severity] = match ($event) {
            'created' => [
                'Nouvel appareil de confiance',
                'Un appareil'.($deviceName ? ' ('.$deviceName.')' : '').' peut désormais ignorer la seconde étape de connexion.',
                NotificationSeverity::Warning,
            ],
            'revoked' => [
                'Appareil de confiance révoqué',
                'Un appareil'.($deviceName ? ' ('.$deviceName.')' : '').' ne peut plus ignorer la seconde étape de connexion.',
                NotificationSeverity::Success,
            ],
            default => [
                'Appareils de confiance révoqués',
                'Tous les appareils de confiance de votre compte ont été révoqués.',
                NotificationSeverity::Critical,
            ],
        };

        $this->publisher->publishToUser(
            $user,
            $organization,
            NotificationCategory::Security,
            $severity,
            $title,
            $message,
            '/security',
            'audit_log',
            $auditLogId,
            'two_factor.trusted_device_'.$event,
        );
    }

    public function sessionSecurity(User $user, Organization $organization, int $auditLogId, string $event): void
    {
        [$title, $message] = $event === 'revoked_all'
            ? ['Autres sessions déconnectées', 'Toutes les autres sessions actives de votre compte ont été déconnectées.']
            : ['Session déconnectée', 'Une autre session active de votre compte a été déconnectée.'];

        $this->publisher->publishToUser(
            $user,
            $organization,
            NotificationCategory::Security,
            NotificationSeverity::Warning,
            $title,
            $message,
            '/security/sessions',
            'audit_log',
            $auditLogId,
            'auth.session_'.$event,
        );
    }

    public function accountSecurity(User $user, Organization $organization, int $auditLogId, string $event): void
    {
        [$title, $message] = $event === 'email_changed'
            ? ['Adresse email modifiée', 'L’adresse email de connexion de votre compte a été modifiée.']
            : ['Mot de passe modifié', 'Le mot de passe de votre compte a été modifié et vos autres sessions ont été déconnectées.'];

        $this->publisher->publishToUser(
            $user,
            $organization,
            NotificationCategory::Security,
            NotificationSeverity::Critical,
            $title,
            $message,
            '/security',
            'audit_log',
            $auditLogId,
            'auth.'.$event,
        );
    }

    private function backup(Organization $organization, OrganizationBackup $backup, NotificationSeverity $severity, string $title, string $message, string $event): void
    {
        $this->publisher->publish($organization, $this->publisher->recipientsWithPermission($organization, 'organization_backups.view'),
            NotificationCategory::Backup, $severity, $title, $message, '/organization-backups', 'organization_backup', $backup->getKey(), $event);
    }
}
