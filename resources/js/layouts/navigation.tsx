import type { TranslationKey } from '@/i18n/translations';
import type { SharedPageProps } from '@/types/app';
import type { ReactNode } from 'react';

/**
 * Single permission-aware navigation registry. The icon rail, the full drawer
 * and the contextual header tabs are all derived from `buildNavGroups()`;
 * never maintain a second route list elsewhere.
 */
export type NavItem = { href: string; label: string; permission?: string | string[]; needsStore?: boolean; icon: ReactNode };
export type NavSection = { key: string; label: string; icon: ReactNode; items: NavItem[] };
export type NavEntry = NavItem | NavSection;
export type NavGroup = { key: string; label: string; icon: ReactNode; items: NavEntry[] };
export type NavMatch = { groupKey: string; sectionKey: string | null; item: NavItem };

type Tenant = SharedPageProps['tenant'];

export const isNavSection = (entry: NavEntry): entry is NavSection => 'items' in entry;

export const hasPermission = (tenant: Tenant, permission?: string | string[]) =>
    !permission || (Array.isArray(permission) ? permission.some((p) => tenant.permissions.includes(p)) : tenant.permissions.includes(permission));

export function buildNavGroups(t: (key: TranslationKey) => string, tenant: Tenant): NavGroup[] {
    return [
        {
            key: 'dashboard',
            label: t('nav.dashboard'),
            icon: <IconDashboard />,
            items: [
                { href: '/platform', label: t('nav.dashboard'), icon: <IconHome /> },
                { href: '/work', label: t('nav.myWork'), permission: 'follow_ups.view', icon: <IconChecklist /> },
            ],
        },
        {
            key: 'sales',
            label: t('nav.sales'),
            icon: <IconShoppingBag />,
            items: [
                { href: '/pos', label: t('nav.pos'), permission: 'pos.access', needsStore: true, icon: <IconRegister /> },
                { href: '/sales/orders', label: t('nav.orders'), permission: 'sales_orders.view', needsStore: true, icon: <IconClipboard /> },
                { href: '/quotations', label: t('nav.quotations'), permission: 'quotations.view', needsStore: true, icon: <IconFileText /> },
                { href: '/invoices', label: t('nav.invoices'), permission: 'invoices.view', needsStore: true, icon: <IconInvoice /> },
                { href: '/delivery-notes', label: t('nav.deliveryNotes'), permission: 'delivery_notes.view', needsStore: true, icon: <IconTruck /> },
                { href: '/sales/returns', label: t('nav.returns'), permission: 'sales_returns.view', needsStore: true, icon: <IconRotateBack /> },
                { href: '/my-commissions', label: t('nav.myCommissions'), permission: 'commissions.own.view', icon: <IconPercent /> },
            ],
        },
        {
            key: 'catalog-stock',
            label: t('nav.catalog'),
            icon: <IconPackage />,
            items: [
                { href: '/catalog/products', label: t('nav.products'), permission: 'products.view', icon: <IconPackage /> },
                { href: '/catalog/categories', label: t('nav.categories'), permission: 'categories.manage', icon: <IconFolder /> },
                { href: '/catalog/brands', label: t('nav.brands'), permission: 'brands.manage', icon: <IconTag /> },
                { href: '/catalog/products/import', label: t('nav.productImport'), permission: 'products.import', icon: <IconImport /> },
                { href: '/catalog/purchase-prices', label: t('nav.purchasePrices'), permission: 'product_cost.view', icon: <IconBanknote /> },
                { href: '/catalog/non-stock-items', label: t('nav.nonStockItems'), permission: 'quotations.view', icon: <IconAlertBox /> },
            ],
        },
        {
            key: 'inventory',
            label: t('nav.inventory'),
            icon: <IconWarehouse />,
            items: [
                { href: '/inventory/stock', label: t('nav.stockState'), permission: 'inventory.view', icon: <IconWarehouse /> },
                { href: '/inventory/movements', label: t('nav.stockMovements'), permission: 'inventory.view', icon: <IconArrows /> },
                { href: '/inventory/transfers', label: t('nav.transfers'), permission: 'inventory.view', icon: <IconTransfer /> },
                { href: '/inventory/transfer-requests', label: t('nav.transferRequests'), permission: 'inventory.transfer_requests.view', icon: <IconRoute /> },
                { href: '/inventory/warehouses', label: t('nav.warehouses'), permission: 'warehouses.view', icon: <IconPin /> },
            ],
        },
        {
            key: 'purchasing',
            label: t('nav.purchasing'),
            icon: <IconCart />,
            items: [
                { href: '/procurement', label: t('nav.procurement'), permission: 'procurement.view', icon: <IconCart /> },
                { href: '/procurement/purchase-orders', label: t('nav.purchaseOrders'), permission: 'procurement.view', icon: <IconClipboard /> },
                { href: '/procurement/supplier-requests', label: t('nav.supplierRequests'), permission: 'procurement.view', needsStore: true, icon: <IconChecklist /> },
                { href: '/procurement/out-of-stock-articles', label: t('nav.catalogTasks'), permission: 'procurement.view', icon: <IconAlertBox /> },
                { href: '/procurement/suppliers', label: t('nav.suppliers'), permission: 'suppliers.view', icon: <IconBuilding /> },
            ],
        },
        {
            key: 'finance',
            label: t('nav.finance'),
            icon: <IconChart />,
            // Deliberately no `needsStore`: Finance reporting works across the
            // whole organization without the viewer's active store switcher.
            items: [
                { href: '/finance', label: t('nav.overview'), permission: 'finance.view', icon: <IconChart /> },
                {
                    key: 'finance-sales-cash',
                    label: t('nav.salesCollections'),
                    icon: <IconTrending />,
                    items: [
                        { href: '/finance/ventes', label: t('nav.sales'), permission: 'finance.view', icon: <IconShoppingBag /> },
                        { href: '/finance/ca-encaisse', label: t('nav.cashRevenue'), permission: 'finance.view', icon: <IconBanknote /> },
                        { href: '/finance/creances', label: t('nav.receivables'), permission: 'finance.view', icon: <IconClockCoins /> },
                    ],
                },
                {
                    href: '/finance/situation-fin-de-mois',
                    label: t('nav.monthEnd'),
                    permission: 'finance.monthly_situation.view',
                    icon: <IconCalendarChart />,
                },
                {
                    key: 'finance-commissions',
                    label: t('nav.commissions'),
                    icon: <IconPercent />,
                    items: [
                        { href: '/finance/commissions', label: t('nav.commissionDashboard'), permission: 'commissions.ledger.view', icon: <IconChart /> },
                        { href: '/finance/commissions/ledger', label: t('nav.ledger'), permission: 'commissions.ledger.view', icon: <IconList /> },
                        { href: '/finance/commissions/rules', label: t('nav.rules'), permission: 'commissions.rules.view', icon: <IconSettings /> },
                    ],
                },
                { href: '/finance/facturation', label: t('nav.invoices'), permission: 'finance.view', icon: <IconInvoice /> },
                {
                    key: 'finance-treasury',
                    label: t('nav.treasury'),
                    icon: <IconWallet />,
                    items: [
                        { href: '/finance/encaissements', label: t('nav.collections'), permission: 'finance.view', icon: <IconCoinsIn /> },
                        { href: '/payments', label: t('nav.payments'), permission: 'payments.view', needsStore: true, icon: <IconWallet /> },
                        { href: '/financial-accounts', label: t('nav.financialAccounts'), permission: 'financial_accounts.view', icon: <IconBank /> },
                        { href: '/finance/journal', label: t('nav.journal'), permission: 'finance.view', icon: <IconList /> },
                    ],
                },
            ],
        },
        {
            key: 'customers',
            label: t('nav.customers'),
            icon: <IconUsers />,
            items: [
                { href: '/sales/customers', label: t('nav.customers'), permission: 'customers.view', icon: <IconUsers /> },
                { href: '/contacts', label: t('nav.contacts'), permission: 'contacts.view', icon: <IconContact /> },
            ],
        },
        {
            key: 'integrations',
            label: t('nav.integrations'),
            icon: <IconPlug />,
            items: [
                { href: '/integrations/woocommerce', label: 'WooCommerce', permission: 'integrations.view', icon: <IconPlug /> },
                {
                    href: '/integrations/woocommerce/stock-tasks',
                    label: t('nav.wooStock'),
                    permission: 'integrations.woocommerce.stock_tasks.view',
                    icon: <IconSync />,
                },
            ],
        },
        {
            key: 'administration',
            label: t('nav.administration'),
            icon: <IconShield />,
            items: [
                { href: '/activity', label: t('nav.activity'), permission: 'audit.view', icon: <IconList /> },
                { href: '/system-health', label: t('nav.systemOperations'), permission: 'system.health.view', icon: <IconShield /> },
                ...(tenant.organization
                    ? [
                          {
                              href: `/organizations/${tenant.organization.id}/users-access`,
                              label: t('common.usersAccess'),
                              permission: 'members.view',
                              icon: <IconUsers />,
                          },
                      ]
                    : []),
            ],
        },
        {
            key: 'settings',
            label: t('nav.settings'),
            icon: <IconSettings />,
            items: [
                ...(tenant.organization
                    ? [
                          {
                              href: '/document-profile',
                              label: t('nav.company'),
                              permission: ['settings.view', 'settings.update'],
                              icon: <IconBuilding />,
                          },
                          {
                              href: '/document-profile/studio',
                              label: t('nav.pdfStudio'),
                              permission: ['settings.view', 'settings.update'],
                              icon: <IconFileText />,
                          },
                      ]
                    : []),
                { href: '/settings/stores', label: t('nav.stores'), permission: 'stores.view', icon: <IconPin /> },
                { href: '/quotation-settings', label: t('nav.quotationSettings'), permission: ['settings.view', 'settings.update'], icon: <IconFileText /> },
                { href: '/document-stamp', label: t('nav.companyStamp'), permission: ['settings.view', 'settings.update'], icon: <IconStamp /> },
                { href: '/return-policy', label: t('nav.returnPolicy'), permission: ['settings.view', 'settings.update'], icon: <IconRotateBack /> },
                { href: '/email-settings', label: t('nav.email'), permission: ['settings.view', 'settings.update'], icon: <IconMail /> },
                { href: '/organization-backups', label: t('nav.backups'), permission: 'organization_backups.view', icon: <IconShield /> },
                { href: '/settings/data-reset', label: t('nav.dataReset'), permission: 'system.data_reset', icon: <IconTrash /> },
                { href: '/settings/ai-mcp', label: t('nav.aiMcp'), icon: <IconPlug /> },
                { href: '/account/notifications', label: t('common.notifications'), icon: <IconBell /> },
                { href: '/catalog/tax-rates', label: t('nav.taxes'), permission: 'tax_rates.view', icon: <IconPercent /> },
            ],
        },
    ];
}

