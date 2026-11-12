<?php

namespace App\Services\Sandbox;

use App\Models\Organization;
use App\Models\User;

/**
 * Content of the Test Mode home: which modules are open in Test Mode (their
 * routes are in the allowlists — SandboxSalesRoutes, SandboxDocumentRules,
 * SandboxInventoryGuard) and which are still unavailable. Display only: the
 * enforcement is the route allowlist in ResolveSandboxContext.
 */
final class SandboxModuleCatalog
{
    /** @var list<array{label: string, href: string, permission: string, hint: string}> */
    private const AVAILABLE = [
        ['label' => 'POS', 'href' => '/pos', 'permission' => 'pos.access', 'hint' => 'Paniers, encaissement simulé'],
        ['label' => 'Devis', 'href' => '/quotations', 'permission' => 'quotations.view', 'hint' => 'Brouillons et conversion en commande'],
        ['label' => 'Commandes', 'href' => '/sales/orders', 'permission' => 'sales_orders.view', 'hint' => 'Création, confirmation, annulation'],
        ['label' => 'Factures', 'href' => '/invoices', 'permission' => 'invoices.view', 'hint' => 'Factures de test (TEST-FAC)'],
        ['label' => 'Bons de livraison', 'href' => '/delivery-notes', 'permission' => 'delivery_notes.view', 'hint' => 'Documents de test (TEST-BL), sans mouvement de stock'],
        ['label' => 'Stock test', 'href' => '/inventory/stock', 'permission' => 'inventory.view', 'hint' => 'Stock indépendant de la Production'],
        ['label' => 'Transferts de stock', 'href' => '/inventory/transfers', 'permission' => 'inventory.view', 'hint' => 'Entre entrepôts, stock test uniquement'],
        ['label' => 'Paiements test', 'href' => '/payments', 'permission' => 'payments.view', 'hint' => 'Paiements simulés, sans effet sur la caisse réelle'],
        ['label' => 'Approvisionnement test', 'href' => '/procurement', 'permission' => 'procurement.view', 'hint' => 'Besoins fournisseurs (TEST-PR) sur commandes et devis test'],
        ['label' => 'Commandes fournisseurs test', 'href' => '/procurement/purchase-orders', 'permission' => 'procurement.view', 'hint' => 'TEST-PO et réception : seul le stock test augmente'],
        ['label' => 'Demandes de disponibilité', 'href' => '/procurement/supplier-requests', 'permission' => 'procurement.view', 'hint' => 'Sourcing fournisseur des devis et paniers test'],
    ];

    /** @var list<string> */
    private const UNAVAILABLE = [
        'Expédition et remise au client',
        'Retours et échanges (avoirs, remboursements de retour)',
        'Fournisseurs, catalogue fournisseurs et articles hors stock (données partagées, lecture seule)',
        'Demandes de transfert entre entrepôts (aucun transfert interne généré par une réception test)',
        'Rapports finance, créances et commissions',
        'Catalogue, clients et paramètres (données partagées, lecture seule)',
        'Intégrations (WooCommerce…)',
        'Réinitialisation du mode test',
    ];

    /** @return list<array{label: string, href: string, hint: string}> modules the user may open */
    public function available(User $user, Organization $organization): array
    {
        return collect(self::AVAILABLE)
            ->filter(fn (array $module) => $user->hasPermission($organization, $module['permission']))
            ->map(fn (array $module) => ['label' => $module['label'], 'href' => $module['href'], 'hint' => $module['hint']])
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function unavailable(): array
    {
        return self::UNAVAILABLE;
    }
}
