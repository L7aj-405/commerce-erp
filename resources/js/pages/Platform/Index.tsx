import { Button, ButtonLink } from '@/components/ui/Button';
import { OrderStatesChart, RankedMoneyChart, SalesCollectionsChart } from '@/components/dashboard/DashboardCharts';
import { TextField } from '@/components/ui/form';
import ApplicationShell from '@/layouts/ApplicationShell';
import type { SharedPageProps, TenantOrganization, TenantStore } from '@/types/app';
import { formatInteger, formatMoney } from '@/utils/format';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
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
    executiveDashboard: null | {
        period: { month: string; label: string };
        primary: {
            net_sales: { value: string; variation: string | null };
            net_collections: { value: string; variation: string | null };
            orders: { value: number; variation: string | null };
            receivables: { value: string; variation: null };
        };
        secondary: {
            average_basket: string; partial_orders: number; unpaid_orders: number; refunds: string;
            returns: { count: number; amount: string }; out_of_stock: number;
        };
        charts: {
            sales_vs_collections: Array<{ label: string; sales: string; collections: string }>;
            order_states: { paid: number; partially_paid: number; unpaid: number };
            top_products: Array<{ label: string; value: string; quantity: string }>;
        };
        attention: Array<{ label: string; value: string | number; kind: 'money' | 'count'; href: string }>;
    };
};

const checklist = [
    ['organization', 'Créer votre organisation'],
    ['store', 'Créer votre premier magasin'],
    ['products', 'Ajouter ou importer des produits'],
    ['stock', 'Enregistrer le stock initial'],
    ['first_sale', 'Réaliser votre première vente'],
] as const;

