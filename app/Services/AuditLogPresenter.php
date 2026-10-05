<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Str;

final class AuditLogPresenter
{
    /** @var array<string, array{label: string, prefixes: list<string>}> */
    private const MODULES = [
        'security' => ['label' => 'Sécurité', 'prefixes' => ['auth.', 'two_factor.', 'email.', 'role.', 'organization_membership.', 'user_invitation.']],
        'catalog' => ['label' => 'Catalogue', 'prefixes' => ['product.', 'product_variant.', 'catalog.', 'category.', 'brand.', 'non_stock_item.', 'contact.']],
        'inventory' => ['label' => 'Stock', 'prefixes' => ['inventory.', 'warehouse.', 'stock_transfer.', 'transfer_request.', 'auto_replenishment.']],
        'sales' => ['label' => 'Ventes', 'prefixes' => ['sales_order.', 'customer.', 'customer_return.', 'customer_exchange.', 'return_policy.', 'out_of_stock_article.']],
        'documents' => ['label' => 'Documents', 'prefixes' => ['invoice.', 'quotation.', 'delivery_note.', 'credit_note.', 'document_', 'organization_document_stamp.']],
        'payments' => ['label' => 'Paiements', 'prefixes' => ['payment.', 'financial_account.']],
        'procurement' => ['label' => 'Achats', 'prefixes' => ['procurement.', 'supplier.']],
        'integrations' => ['label' => 'Intégrations', 'prefixes' => ['woocommerce.']],
        'backups' => ['label' => 'Sauvegardes', 'prefixes' => ['organization_backup.', 'organization_cloud_backup.']],
        'settings' => ['label' => 'Paramètres', 'prefixes' => ['organization.', 'store.', 'organization_mail_setting.', 'smtp.', 'document_profile.', 'quotation_settings.']],
    ];

    /** @var array<string, string> */
    private const EVENT_LABELS = [
        'product.created' => 'Produit créé',
        'product.updated' => 'Produit modifié',
        'product.archived' => 'Produit archivé',
        'role.permissions_changed' => 'Permissions du rôle modifiées',
        'invoice.issued' => 'Facture émise',
        'invoice.cancelled' => 'Facture annulée',
        'invoice.correction_issued' => 'Correction de facture émise',
        'quotation.issued' => 'Devis émis',
        'delivery_note.issued' => 'Bon de livraison émis',
        'inventory.adjusted' => 'Stock ajusté',
        'inventory.opening_stock' => 'Stock initial enregistré',
        'inventory.transferred' => 'Stock transféré',
        'payment.posted' => 'Paiement enregistré',
        'payment.refunded' => 'Paiement remboursé',
        'payment.reversed' => 'Paiement annulé',
        'woocommerce.integration_created' => 'Configuration WooCommerce créée',
        'woocommerce.integration_updated' => 'Configuration WooCommerce modifiée',
        'organization_mail_setting.created' => 'Configuration SMTP créée',
        'organization_mail_setting.updated' => 'Configuration SMTP modifiée',
        'organization_backup.restore_started' => 'Restauration de sauvegarde démarrée',
        'organization_backup.restored' => 'Sauvegarde restaurée',
        'organization_backup.restore_failed' => 'Restauration de sauvegarde échouée',
        'two_factor.enabled' => 'Authentification à deux facteurs activée',
        'two_factor.disabled' => 'Authentification à deux facteurs désactivée',
        'auth.password_changed' => 'Mot de passe modifié',
        'auth.session_revoked' => 'Session révoquée',
    ];

    /** @var array<string, string> */
    private const ACTION_LABELS = [
        'created' => 'créé', 'updated' => 'modifié', 'changed' => 'modifié',
        'deleted' => 'supprimé', 'archived' => 'archivé', 'issued' => 'émis',
        'cancelled' => 'annulé', 'confirmed' => 'confirmé', 'completed' => 'terminé',
        'started' => 'démarré', 'failed' => 'échoué', 'restored' => 'restauré',
        'enabled' => 'activé', 'disabled' => 'désactivé', 'sent' => 'envoyé',
        'posted' => 'enregistré', 'refunded' => 'remboursé', 'reversed' => 'annulé',
    ];