/** Filters by permission + store context and drops empty sections/groups. */
export function visibleNavGroups(groups: NavGroup[], tenant: Tenant): NavGroup[] {
    const allowed = (item: NavItem) => hasPermission(tenant, item.permission) && (!item.needsStore || tenant.store !== null);
    return groups
        .map((group): NavGroup => {
            const items: NavEntry[] = [];
            group.items.forEach((entry) => {
                if (!isNavSection(entry)) {
                    if (allowed(entry)) items.push(entry);
                    return;
                }
                const visibleItems = entry.items.filter(allowed);
                if (visibleItems.length > 0) items.push({ ...entry, items: visibleItems });
            });
            return { ...group, items };
        })
        .filter((group) => group.items.length > 0);
}

export const firstNavItem = (group: NavGroup): NavItem => {
    const entry = group.items[0]!;
    return isNavSection(entry) ? entry.items[0]! : entry;
};

/** Longest-prefix match so deep links (e.g. /sales/orders/42) highlight their parent. */
export function matchActiveNav(groups: NavGroup[], currentPath: string): NavMatch | undefined {
    const candidates: NavMatch[] = [];
    groups.forEach((group) =>
        group.items.forEach((entry) => {
            if (isNavSection(entry)) entry.items.forEach((item) => candidates.push({ groupKey: group.key, sectionKey: entry.key, item }));
            else candidates.push({ groupKey: group.key, sectionKey: null, item: entry });
        }),
    );
    return candidates
        .filter(({ item }) => currentPath === item.href || currentPath.startsWith(`${item.href}/`))
        .sort((a, b) => b.item.href.length - a.item.href.length)[0];
}