export default function PlatformIndex({ organizations, stores, dashboard, onboarding, executiveDashboard }: Props) {
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
                <div className="space-y-6">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight text-ink">Bonjour {firstName}</h1>
                            <p className="mt-1 text-sm text-ink-muted">
                                {tenant.organization?.name}
                                {tenant.store ? ` · ${tenant.store.name}` : ''}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-end gap-3">
                            {executiveDashboard && (
                                <label className="block">
                                    <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">Période</span>
                                    <input type="month" value={executiveDashboard.period.month} onChange={(event) => event.target.value && router.get('/platform', { month: event.target.value }, { preserveState: true, preserveScroll: true })} className="h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink" />
                                </label>
                            )}
                            {tenant.store && can('pos.access') && <ButtonLink href="/pos" size="lg">Nouvelle vente</ButtonLink>}
                        </div>
                    </header>

                    {executiveDashboard ? (
                        <>
                            <section aria-labelledby="executive-kpis">
                                <div className="mb-3 flex items-end justify-between gap-3">
                                    <div><h2 id="executive-kpis" className="text-[13px] font-semibold uppercase tracking-wider text-ink-faint">Vue d’ensemble</h2><p className="mt-1 text-sm text-ink-muted">{executiveDashboard.period.label}</p></div>
                                    <span className="text-xs text-ink-faint">{tenant.store ? tenant.store.name : 'Organisation entière'}</span>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                    <ExecutiveKpi label="Ventes nettes" value={formatMoney(executiveDashboard.primary.net_sales.value)} variation={executiveDashboard.primary.net_sales.variation} href="/finance/ventes" />
                                    <ExecutiveKpi label="Encaissements nets" value={formatMoney(executiveDashboard.primary.net_collections.value)} variation={executiveDashboard.primary.net_collections.variation} href="/finance/ca-encaisse" />
                                    <ExecutiveKpi label="Commandes" value={formatInteger(executiveDashboard.primary.orders.value)} variation={executiveDashboard.primary.orders.variation} href="/sales/orders" />
                                    <ExecutiveKpi label="Créances clients" value={formatMoney(executiveDashboard.primary.receivables.value)} variation={null} href="/finance/creances" />
                                </div>
                            </section>

                            <div className="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
                                <SalesCollectionsChart points={executiveDashboard.charts.sales_vs_collections} />
                                <OrderStatesChart states={executiveDashboard.charts.order_states} />
                            </div>

                            <section aria-label="Indicateurs secondaires" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                                <CompactMetric label="Panier moyen" value={formatMoney(executiveDashboard.secondary.average_basket)} />
                                <CompactMetric label="Partiellement payées" value={formatInteger(executiveDashboard.secondary.partial_orders)} />
                                <CompactMetric label="Non payées" value={formatInteger(executiveDashboard.secondary.unpaid_orders)} />
                                <CompactMetric label="Remboursements" value={formatMoney(executiveDashboard.secondary.refunds)} />
                                <CompactMetric label="Retours reçus" value={`${formatInteger(executiveDashboard.secondary.returns.count)} · ${formatMoney(executiveDashboard.secondary.returns.amount)}`} />
                                <CompactMetric label="Articles à cataloguer" value={formatInteger(executiveDashboard.secondary.out_of_stock)} />
                            </section>

                            <div className="grid gap-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(300px,.8fr)]">
                                <RankedMoneyChart title="Top produits" description="Top 5 par chiffre d’affaires TTC des lignes vendues" points={executiveDashboard.charts.top_products} emptyLabel="Aucune vente produit sur cette période." />
                                <section className="rounded-card border border-line bg-surface p-5 shadow-card">
                                    <h2 className="font-semibold text-ink">À surveiller</h2>
                                    <p className="mt-1 text-[13px] text-ink-muted">Points opérationnels nécessitant une attention.</p>
                                    <div className="mt-4 divide-y divide-line">
                                        {executiveDashboard.attention.map((item) => <Link key={item.label} href={item.href} className="flex items-center justify-between gap-4 py-3 text-sm hover:text-primary"><span className="text-ink-muted">{item.label}</span><strong className="tabular-nums text-ink">{item.kind === 'money' ? formatMoney(item.value) : formatInteger(Number(item.value))}</strong></Link>)}
                                    </div>
                                </section>
                            </div>
                        </>
                    ) : (
                        <section aria-labelledby="activity-title">
                            <h2 id="activity-title" className="mb-3 text-[13px] font-semibold uppercase tracking-wider text-ink-faint">Votre activité</h2>
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                {metrics.filter((metric) => can(metric.permission) && (!metric.needsStore || tenant.store)).map((metric) => (
                                    <Link key={metric.label} href={metric.href} className="rounded-card border border-line bg-surface p-5 shadow-card transition-soft hover:border-line-strong"><p className="text-sm text-ink-muted">{metric.label}</p><p className="mt-2 text-3xl font-semibold tabular-nums text-ink">{metric.value ?? '—'}</p></Link>
                                ))}
                            </div>
                        </section>
                    )}

                    {quickEntries.length > 0 && (
                        <section><h2 className="mb-3 text-[13px] font-semibold uppercase tracking-wider text-ink-faint">Accès rapide</h2><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{quickEntries.map((entry) => <Link key={entry.href} href={entry.href} className="rounded-card border border-line bg-surface p-4 text-sm font-medium text-ink transition-soft hover:border-line-strong hover:bg-raised">{entry.label}</Link>)}</div></section>
                    )}

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

function ExecutiveKpi({ label, value, variation, href }: { label: string; value: string; variation: string | null; href: string }) {
    const numericVariation = variation === null ? null : Number(variation);
    return (
        <Link href={href} className="rounded-card border border-line bg-surface p-5 shadow-card transition-soft hover:border-line-strong">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{label}</p>
            <p className="mt-3 text-3xl font-semibold tabular-nums tracking-tight text-ink">{value}</p>
            <p className={`mt-2 text-xs font-medium ${numericVariation === null ? 'text-ink-faint' : numericVariation >= 0 ? 'text-success' : 'text-danger'}`}>
                {numericVariation === null ? 'Comparaison indisponible' : `${numericVariation >= 0 ? '+' : ''}${numericVariation.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} % vs mois précédent`}
            </p>
        </Link>
    );
}

function CompactMetric({ label, value }: { label: string; value: string }) {
    return <div className="rounded-card border border-line bg-raised px-4 py-3"><p className="text-[11px] uppercase tracking-wide text-ink-faint">{label}</p><p className="mt-1.5 font-semibold tabular-nums text-ink">{value}</p></div>;
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
