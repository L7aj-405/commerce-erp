import { BrandLockup, BrandMark } from '@/components/ui/brand';
import { useToast } from '@/components/ui/toast';
import type { SharedPageProps } from '@/types/app';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { PropsWithChildren, ReactNode } from 'react';

type NavItem = { href: string; label: string; permission?: string | string[]; needsStore?: boolean; icon: ReactNode };

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

    const groups: Array<{ label?: string; items: NavItem[] }> = [
        { items: [{ href: '/platform', label: 'Accueil', icon: <IconHome /> }] },
        { label: 'Vendre', items: [{ href: '/pos', label: 'Point de vente', permission: 'pos.access', needsStore: true, icon: <IconRegister /> }] },
        {
            label: 'Catalogue',
            items: [
                { href: '/catalog/products', label: 'Produits', permission: 'products.view', icon: <IconTag /> },
                { href: '/catalog/products/import', label: 'Importer des produits', permission: 'products.import', icon: <IconImport /> },
                { href: '/catalog/non-stock-items', label: 'Articles hors stock', permission: 'quotations.view', icon: <IconTag /> },
            ],
        },
        {
            label: 'Stock',
            items: [
                { href: '/inventory/stock', label: 'État du stock', permission: 'inventory.view', icon: <IconBoxes /> },
                { href: '/inventory/warehouses', label: 'Emplacements', permission: 'warehouses.view', icon: <IconPin /> },
                { href: '/inventory/transfers', label: 'Transferts', permission: 'inventory.view', icon: <IconTransfer /> },
                { href: '/inventory/transfer-requests', label: 'Demandes de transfert', permission: 'inventory.transfer_requests.view', icon: <IconTransfer /> },
                { href: '/inventory/movements', label: 'Mouvements', permission: 'inventory.view', icon: <IconList /> },
            ],
        },
        { items: [{ href: '/sales/customers', label: 'Clients', permission: 'customers.view', icon: <IconUsers /> }] },
        {
            label: 'Ventes',
            items: [
                { href: '/quotations', label: 'Devis', permission: 'quotations.view', needsStore: true, icon: <IconDoc /> },
                { href: '/sales/orders', label: 'Commandes', permission: 'sales_orders.view', needsStore: true, icon: <IconDoc /> },
                { href: '/invoices', label: 'Factures', permission: 'invoices.view', needsStore: true, icon: <IconInvoice /> },
                { href: '/payments', label: 'Paiements', permission: 'payments.view', needsStore: true, icon: <IconWallet /> },
            ],
        },
        {
            label: 'Achats',
            items: [
                { href: '/procurement', label: 'Approvisionnements', permission: 'procurement.view', icon: <IconTransfer /> },
                { href: '/procurement/suppliers', label: 'Fournisseurs', permission: 'suppliers.view', icon: <IconUsers /> },
            ],
        },
        {
            label: 'Paramètres',
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
                { href: '/document-profile', label: 'Documents · Facture', permission: ['settings.view', 'settings.update'], icon: <IconDoc /> },
                { href: '/quotation-settings', label: 'Documents · Devis', permission: ['settings.view', 'settings.update'], icon: <IconDoc /> },
                { href: '/catalog/categories', label: 'Catégories', permission: 'categories.manage', icon: <IconTag /> },
                { href: '/catalog/brands', label: 'Marques', permission: 'brands.manage', icon: <IconTag /> },
                { href: '/catalog/tax-rates', label: 'Taxes (TVA)', permission: 'tax_rates.view', icon: <IconInvoice /> },
                { href: '/integrations/woocommerce', label: 'Intégrations', permission: 'integrations.view', icon: <IconPlug /> },
                { href: '/financial-accounts', label: 'Comptes financiers', permission: 'financial_accounts.view', icon: <IconWallet /> },
            ],
        },
    ];

    const hasPermission = (permission?: string | string[]) =>
        !permission || (Array.isArray(permission) ? permission.some((p) => tenant.permissions.includes(p)) : tenant.permissions.includes(permission));
    const allowed = (item: NavItem) => hasPermission(item.permission) && (!item.needsStore || tenant.store !== null);
    const activeHref = groups
        .flatMap((group) => group.items)
        .filter((item) => allowed(item) && (currentPath === item.href || currentPath.startsWith(`${item.href}/`)))
        .sort((a, b) => b.href.length - a.href.length)[0]?.href;

    const navigation = (dense: boolean) => (
        <nav className="space-y-5">
            {groups.map((group, index) => {
                const items = group.items.filter(allowed);
                if (!items.length) return null;
                return (
                    <div key={index}>
                        {group.label && !dense && (
                            <p className="mb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-ink-faint">{group.label}</p>
                        )}
                        {group.label && dense && <div className="mx-3 mb-1 border-t border-line" />}
                        {items.map((item) => {
                            const active = activeHref === item.href;
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    title={dense ? item.label : undefined}
                                    aria-current={active ? 'page' : undefined}
                                    className={`group relative mb-0.5 flex items-center gap-2.5 rounded-field px-3 py-2 text-sm transition-soft ${
                                        dense ? 'justify-center' : ''
                                    } ${
                                        active
                                            ? 'bg-sage font-medium text-ink'
                                            : 'text-ink-muted hover:bg-sage/60 hover:text-ink'
                                    }`}
                                >
                                    {active && <span className="absolute left-0 top-1.5 bottom-1.5 w-0.5 rounded-full bg-primary" />}
                                    <span className={active ? 'text-primary' : 'text-ink-faint group-hover:text-ink-muted'}>{item.icon}</span>
                                    {!dense && <span className="truncate">{item.label}</span>}
                                </Link>
                            );
                        })}
                    </div>
                );
            })}
        </nav>
    );

    return (
        <div className={`bg-canvas text-ink ${flush ? 'h-screen overflow-hidden' : 'min-h-screen'}`}>
            {/* Desktop sidebar */}
            <aside
                className={`fixed inset-y-0 left-0 z-20 hidden flex-col border-r border-line bg-surface lg:flex ${
                    collapsed ? 'w-16' : 'w-64'
                } transition-[width] duration-200`}
            >
                <div className={`flex h-14 items-center border-b border-line ${collapsed ? 'justify-center px-0' : 'px-4'}`}>
                    <Link href="/platform" className="rounded-lg">
                        {collapsed ? <BrandMark /> : <BrandLockup />}
                    </Link>
                </div>
                <div className="flex-1 overflow-y-auto px-3 py-4">{navigation(collapsed)}</div>
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

            <div className={`${collapsed ? 'lg:pl-16' : 'lg:pl-64'} ${flush ? 'flex h-screen flex-col' : ''}`}>
                <header className={`z-10 border-b border-line bg-canvas/85 px-4 py-2.5 backdrop-blur sm:px-6 ${flush ? 'shrink-0' : 'sticky top-0'}`}>
                    <div className={`mx-auto flex items-center justify-between gap-4 ${wide ? 'max-w-[1700px]' : 'max-w-7xl'}`}>
                        {/* Mobile menu */}
                        <details className="lg:hidden">
                            <summary className="flex cursor-pointer items-center gap-2 rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                                Menu
                            </summary>
                            <div className="absolute left-4 top-14 z-30 w-64 rounded-card border border-line bg-surface p-3 shadow-pop">{navigation(false)}</div>
                        </details>

                        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                            <select
                                aria-label="Organisation"
                                value={tenant.organization?.id ?? ''}
                                onChange={(event) => event.target.value && router.post(`/context/organizations/${event.target.value}`)}
                                className="max-w-56 rounded-field border border-line-strong bg-surface px-3 py-2 text-sm font-medium text-ink transition-soft focus:border-primary focus:outline-none"
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
                                className="max-w-56 rounded-field border border-line-strong bg-surface px-3 py-2 text-sm text-ink transition-soft focus:border-primary focus:outline-none disabled:opacity-50"
                            >
                                <option value="">Magasin</option>
                                {tenant.stores.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name} · {item.code}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Profile menu */}
                        <details className="relative">
                            <summary className="flex cursor-pointer list-none items-center gap-2 rounded-field px-2 py-1.5 transition-soft hover:bg-sage">
                                <span className="grid size-8 place-items-center rounded-full bg-primary text-[13px] font-semibold text-primary-fg">
                                    {(auth.user?.name ?? '?').trim().charAt(0).toUpperCase()}
                                </span>
                                <span className="hidden text-left sm:block">
                                    <span className="block text-[13px] font-medium leading-tight text-ink">{auth.user?.name}</span>
                                    <span className="block text-[11px] leading-tight text-ink-muted">{auth.user?.email}</span>
                                </span>
                            </summary>
                            <div className="absolute right-0 top-12 z-30 w-60 rounded-card border border-line bg-surface p-1.5 shadow-pop">
                                <div className="px-3 py-2">
                                    <p className="text-[13px] font-medium text-ink">{auth.user?.name}</p>
                                    <p className="truncate text-[12px] text-ink-muted">{auth.user?.email}</p>
                                </div>
                                {tenant.organization && tenant.permissions.includes('members.view') && (
                                    <Link
                                        href={`/organizations/${tenant.organization.id}/users-access`}
                                        className="block rounded-field px-3 py-2 text-[13px] text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                                    >
                                        Utilisateurs & accès
                                    </Link>
                                )}
                                {hasPermission(['settings.view', 'settings.update']) && (
                                    <Link
                                        href="/document-profile"
                                        className="block rounded-field px-3 py-2 text-[13px] text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                                    >
                                        Paramètres
                                    </Link>
                                )}
                                <div className="my-1 border-t border-line" />
                                <button
                                    type="button"
                                    onClick={() => router.post('/logout')}
                                    className="block w-full rounded-field px-3 py-2 text-left text-[13px] text-danger transition-soft hover:bg-danger-soft"
                                >
                                    Déconnexion
                                </button>
                            </div>
                        </details>
                    </div>
                </header>

                {flush ? (
                    <main className="min-h-0 flex-1 overflow-hidden">{children}</main>
                ) : (
                    <main className={`mx-auto px-4 py-8 sm:px-6 lg:px-8 ${wide ? 'max-w-[1700px]' : 'max-w-7xl'}`}>
                        {children}
                    </main>
                )}
            </div>
        </div>
    );
}