export type QuickAction = { href: string; label: string; icon: ReactNode };

/**
 * Create shortcuts shared by the header "Créer" menu and the command palette.
 * Each one routes to an existing workflow and is shown only when permitted.
 */
export function buildQuickActions(t: (key: TranslationKey) => string, tenant: Tenant): QuickAction[] {
    const can = (permission: string) => tenant.permissions.includes(permission);
    return [
        { href: '/pos', label: t('platform.newSale'), icon: <IconRegister />, show: can('pos.access') && !!tenant.store },
        { href: '/procurement', label: t('shell.newPurchase'), icon: <IconCart />, show: can('procurement.manage') },
        { href: '/catalog/products/create', label: t('platform.addProduct'), icon: <IconPackage />, show: can('products.create') },
        { href: '/contacts/create', label: t('shell.addContact'), icon: <IconContact />, show: can('contacts.create') },
    ]
        .filter((action) => action.show)
        .map(({ href, label, icon }) => ({ href, label, icon }));
}

/** Index routes whose controllers accept `?search=` (verified server-side). */
export const SEARCHABLE_INDEX_HREFS = [
    '/catalog/products',
    '/sales/orders',
    '/sales/customers',
    '/quotations',
    '/invoices',
    '/payments',
    '/procurement/purchase-orders',
    '/procurement/suppliers',
    '/contacts',
] as const;

