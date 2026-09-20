import { BrandLockup, BrandMark } from '@/components/ui/brand';
import { useToast } from '@/components/ui/toast';
import type { SharedPageProps } from '@/types/app';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { PropsWithChildren, ReactNode } from 'react';

type NavItem = { href: string; label: string; permission?: string | string[]; needsStore?: boolean; icon: ReactNode };
type NavGroup = { key: string; label: string; icon: ReactNode; items: NavItem[] };

const SIDEBAR_KEY = 'shell.sidebar.collapsed';

export default function ApplicationShell({ children, wide = false, flush = false }: PropsWithChildren<{ wide?: boolean; flush?: boolean }>) {
    const page = usePage<SharedPageProps>();
    const { auth, tenant, flash } = page.props;
    const currentPath = page.url.split('?')[0];

    // Bridge server flash("success", …) redirects into the toast system so every
    // module's "Enregistré" / "Lancé" confirmation is delivered consistently.
    const toast = useToast();
    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
    }, [flash?.success, toast]);

    const [collapsed, setCollapsed] = useState(false);
    useEffect(() => {
        try {
            setCollapsed(window.localStorage.getItem(SIDEBAR_KEY) === '1');
        } catch {
            /* storage unavailable */
        }
    }, []);
    const toggleCollapsed = () => {
        setCollapsed((value) => {
            const next = !value;
            try {
                window.localStorage.setItem(SIDEBAR_KEY, next ? '1' : '0');
            } catch {
                /* storage unavailable */
            }
            return next;
        });
    };

    // Mobile navigation drawer (the desktop sidebar is fixed/always visible from `lg:` up).
    const [navOpen, setNavOpen] = useState(false);
    useEffect(() => {
        setNavOpen(false);
    }, [currentPath]);
    useEffect(() => {
        if (!navOpen) return;
        const previousOverflow = document.documentElement.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        const handler = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setNavOpen(false);
        };
        window.addEventListener('keydown', handler);
        return () => {
            document.documentElement.style.overflow = previousOverflow;
            window.removeEventListener('keydown', handler);
        };
    }, [navOpen]);

    const groups: NavGroup[] = [
        {
            key: 'dashboard',
            label: 'Tableau de bord',
            icon: <IconDashboard />,
            items: [{ href: '/platform', label: 'Tableau de bord', icon: <IconHome /> }],
        },
        {
            key: 'sales',
            label: 'Ventes',
            icon: <IconShoppingBag />,
            items: [
                { href: '/pos', label: 'Point de vente', permission: 'pos.access', needsStore: true, icon: <IconRegister /> },
                { href: '/sales/orders', label: 'Commandes', permission: 'sales_orders.view', needsStore: true, icon: <IconClipboard /> },
                { href: '/quotations', label: 'Devis', permission: 'quotations.view', needsStore: true, icon: <IconFileText /> },
                { href: '/invoices', label: 'Factures', permission: 'invoices.view', needsStore: true, icon: <IconInvoice /> },
                { href: '/delivery-notes', label: 'Bons de livraison', permission: 'delivery_notes.view', needsStore: true, icon: <IconTruck /> },
                { href: '/sales/returns', label: 'Retours / Avoirs', permission: 'sales_returns.view', needsStore: true, icon: <IconRotateBack /> },
            ],
        },
        {
            key: 'catalog-stock',
            label: 'Catalogue & stock',
            icon: <IconBoxes />,
            items: [
                { href: '/catalog/products', label: 'Produits', permission: 'products.view', icon: <IconPackage /> },
                { href: '/catalog/categories', label: 'Catégories', permission: 'categories.manage', icon: <IconFolder /> },
                { href: '/catalog/brands', label: 'Marques', permission: 'brands.manage', icon: <IconTag /> },
                { href: '/catalog/products/import', label: 'Import produits', permission: 'products.import', icon: <IconImport /> },
                { href: '/catalog/non-stock-items', label: 'Articles hors stock', permission: 'quotations.view', icon: <IconAlertBox /> },
                { href: '/inventory/stock', label: 'État du stock', permission: 'inventory.view', icon: <IconWarehouse /> },
                { href: '/inventory/movements', label: 'Mouvements de stock', permission: 'inventory.view', icon: <IconArrows /> },
                { href: '/inventory/transfers', label: 'Transferts', permission: 'inventory.view', icon: <IconTransfer /> },
                { href: '/inventory/transfer-requests', label: 'Demandes de transfert', permission: 'inventory.transfer_requests.view', icon: <IconRoute /> },
                { href: '/inventory/warehouses', label: 'Entrepôts', permission: 'warehouses.view', icon: <IconPin /> },
            ],
        },
        {
            key: 'purchasing',
            label: 'Achats',
            icon: <IconCart />,
            items: [
                { href: '/procurement', label: 'Approvisionnements', permission: 'procurement.view', icon: <IconCart /> },
                { href: '/procurement/out-of-stock-articles', label: 'Articles à cataloguer', permission: 'procurement.view', icon: <IconAlertBox /> },
                { href: '/procurement/suppliers', label: 'Fournisseurs', permission: 'suppliers.view', icon: <IconBuilding /> },
            ],
        },
        {
            key: 'finance',
            label: 'Finance',
            icon: <IconChart />,
            // Deliberately no `needsStore`: Finance reporting works across the
            // whole organization without the viewer's active store switcher.
            items: [
                { href: '/finance', label: 'Vue d’ensemble', permission: 'finance.view', icon: <IconChart /> },
                { href: '/finance/ventes', label: 'Ventes', permission: 'finance.view', icon: <IconTrending /> },
                { href: '/finance/ca-encaisse', label: 'CA encaissé', permission: 'finance.view', icon: <IconBanknote /> },
                { href: '/finance/creances', label: 'Créances', permission: 'finance.view', icon: <IconClockCoins /> },
                { href: '/finance/encaissements', label: 'Encaissements', permission: 'finance.view', icon: <IconWallet /> },
                { href: '/finance/facturation', label: 'Facturation', permission: 'finance.view', icon: <IconInvoice /> },
                { href: '/finance/journal', label: 'Journal', permission: 'finance.view', icon: <IconList /> },
                { href: '/payments', label: 'Paiements', permission: 'payments.view', needsStore: true, icon: <IconWallet /> },
                { href: '/financial-accounts', label: 'Comptes financiers', permission: 'financial_accounts.view', icon: <IconBank /> },
            ],
        },
        {
            key: 'customers',
            label: 'Clients',
            icon: <IconUsers />,
            items: [{ href: '/sales/customers', label: 'Clients', permission: 'customers.view', icon: <IconUsers /> }],
        },
        {
            key: 'integrations',
            label: 'Intégrations',
            icon: <IconPlug />,
            items: [
                { href: '/integrations/woocommerce', label: 'WooCommerce', permission: 'integrations.view', icon: <IconPlug /> },
                {
                    href: '/integrations/woocommerce/stock-tasks',
                    label: 'Stock WooCommerce',
                    permission: 'integrations.woocommerce.stock_tasks.view',
                    icon: <IconSync />,
                },
            ],
        },
        {
            key: 'administration',
            label: 'Administration',
            icon: <IconShield />,
            items: [
                ...(tenant.organization
                    ? [
                          {
                              href: `/organizations/${tenant.organization.id}/users-access`,
                              label: 'Utilisateurs & accès',
                              permission: 'members.view',
                              icon: <IconUsers />,
                          },
                      ]
                    : []),
            ],
        },
        {
            key: 'settings',
            label: 'Paramètres',
            icon: <IconSettings />,
            items: [
                ...(tenant.organization
                    ? [
                          {
                              href: '/document-profile',
                              label: 'Organisation / société',
                              permission: ['settings.view', 'settings.update'],
                              icon: <IconBuilding />,
                          },
                      ]
                    : []),
                { href: '/quotation-settings', label: 'Documents · Devis', permission: ['settings.view', 'settings.update'], icon: <IconFileText /> },
                { href: '/document-stamp', label: 'Cachet de l’entreprise', permission: ['settings.view', 'settings.update'], icon: <IconStamp /> },
                { href: '/return-policy', label: 'Politique de retour', permission: ['settings.view', 'settings.update'], icon: <IconRotateBack /> },
                { href: '/email-settings', label: 'Email / SMTP', permission: ['settings.view', 'settings.update'], icon: <IconMail /> },
                { href: '/catalog/tax-rates', label: 'Taxes (TVA)', permission: 'tax_rates.view', icon: <IconPercent /> },
            ],
        },
    ];

    const hasPermission = (permission?: string | string[]) =>
        !permission || (Array.isArray(permission) ? permission.some((p) => tenant.permissions.includes(p)) : tenant.permissions.includes(permission));
    const allowed = (item: NavItem) => hasPermission(item.permission) && (!item.needsStore || tenant.store !== null);
    const visibleGroups = groups
        .map((group) => ({ ...group, items: group.items.filter(allowed) }))
        .filter((group) => group.items.length > 0);
    const activeMatch = visibleGroups
        .flatMap((group) => group.items.map((item) => ({ groupKey: group.key, item })))
        .filter(({ item }) => currentPath === item.href || currentPath.startsWith(`${item.href}/`))
        .sort((a, b) => b.item.href.length - a.item.href.length)[0];
    const activeHref = activeMatch?.item.href;
    const activeGroupKey = activeMatch?.groupKey;
    const [openGroup, setOpenGroup] = useState<string>('');

    useEffect(() => {
        if (activeGroupKey) {
            setOpenGroup(activeGroupKey);
        } else if (visibleGroups[0]) {
            setOpenGroup(visibleGroups[0].key);
        }
        // Keep the active route's parent open after Inertia navigation, but do
        // not re-run this after local accordion clicks; otherwise manual opens
        // are immediately overwritten by the still-active route group.
    }, [currentPath, activeGroupKey]);

    const orgStoreSwitcher = (
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
            <select
                aria-label="Organisation"
                value={tenant.organization?.id ?? ''}
                onChange={(event) => event.target.value && router.post(`/context/organizations/${event.target.value}`)}
                className="h-11 max-w-full flex-1 rounded-field border border-line-strong bg-surface px-3 text-sm font-medium text-ink transition-soft focus:border-primary focus:outline-none sm:h-auto sm:max-w-56 sm:flex-none sm:py-2"
            >
                <option value="">Organisation</option>
                {tenant.organizations.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.name}
                    </option>
                ))}
            </select>
            <select
                aria-label="Magasin"
                value={tenant.store?.id ?? ''}
                onChange={(event) => event.target.value && router.post(`/context/stores/${event.target.value}`)}
                disabled={!tenant.organization}
                className="h-11 max-w-full flex-1 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink transition-soft focus:border-primary focus:outline-none disabled:opacity-50 sm:h-auto sm:max-w-56 sm:flex-none sm:py-2"
            >
                <option value="">Magasin</option>
                {tenant.stores.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.name} · {item.code}
                    </option>
                ))}
            </select>
        </div>
    );

    const navigation = (dense: boolean) => (
        <nav className={dense ? 'space-y-1.5' : 'space-y-1'}>
            {visibleGroups.map((group) => {
                const open = openGroup === group.key;
                const groupActive = activeGroupKey === group.key;
                const firstItem = group.items[0];

                if (dense) {
                    return (
                        <Link
                            key={group.key}
                            href={firstItem.href}
                            title={group.label}
                            aria-current={groupActive ? 'page' : undefined}
                            className={`relative flex size-10 items-center justify-center rounded-field transition-soft ${
                                groupActive ? 'bg-sage text-primary' : 'text-ink-faint hover:bg-sage/70 hover:text-ink'
                            }`}
                        >
                            {groupActive && <span className="absolute left-0 top-2 bottom-2 w-0.5 rounded-full bg-primary" />}
                            {group.icon}
                        </Link>
                    );
                }

                return (
                    <div key={group.key}>
                        <button
                            type="button"
                            onClick={() => setOpenGroup(open ? '' : group.key)}
                            aria-expanded={open}
                            className={`group flex w-full items-center gap-2.5 rounded-field px-3 py-2.5 text-left text-sm transition-soft ${
                                groupActive || open ? 'bg-sage/80 font-medium text-ink' : 'text-ink-muted hover:bg-sage/60 hover:text-ink'
                            }`}
                        >
                            <span className={groupActive || open ? 'text-primary' : 'text-ink-faint group-hover:text-ink-muted'}>{group.icon}</span>
                            <span className="min-w-0 flex-1 truncate">{group.label}</span>
                            <span className={`text-ink-faint transition-transform ${open ? 'rotate-90' : ''}`}>
                                <IconChevron />
                            </span>
                        </button>
                        {open && (
                            <div className="mt-1 space-y-0.5 border-l border-line/80 pl-3 ml-5">
                                {group.items.map((item) => {
                                    const active = activeHref === item.href;
                                    return (
                                        <Link
                                            key={item.href}
                                            href={item.href}
                                            onClick={(event) => event.stopPropagation()}
                                            aria-current={active ? 'page' : undefined}
                                            className={`group relative flex items-center gap-2 rounded-field px-3 py-2 text-[13px] transition-soft ${
                                                active
                                                    ? 'bg-sage font-medium text-ink'
                                                    : 'text-ink-muted hover:bg-sage/60 hover:text-ink'
                                            }`}
                                        >
                                            {active && <span className="absolute left-0 top-2 bottom-2 w-0.5 rounded-full bg-primary" />}
                                            <span className={active ? 'text-primary' : 'text-ink-faint group-hover:text-ink-muted'}>{item.icon}</span>
                                            <span className="truncate">{item.label}</span>
                                        </Link>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                );
            })}
        </nav>
    );

    return (
        <div className={`bg-canvas text-ink ${flush ? 'h-dvh overflow-hidden' : 'min-h-dvh'}`}>
            {/* Desktop sidebar */}
            <aside
                className={`fixed inset-y-0 left-0 z-20 hidden flex-col border-r border-line bg-surface lg:flex ${
                    collapsed ? 'w-16' : 'w-72'
                } transition-[width] duration-200`}
            >
                <div className={`flex h-14 items-center border-b border-line ${collapsed ? 'justify-center px-0' : 'px-4'}`}>
                    <Link href="/platform" className="rounded-lg">
                        {collapsed ? <BrandMark /> : <BrandLockup />}
                    </Link>
                </div>
                <div className={`flex-1 overflow-y-auto py-4 ${collapsed ? 'px-3' : 'px-3'}`}>{navigation(collapsed)}</div>
                <div className="border-t border-line p-3">
                    <button
                        type="button"
                        onClick={toggleCollapsed}
                        className="flex w-full items-center justify-center gap-2 rounded-field px-3 py-2 text-[13px] text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                        aria-label={collapsed ? 'Déplier le menu' : 'Replier le menu'}
                    >
                        <span className={`transition-transform ${collapsed ? 'rotate-180' : ''}`}>
                            <IconCollapse />
                        </span>
                        {!collapsed && <span>Replier</span>}
                    </button>
                </div>
            </aside>

            <div className={`${collapsed ? 'lg:pl-16' : 'lg:pl-72'} ${flush ? 'flex h-dvh flex-col' : ''}`}>
                <header className={`z-10 border-b border-line bg-canvas/85 px-3 py-2.5 backdrop-blur sm:px-6 ${flush ? 'shrink-0' : 'sticky top-0'}`}>
                    <div className={`mx-auto flex items-center justify-between gap-2 sm:gap-4 ${wide ? 'max-w-[1700px]' : 'max-w-7xl'}`}>
                        {/* Mobile menu trigger */}
                        <button
                            type="button"
                            onClick={() => setNavOpen(true)}
                            aria-label="Ouvrir le menu"
                            aria-expanded={navOpen}
                            className="flex size-11 shrink-0 items-center justify-center rounded-field border border-line-strong bg-surface text-ink transition-soft hover:bg-sage lg:hidden"
                        >
                            <IconMenu />
                        </button>

                        {/* Org/store switcher — stays in the header from `sm:` up; folded into the drawer below that */}
                        <div className="hidden min-w-0 flex-1 sm:flex">{orgStoreSwitcher}</div>
                        <div className="min-w-0 flex-1 sm:hidden" />

                        {/* Profile menu */}
                        <details className="relative shrink-0">
                            <summary className="flex cursor-pointer list-none items-center gap-2 rounded-field px-1.5 py-1.5 transition-soft hover:bg-sage sm:px-2">
                                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-primary text-[13px] font-semibold text-primary-fg sm:size-8">
                                    {(auth.user?.name ?? '?').trim().charAt(0).toUpperCase()}
                                </span>
                                <span className="hidden text-left sm:block">
                                    <span className="block text-[13px] font-medium leading-tight text-ink">{auth.user?.name}</span>
                                    <span className="block text-[11px] leading-tight text-ink-muted">{auth.user?.email}</span>
                                </span>
                            </summary>
                            <div className="absolute right-0 top-12 z-30 w-60 max-w-[calc(100vw-1.5rem)] rounded-card border border-line bg-surface p-1.5 shadow-pop">
                                <div className="px-3 py-2">
                                    <p className="text-[13px] font-medium text-ink">{auth.user?.name}</p>
                                    <p className="truncate text-[12px] text-ink-muted">{auth.user?.email}</p>
                                </div>

                                {/* Personal account — the current user's own profile/security, never
                                    organization administration (kept in its own group below). */}
                                <Link
                                    href="/account/profile"
                                    className="block rounded-field px-3 py-2.5 text-[13px] text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                                >
                                    Mon profil
                                </Link>
                                <Link
                                    href="/security"
                                    className="block rounded-field px-3 py-2.5 text-[13px] text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                                >
                                    Sécurité
                                </Link>

                                {(tenant.organization && tenant.permissions.includes('members.view')) ||
                                hasPermission(['settings.view', 'settings.update']) ? (
                                    <div className="my-1 border-t border-line" />
                                ) : null}

                                {tenant.organization && tenant.permissions.includes('members.view') && (
                                    <Link
                                        href={`/organizations/${tenant.organization.id}/users-access`}
                                        className="block rounded-field px-3 py-2.5 text-[13px] text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                                    >
                                        Utilisateurs & accès
                                    </Link>
                                )}
                                {hasPermission(['settings.view', 'settings.update']) && (
                                    <Link
                                        href="/document-profile"
                                        className="block rounded-field px-3 py-2.5 text-[13px] text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                                    >
                                        Paramètres de l’organisation
                                    </Link>
                                )}
                                <div className="my-1 border-t border-line" />
                                <button
                                    type="button"
                                    onClick={() => router.post('/logout')}
                                    className="block w-full rounded-field px-3 py-2.5 text-left text-[13px] text-danger transition-soft hover:bg-danger-soft"
                                >
                                    Déconnexion
                                </button>
                            </div>
                        </details>
                    </div>
                </header>

                {/* Mobile navigation drawer */}
                {navOpen && (
                    <div className="fixed inset-0 z-40 flex lg:hidden">
                        <button type="button" className="flex-1 bg-ink/40" onClick={() => setNavOpen(false)} aria-label="Fermer le menu" />
                        <aside className="flex h-full w-[88vw] max-w-96 flex-col bg-surface shadow-pop">
                            <div className="flex h-14 shrink-0 items-center justify-between border-b border-line px-4">
                                <BrandLockup />
                                <button
                                    type="button"
                                    onClick={() => setNavOpen(false)}
                                    aria-label="Fermer le menu"
                                    className="flex size-10 items-center justify-center rounded-field text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                                >
                                    <IconClose />
                                </button>
                            </div>
                            <div className="shrink-0 border-b border-line p-3 sm:hidden">{orgStoreSwitcher}</div>
                            <div className="min-h-0 flex-1 overflow-y-auto px-3 py-4">{navigation(false)}</div>
                        </aside>
                    </div>
                )}

                {flush ? (
                    <main className="min-h-0 flex-1 overflow-hidden">{children}</main>
                ) : (
                    <main className={`mx-auto min-w-0 px-4 py-5 sm:px-6 sm:py-8 lg:px-8 ${wide ? 'max-w-[1700px]' : 'max-w-7xl'}`}>
                        {children}
                    </main>
                )}
            </div>
        </div>
    );
}

/* --- icons (16px, currentColor) --- */
const s = { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.7, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };
function IconDashboard() { return <svg {...s}><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>; }
function IconHome() { return <svg {...s}><path d="M4 11 12 4l8 7M6 10v10h12V10" /></svg>; }
function IconRegister() { return <svg {...s}><rect x="3" y="4" width="18" height="13" rx="2" /><path d="M3 9h18M8 21h8M12 17v4" /></svg>; }
function IconShoppingBag() { return <svg {...s}><path d="M6 8h12l-1 13H7L6 8Z" /><path d="M9 8a3 3 0 0 1 6 0" /></svg>; }
function IconClipboard() { return <svg {...s}><path d="M8 4h8l1 3H7l1-3Z" /><path d="M7 6H5v15h14V6h-2M8 12h8M8 16h6" /></svg>; }
function IconFileText() { return <svg {...s}><path d="M7 3h7l5 5v13H7V3Z" /><path d="M14 3v5h5M10 13h6M10 17h6" /></svg>; }
function IconTruck() { return <svg {...s}><path d="M3 6h11v10H3V6ZM14 10h4l3 3v3h-7v-6Z" /><circle cx="7" cy="18" r="2" /><circle cx="18" cy="18" r="2" /></svg>; }
function IconRotateBack() { return <svg {...s}><path d="M9 7H4v-5M4 7a9 9 0 1 1 3 6.7" /></svg>; }
function IconPackage() { return <svg {...s}><path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z" /><path d="M4 7l8 4 8-4M12 11v10" /></svg>; }
function IconTag() { return <svg {...s}><path d="M4 4h8l8 8-8 8-8-8V4Z" /><circle cx="8.5" cy="8.5" r="1.3" /></svg>; }
function IconImport() { return <svg {...s}><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" /></svg>; }
function IconAlertBox() { return <svg {...s}><path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z" /><path d="M12 8v5M12 16h.01" /></svg>; }
function IconBoxes() { return <svg {...s}><path d="M7 3h7l4 4v7l-7 4-7-4V7l3-4Z" /><path d="M4 7l7 4 7-4M11 11v7M14 3l-7 4" /></svg>; }
function IconWarehouse() { return <svg {...s}><path d="M3 10 12 4l9 6v10H3V10Z" /><path d="M7 20v-7h10v7M7 13h10M10 16h4" /></svg>; }
function IconPin() { return <svg {...s}><path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z" /><circle cx="12" cy="10" r="2.4" /></svg>; }
function IconTransfer() { return <svg {...s}><path d="M4 8h13l-3-3M20 16H7l3 3" /></svg>; }
function IconArrows() { return <svg {...s}><path d="M7 7h13l-3-3M17 17H4l3 3M5 12h14" /></svg>; }
function IconRoute() { return <svg {...s}><circle cx="6" cy="6" r="2" /><circle cx="18" cy="18" r="2" /><path d="M8 6h4a4 4 0 0 1 0 8h-1a4 4 0 0 0 0 8h7" /></svg>; }
function IconList() { return <svg {...s}><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" /></svg>; }
function IconCart() { return <svg {...s}><path d="M4 4h2l2 11h10l2-8H7" /><circle cx="10" cy="20" r="1.5" /><circle cx="17" cy="20" r="1.5" /></svg>; }
function IconUsers() { return <svg {...s}><circle cx="9" cy="8" r="3" /><path d="M3 20c0-3.3 2.7-5 6-5s6 1.7 6 5M16 6.5a3 3 0 0 1 0 5.5M21 20c0-2.6-1.5-4.2-3.5-4.7" /></svg>; }
function IconChart() { return <svg {...s}><path d="M4 19V5M4 19h16" /><path d="M8 15v-4M12 15V8M16 15v-7" /></svg>; }
function IconTrending() { return <svg {...s}><path d="M4 17 9 12l4 4 7-8" /><path d="M15 8h5v5" /></svg>; }
function IconBanknote() { return <svg {...s}><rect x="3" y="6" width="18" height="12" rx="2" /><circle cx="12" cy="12" r="3" /><path d="M6 9h.01M18 15h.01" /></svg>; }
function IconClockCoins() { return <svg {...s}><circle cx="9" cy="10" r="5" /><path d="M9 7v3l2 2M15 14c3 0 5 1.2 5 2.7s-2 2.7-5 2.7-5-1.2-5-2.7" /></svg>; }
function IconInvoice() { return <svg {...s}><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z" /><path d="M9 8h6M9 12h6" /></svg>; }
function IconWallet() { return <svg {...s}><path d="M4 7a2 2 0 0 1 2-2h11v4M4 7v10a2 2 0 0 0 2 2h13V9H6a2 2 0 0 1-2-2Z" /><circle cx="16" cy="14" r="1.3" /></svg>; }
function IconBank() { return <svg {...s}><path d="M4 10h16L12 4 4 10ZM6 10v8M10 10v8M14 10v8M18 10v8M4 20h16" /></svg>; }
function IconBuilding() { return <svg {...s}><path d="M5 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16M9 8h.01M12 8h.01M9 12h.01M12 12h.01M9 16h.01M12 16h.01M17 21V10h2a2 2 0 0 1 2 2v9" /></svg>; }
function IconPlug() { return <svg {...s}><path d="M9 2v6M15 2v6M7 8h10v3a5 5 0 0 1-10 0V8ZM12 16v6" /></svg>; }
function IconSync() { return <svg {...s}><path d="M4 12a8 8 0 0 1 13.7-5.7L20 8M20 4v4h-4M20 12a8 8 0 0 1-13.7 5.7L4 16M4 20v-4h4" /></svg>; }
function IconShield() { return <svg {...s}><path d="M12 3 5 6v5c0 4.5 2.8 8 7 10 4.2-2 7-5.5 7-10V6l-7-3Z" /></svg>; }
function IconSettings() { return <svg {...s}><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z" /><path d="M3 12h2M19 12h2M12 3v2M12 19v2M5.6 5.6 7 7M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4" /></svg>; }
function IconFolder() { return <svg {...s}><path d="M3 6h7l2 3h9v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6Z" /></svg>; }
function IconPercent() { return <svg {...s}><path d="M19 5 5 19" /><circle cx="7" cy="7" r="2" /><circle cx="17" cy="17" r="2" /></svg>; }
function IconMail() { return <svg {...s}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></svg>; }
function IconStamp() { return <svg {...s}><circle cx="12" cy="8" r="5" /><path d="M9 8h6M9 6.5h6M9 9.5h4M6 21h12l-1.5-5h-9L6 21Z" /></svg>; }
function IconChevron() { return <svg {...s} width={14} height={14}><path d="m9 5 5 7-5 7" /></svg>; }
function IconCollapse() { return <svg {...s}><path d="M14 6 8 12l6 6" /></svg>; }
function IconMenu() { return <svg {...s}><path d="M4 6h16M4 12h16M4 18h16" /></svg>; }
function IconClose() { return <svg {...s}><path d="M6 6l12 12M18 6 6 18" /></svg>; }
