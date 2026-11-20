<?php

namespace App\Support\Roles;

/**
 * U-5: plain-language presentation of a role's permission set.
 *
 * Pure presentation mapping: it neither defines nor grants permissions (the
 * catalogue stays in config/platform.php). Each capability is "any of these real
 * permission keys"; each conflict is "all of these capabilities held together".
 * The same definition is sent to the roles editor (Inertia prop) so the browser
 * and the server tests use one source of truth; labels live in the i18n files
 * under `roles.cap.<id>` and `roles.conflict.<id>.*`.
 */
final class RoleCapabilitySummary
{
    /** @var array<string, list<string>> capability id => permission keys (any of) */
    public const CAPABILITIES = [
        'sell' => ['pos.access', 'sales_orders.create', 'quotations.create'],
        'confirm_orders' => ['sales_orders.confirm'],
        'discount_or_price' => ['sales_orders.override_price', 'sales_orders.apply_discount'],
        'cancel_orders' => ['sales_orders.cancel'],
        'returns_exchanges' => ['sales_returns.create', 'sales_returns.receive', 'sales_exchanges.create', 'sales_exchanges.process'],
        'take_payments' => ['payments.create'],
        'cancel_payments' => ['payments.reverse'],
        'refund_payments' => ['payments.refund'],
        'issue_invoices' => ['invoices.issue'],
        'issue_credit_notes' => ['credit_notes.issue'],
        'purchasing' => ['procurement.manage', 'procurement.receive', 'suppliers.manage'],
        'stock' => ['inventory.adjust', 'inventory.transfer', 'inventory.opening', 'inventory.consume'],
        'catalog' => ['products.create', 'products.update', 'products.archive', 'products.import'],
        'customers' => ['customers.create', 'customers.update'],
        'finance_reports' => ['finance.view', 'finance.receivables.view', 'finance.export'],
        'finance_periods' => ['finance.periods.reopen', 'finance.outgoing_payments.manage', 'finance.monthly_situation.manage'],
        'users_and_roles' => ['members.create', 'members.update', 'members.delete', 'roles.create', 'roles.update', 'roles.assign-permissions'],
        'settings_and_backups' => ['settings.update', 'organization_backups.restore', 'system.data_reset'],
    ];

    /** @var array<string, list<string>> conflict id => capability ids that must ALL be held */
    public const CONFLICTS = [
        // Segregation of duties: take the money, undo the entry AND hand money back.
        'payments_cash_control' => ['take_payments', 'cancel_payments', 'refund_payments'],
    ];

    /**
     * @param  iterable<string>  $permissionKeys
     * @return array{capabilities: list<string>, conflicts: list<string>}
     */
    public static function summarize(iterable $permissionKeys): array
    {
        $held = [];
        foreach ($permissionKeys as $key) {
            $held[(string) $key] = true;
        }

        $capabilities = [];
        foreach (self::CAPABILITIES as $id => $keys) {
            foreach ($keys as $key) {
                if (isset($held[$key])) {
                    $capabilities[] = $id;
                    break;
                }
            }
        }

        $conflicts = [];
        foreach (self::CONFLICTS as $id => $required) {
            if (array_diff($required, $capabilities) === []) {
                $conflicts[] = $id;
            }
        }

        return ['capabilities' => $capabilities, 'conflicts' => $conflicts];
    }

    /** @return array{capabilities: array<string, list<string>>, conflicts: array<string, list<string>>} */
    public static function definition(): array
    {
        return ['capabilities' => self::CAPABILITIES, 'conflicts' => self::CONFLICTS];
    }
}