/* --- icons (16px, currentColor) --- */
const s = { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.7, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };
function IconHome() { return <svg {...s}><path d="M4 11 12 4l8 7M6 10v10h12V10" /></svg>; }
function IconRegister() { return <svg {...s}><rect x="3" y="4" width="18" height="13" rx="2" /><path d="M3 9h18M8 21h8M12 17v4" /></svg>; }
function IconTag() { return <svg {...s}><path d="M4 4h8l8 8-8 8-8-8V4Z" /><circle cx="8.5" cy="8.5" r="1.3" /></svg>; }
function IconImport() { return <svg {...s}><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" /></svg>; }
function IconBoxes() { return <svg {...s}><path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z" /><path d="M4 7l8 4 8-4M12 11v10" /></svg>; }
function IconPin() { return <svg {...s}><path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z" /><circle cx="12" cy="10" r="2.4" /></svg>; }
function IconTransfer() { return <svg {...s}><path d="M4 8h13l-3-3M20 16H7l3 3" /></svg>; }
function IconList() { return <svg {...s}><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" /></svg>; }
function IconUsers() { return <svg {...s}><circle cx="9" cy="8" r="3" /><path d="M3 20c0-3.3 2.7-5 6-5s6 1.7 6 5M16 6.5a3 3 0 0 1 0 5.5M21 20c0-2.6-1.5-4.2-3.5-4.7" /></svg>; }
function IconDoc() { return <svg {...s}><path d="M7 3h7l5 5v13H7V3Z" /><path d="M14 3v5h5M10 13h6M10 17h6" /></svg>; }
function IconInvoice() { return <svg {...s}><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z" /><path d="M9 8h6M9 12h6" /></svg>; }
function IconWallet() { return <svg {...s}><path d="M4 7a2 2 0 0 1 2-2h11v4M4 7v10a2 2 0 0 0 2 2h13V9H6a2 2 0 0 1-2-2Z" /><circle cx="16" cy="14" r="1.3" /></svg>; }
function IconBuilding() { return <svg {...s}><path d="M5 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16M9 8h.01M12 8h.01M9 12h.01M12 12h.01M9 16h.01M12 16h.01M17 21V10h2a2 2 0 0 1 2 2v9" /></svg>; }
function IconCollapse() { return <svg {...s}><path d="M14 6 8 12l6 6" /></svg>; }
function IconPlug() { return <svg {...s}><path d="M9 2v6M15 2v6M7 8h10v3a5 5 0 0 1-10 0V8ZM12 16v6" /></svg>; }