/* --- icons (currentColor, sized by the `size` prop) --- */
const s = { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.7, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };
export function IconDashboard() { return <svg {...s}><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>; }
export function IconHome() { return <svg {...s}><path d="M4 11 12 4l8 7M6 10v10h12V10" /></svg>; }
export function IconRegister() { return <svg {...s}><rect x="3" y="4" width="18" height="13" rx="2" /><path d="M3 9h18M8 21h8M12 17v4" /></svg>; }
export function IconShoppingBag() { return <svg {...s}><path d="M6 8h12l-1 13H7L6 8Z" /><path d="M9 8a3 3 0 0 1 6 0" /></svg>; }
export function IconClipboard() { return <svg {...s}><path d="M8 4h8l1 3H7l1-3Z" /><path d="M7 6H5v15h14V6h-2M8 12h8M8 16h6" /></svg>; }
export function IconFileText() { return <svg {...s}><path d="M7 3h7l5 5v13H7V3Z" /><path d="M14 3v5h5M10 13h6M10 17h6" /></svg>; }
export function IconTruck() { return <svg {...s}><path d="M3 6h11v10H3V6ZM14 10h4l3 3v3h-7v-6Z" /><circle cx="7" cy="18" r="2" /><circle cx="18" cy="18" r="2" /></svg>; }
export function IconRotateBack() { return <svg {...s}><path d="M9 7H4v-5M4 7a9 9 0 1 1 3 6.7" /></svg>; }
export function IconPackage() { return <svg {...s}><path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z" /><path d="M4 7l8 4 8-4M12 11v10" /></svg>; }
export function IconTag() { return <svg {...s}><path d="M4 4h8l8 8-8 8-8-8V4Z" /><circle cx="8.5" cy="8.5" r="1.3" /></svg>; }
export function IconImport() { return <svg {...s}><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" /></svg>; }
export function IconAlertBox() { return <svg {...s}><path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z" /><path d="M12 8v5M12 16h.01" /></svg>; }
export function IconBoxes() { return <svg {...s}><path d="M7 3h7l4 4v7l-7 4-7-4V7l3-4Z" /><path d="M4 7l7 4 7-4M11 11v7M14 3l-7 4" /></svg>; }
export function IconWarehouse() { return <svg {...s}><path d="M3 10 12 4l9 6v10H3V10Z" /><path d="M7 20v-7h10v7M7 13h10M10 16h4" /></svg>; }
export function IconPin() { return <svg {...s}><path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z" /><circle cx="12" cy="10" r="2.4" /></svg>; }
export function IconTransfer() { return <svg {...s}><path d="M4 8h13l-3-3M20 16H7l3 3" /></svg>; }
export function IconArrows() { return <svg {...s}><path d="M7 7h13l-3-3M17 17H4l3 3M5 12h14" /></svg>; }
export function IconRoute() { return <svg {...s}><circle cx="6" cy="6" r="2" /><circle cx="18" cy="18" r="2" /><path d="M8 6h4a4 4 0 0 1 0 8h-1a4 4 0 0 0 0 8h7" /></svg>; }
export function IconList() { return <svg {...s}><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" /></svg>; }
export function IconCart() { return <svg {...s}><path d="M4 4h2l2 11h10l2-8H7" /><circle cx="10" cy="20" r="1.5" /><circle cx="17" cy="20" r="1.5" /></svg>; }
export function IconUsers() { return <svg {...s}><circle cx="9" cy="8" r="3" /><path d="M3 20c0-3.3 2.7-5 6-5s6 1.7 6 5M16 6.5a3 3 0 0 1 0 5.5M21 20c0-2.6-1.5-4.2-3.5-4.7" /></svg>; }
export function IconContact() { return <svg {...s}><rect x="4" y="3" width="16" height="18" rx="2" /><circle cx="12" cy="9" r="2.5" /><path d="M8 17c.5-2.2 2-3.5 4-3.5s3.5 1.3 4 3.5M2 7h4M2 12h4M2 17h4" /></svg>; }
export function IconChart() { return <svg {...s}><path d="M4 19V5M4 19h16" /><path d="M8 15v-4M12 15V8M16 15v-7" /></svg>; }
export function IconTrending() { return <svg {...s}><path d="M4 17 9 12l4 4 7-8" /><path d="M15 8h5v5" /></svg>; }
export function IconBanknote() { return <svg {...s}><rect x="3" y="6" width="18" height="12" rx="2" /><circle cx="12" cy="12" r="3" /><path d="M6 9h.01M18 15h.01" /></svg>; }
export function IconClockCoins() { return <svg {...s}><circle cx="9" cy="10" r="5" /><path d="M9 7v3l2 2M15 14c3 0 5 1.2 5 2.7s-2 2.7-5 2.7-5-1.2-5-2.7" /></svg>; }
export function IconCalendarChart() { return <svg {...s}><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M7 3v4M17 3v4M3 10h18M7 17l3-3 3 2 4-4" /></svg>; }
export function IconCoinsIn() { return <svg {...s}><ellipse cx="12" cy="17" rx="7" ry="3" /><path d="M5 17v3c0 1.7 3.1 3 7 3s7-1.3 7-3v-3M12 3v9M8 8l4 4 4-4" /></svg>; }
export function IconInvoice() { return <svg {...s}><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z" /><path d="M9 8h6M9 12h6" /></svg>; }
export function IconWallet() { return <svg {...s}><path d="M4 7a2 2 0 0 1 2-2h11v4M4 7v10a2 2 0 0 0 2 2h13V9H6a2 2 0 0 1-2-2Z" /><circle cx="16" cy="14" r="1.3" /></svg>; }
export function IconBank() { return <svg {...s}><path d="M4 10h16L12 4 4 10ZM6 10v8M10 10v8M14 10v8M18 10v8M4 20h16" /></svg>; }
export function IconBuilding() { return <svg {...s}><path d="M5 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16M9 8h.01M12 8h.01M9 12h.01M12 12h.01M9 16h.01M12 16h.01M17 21V10h2a2 2 0 0 1 2 2v9" /></svg>; }
export function IconPlug() { return <svg {...s}><path d="M9 2v6M15 2v6M7 8h10v3a5 5 0 0 1-10 0V8ZM12 16v6" /></svg>; }
export function IconSync() { return <svg {...s}><path d="M4 12a8 8 0 0 1 13.7-5.7L20 8M20 4v4h-4M20 12a8 8 0 0 1-13.7 5.7L4 16M4 20v-4h4" /></svg>; }
export function IconShield() { return <svg {...s}><path d="M12 3 5 6v5c0 4.5 2.8 8 7 10 4.2-2 7-5.5 7-10V6l-7-3Z" /></svg>; }
export function IconChecklist() { return <svg {...s}><path d="M9 6h11M9 12h11M9 18h11" /><path d="m3.5 6 1.5 1.5L7.5 5M3.5 12l1.5 1.5L7.5 11M3.5 18l1.5 1.5L7.5 17" /></svg>; }
export function IconSettings() { return <svg {...s}><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z" /><path d="M3 12h2M19 12h2M12 3v2M12 19v2M5.6 5.6 7 7M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4" /></svg>; }
export function IconTrash() { return <svg {...s}><path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5" /></svg>; }
export function IconFolder() { return <svg {...s}><path d="M3 6h7l2 3h9v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6Z" /></svg>; }
export function IconPercent() { return <svg {...s}><path d="M19 5 5 19" /><circle cx="7" cy="7" r="2" /><circle cx="17" cy="17" r="2" /></svg>; }
export function IconMail() { return <svg {...s}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></svg>; }
export function IconBell() { return <svg {...s}><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>; }
export function IconStamp() { return <svg {...s}><circle cx="12" cy="8" r="5" /><path d="M9 8h6M9 6.5h6M9 9.5h4M6 21h12l-1.5-5h-9L6 21Z" /></svg>; }
export function DisclosureChevron({ open, direction }: { open: boolean; direction: 'ltr' | 'rtl' }) {
    const path = open ? 'm5 9 7 7 7-7' : direction === 'rtl' ? 'm15 5-7 7 7 7' : 'm9 5 7 7-7 7';
    return <span className="directional-icon shrink-0 text-ink-faint"><svg {...s} width={14} height={14}><path d={path} /></svg></span>;
}
export function IconCollapse({ direction }: { direction: 'ltr' | 'rtl' }) { return <svg {...s}><path d={direction === 'rtl' ? 'M10 6l6 6-6 6' : 'M14 6 8 12l6 6'} /></svg>; }
export function IconMenu() { return <svg {...s}><path d="M4 6h16M4 12h16M4 18h16" /></svg>; }
export function IconClose() { return <svg {...s}><path d="M6 6l12 12M18 6 6 18" /></svg>; }
export function IconPlus() { return <svg {...s}><path d="M12 5v14M5 12h14" /></svg>; }
export function IconSun() { return <svg {...s}><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></svg>; }
export function IconMoon() { return <svg {...s}><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" /></svg>; }
export function IconSearch() { return <svg {...s}><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>; }
export function IconChevronDown() { return <svg {...s} width={14} height={14}><path d="m6 9 6 6 6-6" /></svg>; }
export function IconMore() { return <svg {...s}><circle cx="5" cy="12" r="1" /><circle cx="12" cy="12" r="1" /><circle cx="19" cy="12" r="1" /></svg>; }
export function IconStore() { return <svg {...s}><path d="M4 9h16l-1-5H5L4 9ZM5 9v11h14V9M9 20v-6h6v6" /></svg>; }
