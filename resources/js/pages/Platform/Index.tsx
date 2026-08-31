import { Button, ButtonLink } from '@/components/ui/Button';
import FormField from '@/components/ui/FormField';
import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
import type { SharedPageProps, TenantOrganization, TenantStore } from '@/types/app';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    organizations: TenantOrganization[];
    stores: TenantStore[];
    dashboard: { product_count: number | null; stocked_item_count: number | null; sales_today: number | null; payments_to_receive: number | null };
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
    const { tenant } = usePage<SharedPageProps>().props;
    const organizationForm = useForm({ name: '' });
    const storeForm = useForm({ name: '', code: '' });
    const can = (permission: string) => tenant.permissions.includes(permission);

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
        { label: "Ventes aujourd’hui", value: dashboard.sales_today, href: '/sales/orders', permission: 'sales_orders.view', needsStore: true },
        { label: 'Paiements à recevoir', value: dashboard.payments_to_receive, href: '/payments', permission: 'payments.view', needsStore: true },
    ];

    return <ApplicationShell>
        <Head title="Tableau de bord" />
        <PageHeader
            title="Tableau de bord"
            description={tenant.organization ? `${tenant.organization.name}${tenant.store ? ` · ${tenant.store.name}` : ''}` : 'Configurez votre espace de travail pour commencer.'}
            actions={tenant.store && can('pos.access') ? <ButtonLink href="/pos">Nouvelle vente</ButtonLink> : undefined}
        />

        {organizations.length === 0 && <section className="max-w-xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Première étape</p>
            <h2 className="mt-2 text-xl font-semibold">Créer votre organisation</h2>
            <p className="mt-1 text-sm text-slate-600">Elle contiendra vos magasins, produits et utilisateurs.</p>
            <form onSubmit={createOrganization} className="mt-5 space-y-4">
                <FormField label="Nom de l’organisation" name="name" required maxLength={255} autoComplete="organization" placeholder="AV Professional" value={organizationForm.data.name} onChange={event => organizationForm.setData('name', event.target.value)} error={organizationForm.errors.name} />
                <Button type="submit" disabled={organizationForm.processing}>{organizationForm.processing ? 'Création…' : 'Créer l’organisation'}</Button>
            </form>
        </section>}

        {tenant.organization && stores.length === 0 && can('stores.create') && <section className="max-w-xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Deuxième étape</p>
            <h2 className="mt-2 text-xl font-semibold">Créer votre premier magasin</h2>
            <p className="mt-1 text-sm text-slate-600">Les ventes et leur contexte opérationnel seront rattachés à ce magasin.</p>
            <form onSubmit={createStore} className="mt-5 grid gap-4 sm:grid-cols-2">
                <FormField label="Nom du magasin" name="name" required maxLength={255} placeholder="Magasin principal" value={storeForm.data.name} onChange={event => storeForm.setData('name', event.target.value)} error={storeForm.errors.name} />
                <FormField label="Code" name="code" required maxLength={64} placeholder="MAIN" value={storeForm.data.code} onChange={event => storeForm.setData('code', event.target.value.toUpperCase())} error={storeForm.errors.code} />
                <div className="sm:col-span-2"><Button type="submit" disabled={storeForm.processing}>{storeForm.processing ? 'Création…' : 'Créer le magasin'}</Button></div>
            </form>
        </section>}

        {tenant.organization && <div className="space-y-7">
            <section aria-labelledby="activity-title">
                <h2 id="activity-title" className="mb-3 text-base font-semibold">Votre activité</h2>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {metrics.filter(metric => can(metric.permission) && (!metric.needsStore || tenant.store)).map(metric => <Link key={metric.label} href={metric.href} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-300">
                        <p className="text-sm text-slate-600">{metric.label}</p>
                        <p className="mt-2 text-3xl font-semibold tabular-nums">{metric.value ?? '—'}</p>
                    </Link>)}
                </div>
            </section>

            <div className="grid gap-6 lg:grid-cols-[1.25fr_1fr]">
                <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold">Bien démarrer</h2>
                    <p className="mt-1 text-sm text-slate-600">Les étapes essentielles pour rendre votre espace opérationnel.</p>
                    <ol className="mt-5 space-y-3">
                        {checklist.map(([key, label]) => <li key={key} className="flex items-center gap-3 text-sm">
                            <span aria-hidden="true" className={`grid size-6 place-items-center rounded-full text-xs font-bold ${onboarding[key] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{onboarding[key] ? '✓' : '·'}</span>
                            <span className={onboarding[key] ? 'text-slate-500 line-through' : 'font-medium text-slate-800'}>{label}</span>
                        </li>)}
                    </ol>
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold">Prochaine action</h2>
                    <p className="mt-1 text-sm text-slate-600">Choisissez le chemin le plus adapté à votre activité.</p>
                    <div className="mt-5 grid gap-3">
                        {can('products.import') && <ButtonLink href="/catalog/products/import">Importer des produits</ButtonLink>}
                        {can('products.create') && <ButtonLink href="/catalog/products/create" variant="secondary">Ajouter un produit</ButtonLink>}
                        {can('inventory.opening') && <ButtonLink href="/inventory/stock" variant="secondary">Saisir le stock initial</ButtonLink>}
                        {tenant.store && can('pos.access') && <ButtonLink href="/pos" variant="secondary">Ouvrir le point de vente</ButtonLink>}
                    </div>
                </section>
            </div>
        </div>}
    </ApplicationShell>;
}