    /** @return array<string, mixed> */
    public function present(AuditLog $log): array
    {
        $module = $this->module($log->event);
        $oldValues = SensitiveDataRedactor::sanitizeArray($log->old_values ?? []);
        $newValues = SensitiveDataRedactor::sanitizeArray($log->new_values ?? []);
        $reference = $this->reference($newValues, $oldValues, $log->auditable_id);
        $action = $this->eventLabel($log->event, $module['label']);
        $actor = $log->actor?->name ?? ($log->actor_id ? 'Utilisateur supprimé' : 'Système');

        return [
            'id' => $log->getKey(),
            'created_at' => $log->created_at?->toIso8601String(),
            'actor' => $log->actor ? $log->actor->only(['id', 'name', 'email']) : null,
            'actor_label' => $actor,
            'organization' => $log->organization?->only(['id', 'name']),
            'store' => $log->store?->only(['id', 'name', 'code']),
            'module' => $module,
            'event' => $log->event,
            'action_label' => $action,
            'target' => [
                'type' => $this->targetLabel($log->auditable_type),
                'id' => $log->auditable_id,
                'reference' => $reference,
            ],
            'description' => trim($actor.' · '.$action.($reference ? ' · '.$reference : '')),
            'ip_address' => $log->ip_address,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ];
    }

    /** @return array{key: string, label: string} */
    public function module(string $event): array
    {
        foreach (self::MODULES as $key => $definition) {
            foreach ($definition['prefixes'] as $prefix) {
                if (str_starts_with($event, $prefix)) {
                    return ['key' => $key, 'label' => $definition['label']];
                }
            }
        }

        return ['key' => 'other', 'label' => 'Autre'];
    }

    /** @return list<string> */
    public function modulePrefixes(string $module): array
    {
        return self::MODULES[$module]['prefixes'] ?? [];
    }

    /** @param list<string> $events @return list<array{key: string, label: string}> */
    public function availableModules(array $events): array
    {
        return collect($events)->map(fn (string $event) => $this->module($event))
            ->unique('key')->sortBy('label')->values()->all();
    }

    public function eventLabel(string $event, ?string $moduleLabel = null): string
    {
        if (isset(self::EVENT_LABELS[$event])) {
            return self::EVENT_LABELS[$event];
        }

        $action = Str::afterLast($event, '.');
        $translated = self::ACTION_LABELS[$action] ?? Str::lower(Str::headline($action));

        return ($moduleLabel ?? $this->module($event)['label']).' · '.$translated;
    }

    /** @param array<string|int, mixed> $newValues @param array<string|int, mixed> $oldValues */
    private function reference(array $newValues, array $oldValues, int|string|null $id): ?string
    {
        foreach (['invoice_number', 'quotation_number', 'delivery_note_number', 'credit_note_number', 'order_number', 'payment_number', 'return_number', 'exchange_number', 'reference', 'sku', 'name'] as $key) {
            $value = $newValues[$key] ?? $oldValues[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return Str::limit((string) $value, 120);
            }
        }

        return $id !== null ? '#'.$id : null;
    }

    private function targetLabel(?string $type): ?string
    {
        if (! $type) {
            return null;
        }

        return match (class_basename($type)) {
            'Product', 'ProductVariant' => 'Produit',
            'SalesOrder' => 'Commande',
            'Invoice' => 'Facture',
            'Quotation' => 'Devis',
            'DeliveryNote' => 'Bon de livraison',
            'Payment', 'PaymentRefund' => 'Paiement',
            'InventoryMovement' => 'Mouvement de stock',
            'User' => 'Utilisateur',
            'Role' => 'Rôle',
            default => Str::headline(class_basename($type)),
        };
    }
}
