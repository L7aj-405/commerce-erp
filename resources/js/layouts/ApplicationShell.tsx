import type { SharedPageProps } from '@/types/app';
import { Link, router, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

type NavItem = { href: string; label: string; permission?: string; needsStore?: boolean };

export default function ApplicationShell({ children, wide = false }: PropsWithChildren<{ wide?: boolean }>) {
    const page = usePage<SharedPageProps>();
    const { auth, tenant, flash } = page.props;
    const currentPath = page.url.split('?')[0];
    const groups: Array<{ label?: string; items: NavItem[] }> = [
        { items: [{ href: '/platform', label: 'Tableau de bord' }] },
        { label: 'Vendre', items: [{ href: '/pos', label: 'Point de vente', permission: 'pos.access', needsStore: true }] },
        { label: 'Catalogue', items: [{ href: '/catalog/products', label: 'Produits', permission: 'products.view' }, { href: '/catalog/products/import', label: 'Importer des produits', permission: 'products.import' }] },
        { label: 'Stock', items: [{ href: '/inventory/stock', label: 'État du stock', permission: 'inventory.view' }, { href: '/inventory/warehouses', label: 'Emplacements', permission: 'warehouses.view' }, { href: '/inventory/transfers', label: 'Transferts', permission: 'inventory.view' }, { href: '/inventory/movements', label: 'Mouvements', permission: 'inventory.view' }] },
        { items: [{ href: '/sales/customers', label: 'Clients', permission: 'customers.view' }] },
        { label: 'Ventes', items: [{ href: '/sales/orders', label: 'Commandes', permission: 'sales_orders.view', needsStore: true }, { href: '/invoices', label: 'Factures', permission: 'invoices.view', needsStore: true }, { href: '/payments', label: 'Paiements', permission: 'payments.view', needsStore: true }] },
        { label: 'Paramètres', items: [...(tenant.organization ? [{ href: `/organizations/${tenant.organization.id}`, label: 'Organisation et utilisateurs', permission: 'organizations.view' }] : []), { href: '/document-profile', label: 'Profil des documents', permission: 'settings.update' }, { href: '/catalog/categories', label: 'Catégories', permission: 'categories.manage' }, { href: '/catalog/brands', label: 'Marques', permission: 'brands.manage' }, { href: '/financial-accounts', label: 'Comptes financiers', permission: 'financial_accounts.view' }] },
    ];
    const allowed = (item: NavItem) => (!item.permission || tenant.permissions.includes(item.permission)) && (!item.needsStore || tenant.store !== null);
    const activeHref = groups.flatMap(group => group.items).filter(item => allowed(item) && (currentPath === item.href || currentPath.startsWith(`${item.href}/`))).sort((a, b) => b.href.length - a.href.length)[0]?.href;
    const navigation = <>{groups.map((group, index) => { const items = group.items.filter(allowed); return items.length ? <div key={index} className="mb-5">{group.label && <p className="mb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{group.label}</p>}{items.map(item => <Link key={item.href} href={item.href} aria-current={activeHref === item.href ? 'page' : undefined} className={`block rounded-lg px-3 py-2 text-sm ${activeHref === item.href ? 'bg-slate-100 font-semibold text-slate-950' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-950'}`}>{item.label}</Link>)}</div> : null; })}</>;

    return <div className="min-h-screen bg-slate-50 text-slate-950">
        <aside className="fixed inset-y-0 left-0 z-20 hidden w-64 overflow-y-auto border-r border-slate-200 bg-white px-4 py-5 lg:block"><Link href="/platform" className="px-3 text-lg font-bold tracking-tight">Commerce ERP</Link><nav className="mt-8">{navigation}</nav></aside>
        <div className="lg:pl-64"><header className="sticky top-0 z-10 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:px-6"><div className="mx-auto flex max-w-7xl items-center justify-between gap-4">
            <details className="lg:hidden"><summary className="cursor-pointer rounded-lg border px-3 py-2 text-sm">Menu</summary><nav className="absolute left-4 top-14 w-64 rounded-xl border bg-white p-3 shadow-xl">{navigation}</nav></details>
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2"><select aria-label="Organisation" value={tenant.organization?.id ?? ''} onChange={event => event.target.value && router.post(`/context/organizations/${event.target.value}`)} className="max-w-56 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium"><option value="">Organisation</option>{tenant.organizations.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select><select aria-label="Magasin" value={tenant.store?.id ?? ''} onChange={event => event.target.value && router.post(`/context/stores/${event.target.value}`)} disabled={!tenant.organization} className="max-w-56 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"><option value="">Magasin</option>{tenant.stores.map(item => <option key={item.id} value={item.id}>{item.name} · {item.code}</option>)}</select></div>
            <div className="hidden text-right sm:block"><p className="text-sm font-medium">{auth.user?.name}</p><button type="button" onClick={() => router.post('/logout')} className="text-xs text-slate-500 hover:text-slate-950">Se déconnecter</button></div>
        </div></header><main className={`mx-auto px-4 py-8 sm:px-6 ${wide ? 'max-w-[1700px]' : 'max-w-7xl'}`}>{flash?.success && <div className="mb-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{flash.success}</div>}{children}</main></div>
    </div>;
}
