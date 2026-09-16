import { Button, ButtonLink } from '@/components/ui/Button';
import { TextField } from '@/components/ui/form';
import ApplicationShell from '@/layouts/ApplicationShell';
import type { SharedPageProps, TenantOrganization, TenantStore } from '@/types/app';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    organizations: TenantOrganization[];
    stores: TenantStore[];
    dashboard: {
        product_count: number | null;
        stocked_item_count: number | null;
        sales_today: number | null;
        payments_to_receive: number | null;
        woo_stock_tasks_pending: number | null;
        out_of_stock_articles_pending: number | null;
    };
    onboarding: { organization: boolean; store: boolean; products: boolean; stock: boolean; first_sale: boolean };
};

const checklist = [
    ['organization', 'Créer votre organisation'],
    ['store', 'Créer votre premier magasin'],
    ['products', 'Ajouter ou importer des produits'],
    ['stock', 'Enregistrer le stock initial'],
    ['first_sale', 'Réaliser votre première vente'],
] as const;

export default function PlatformIndex({ organizations, stores, dashboard, onboarding }: Props) {
    const page = usePage<SharedPageProps>();
    const { tenant, auth } = page.props;
    const organizationForm = useForm({ name: '' });
    const storeForm = useForm({ name: '', code: '' });
    const can = (permission: string) => tenant.permissions.includes(permission);
    const firstName = (auth.user?.name ?? '').trim().split(' ')[0] || 'bienvenue';

    const needsOrganization = organizations.length === 0;
    const needsStore = !!tenant.organization && stores.length === 0 && can('stores.create');
    const inSetup = needsOrganization || needsStore;

    const createOrganization = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        organizationForm.post('/organizations');
    };
    const createStore = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        storeForm.post('/stores');
    };

    const metrics = [
        { label: 'Produits', value: dashboard.product_count, href: '/catalog/products', permission: 'products.view', needsStore: false },
        { label: 'Articles en stock', value: dashboard.stocked_item_count, href: '/inventory/stock', permission: 'inventory.view', needsStore: false },
        { label: 'Ventes aujourd’hui', value: dashboard.sales_today, href: '/sales/orders', permission: 'sales_orders.view', needsStore: true },
        { label: 'Paiements à recevoir', value: dashboard.payments_to_receive, href: '/payments', permission: 'payments.view', needsStore: true },
        {
            label: 'Stock WooCommerce à mettre à jour',
            value: dashboard.woo_stock_tasks_pending,
            href: '/integrations/woocommerce/stock-tasks',
            permission: 'integrations.woocommerce.stock_tasks.view',
            needsStore: false,
        },
        {
            label: 'Articles à cataloguer',
            value: dashboard.out_of_stock_articles_pending,
            href: '/procurement/out-of-stock-articles',
            permission: 'procurement.view',
            needsStore: false,
        },
    ];

    const quickEntries = [
        { label: 'Point de vente', href: '/pos', permission: 'pos.access', needsStore: true },
        { label: 'Produits', href: '/catalog/products', permission: 'products.view', needsStore: false },
        { label: 'Stock', href: '/inventory/stock', permission: 'inventory.view', needsStore: false },
        { label: 'Clients', href: '/sales/customers', permission: 'customers.view', needsStore: false },
    ].filter((entry) => can(entry.permission) && (!entry.needsStore || tenant.store));

    return (
        <ApplicationShell>
            <Head title={inSetup ? 'Préparer votre espace' : 'Accueil'} />

            {inSetup ? (
                <div className="mx-auto max-w-xl">
                    <p className="text-[13px] font-medium uppercase tracking-[0.14em] text-ink-muted">Bienvenue</p>
                    <h1 className="mt-2 text-2xl font-semibold tracking-tight text-ink">
                        Bonjour {firstName}, préparons votre espace de travail.
                    </h1>
                    <p className="mt-1.5 text-sm text-ink-muted">
                        Deux étapes suffisent pour commencer à vendre.
                    </p>

                    <ol className="mt-6 flex items-center gap-3 text-[13px]">
                        <Step index={1} label="Organisation" state={needsOrganization ? 'current' : 'done'} />
                        <span className="h-px flex-1 bg-line" />
                        <Step index={2} label="Magasin" state={needsOrganization ? 'todo' : needsStore ? 'current' : 'done'} />
                    </ol>

                    {needsOrganization && (
                        <section className="mt-6 rounded-card border border-line bg-surface p-6 shadow-card">
                            <h2 className="text-lg font-semibold text-ink">Créer votre organisation</h2>
                            <p className="mt-1 text-sm text-ink-muted">Elle contiendra vos magasins, produits, clients et utilisateurs.</p>
                            <form onSubmit={createOrganization} className="mt-5 space-y-4">
                                <TextField
                                    label="Nom de l’organisation"
                                    name="name"
                                    required
                                    maxLength={255}
                                    autoComplete="organization"
                                    placeholder="Ex. AV Professional"
                                    value={organizationForm.data.name}
                                    onChange={(event) => organizationForm.setData('name', event.target.value)}
                                    error={organizationForm.errors.name}
                                />
                                <Button type="submit" size="lg" disabled={organizationForm.processing}>
                                    {organizationForm.processing ? 'Création…' : 'Continuer'}
                                </Button>
                            </form>
                        </section>
                    )}

                    {needsStore && (
                        <section className="mt-6 rounded-card border border-line bg-surface p-6 shadow-card">
                            <h2 className="text-lg font-semibold text-ink">Créer votre premier magasin</h2>
                            <p className="mt-1 text-sm text-ink-muted">Les ventes et leur contexte opérationnel seront rattachés à ce magasin.</p>
                            <form onSubmit={createStore} className="mt-5 grid gap-4 sm:grid-cols-2">
                                <TextField
                                    label="Nom du magasin"
                                    name="name"
                                    required
                                    maxLength={255}
                                    placeholder="Magasin principal"
                                    value={storeForm.data.name}
                                    onChange={(event) => storeForm.setData('name', event.target.value)}
                                    error={storeForm.errors.name}
                                />
                                <TextField
                                    label="Code"
                                    name="code"
                                    required
                                    maxLength={64}
                                    placeholder="MAIN"
                                    value={storeForm.data.code}
                                    onChange={(event) => storeForm.setData('code', event.target.value.toUpperCase())}
                                    error={storeForm.errors.code}
                                />
                                <div className="sm:col-span-2">
                                    <Button type="submit" size="lg" disabled={storeForm.processing}>
                                        {storeForm.processing ? 'Création…' : 'Entrer dans l’application'}
                                    </Button>
                                </div>
                            </form>
                        </section>
                    )}
                </div>
            ) : (
                <div className="space-y-7">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight text-ink">Bonjour {firstName}</h1>
                            <p className="mt-1 text-sm text-ink-muted">
                                {tenant.organization?.name}
                                {tenant.store ? ` · ${tenant.store.name}` : ''}
                            </p>
                        </div>
                        {tenant.store && can('pos.access') && <ButtonLink href="/pos" size="lg">Nouvelle vente</ButtonLink>}
                    </header>

                    {quickEntries.length > 0 && (
                        <section>
                            <h2 className="mb-3 text-[13px] font-semibold uppercase tracking-wider text-ink-faint">Accès rapide</h2>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                {quickEntries.map((entry) => (
                                    <Link
                                        key={entry.href}
                                        href={entry.href}
                                        className="rounded-card border border-line bg-surface p-4 text-sm font-medium text-ink shadow-card transition-soft hover:border-line-strong hover:bg-raised"
                                    >
                                        {entry.label}
                                    </Link>
                                ))}
                            </div>
                        </section>
                    )}

                    <section aria-labelledby="activity-title">
                        <h2 id="activity-title" className="mb-3 text-[13px] font-semibold uppercase tracking-wider text-ink-faint">Votre activité</h2>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            {metrics
                                .filter((metric) => can(metric.permission) && (!metric.needsStore || tenant.store))
                                .map((metric) => (
                                    <Link
                                        key={metric.label}
                                        href={metric.href}
                                        className="rounded-card border border-line bg-surface p-5 shadow-card transition-soft hover:border-line-strong"
                                    >
                                        <p className="text-sm text-ink-muted">{metric.label}</p>
                                        <p className="mt-2 text-3xl font-semibold tabular-nums text-ink">{metric.value ?? '—'}</p>
                                    </Link>
                                ))}
                        </div>
                    </section>

                    <div className="grid gap-6 lg:grid-cols-[1.25fr_1fr]">
                        <section className="rounded-card border border-line bg-surface p-6 shadow-card">
                            <h2 className="font-semibold text-ink">Bien démarrer</h2>
                            <p className="mt-1 text-sm text-ink-muted">Les étapes essentielles pour rendre votre espace opérationnel.</p>
                            <ol className="mt-5 space-y-3">
                                {checklist.map(([key, label]) => (
                                    <li key={key} className="flex items-center gap-3 text-sm">
                                        <span
                                            aria-hidden="true"
                                            className={`grid size-6 place-items-center rounded-full text-xs font-bold ${
                                                onboarding[key] ? 'bg-success-soft text-success' : 'bg-sage text-ink-muted'
                                            }`}
                                        >
                                            {onboarding[key] ? '✓' : '·'}
                                        </span>
                                        <span className={onboarding[key] ? 'text-ink-faint line-through' : 'font-medium text-ink'}>{label}</span>
                                    </li>
                                ))}
                            </ol>
                        </section>

                        <section className="rounded-card border border-line bg-surface p-6 shadow-card">
                            <h2 className="font-semibold text-ink">Prochaine action</h2>
                            <p className="mt-1 text-sm text-ink-muted">Choisissez le chemin le plus adapté à votre activité.</p>
                            <div className="mt-5 grid gap-3">
                                {can('products.import') && <ButtonLink href="/catalog/products/import">Importer des produits</ButtonLink>}
                                {can('products.create') && <ButtonLink href="/catalog/products/create" variant="secondary">Ajouter un produit</ButtonLink>}
                                {can('inventory.opening') && <ButtonLink href="/inventory/stock" variant="secondary">Saisir le stock initial</ButtonLink>}
                                {tenant.store && can('pos.access') && <ButtonLink href="/pos" variant="secondary">Ouvrir le point de vente</ButtonLink>}
                            </div>
                        </section>
                    </div>
                </div>
            )}
        </ApplicationShell>
    );
}

function Step({ index, label, state }: { index: number; label: string; state: 'todo' | 'current' | 'done' }) {
    return (
        <span className="flex items-center gap-2">
            <span
                className={`grid size-6 place-items-center rounded-full text-xs font-semibold ${
                    state === 'done'
                        ? 'bg-success-soft text-success'
                        : state === 'current'
                          ? 'bg-primary text-primary-fg'
                          : 'bg-sage text-ink-faint'
                }`}
            >
                {state === 'done' ? '✓' : index}
            </span>
            <span className={state === 'current' ? 'font-medium text-ink' : 'text-ink-muted'}>{label}</span>
        </span>
    );
}
