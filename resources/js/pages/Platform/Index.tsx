import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Organization = {
    id: number;
    name: string;
    status: string;
};

type Store = {
    id: number;
    organization_id: number;
    name: string;
    code: string;
    status: string;
};

type SharedProps = {
    auth: {
        user: { id: number; name: string; email: string };
    };
    tenant: {
        organization: Organization | null;
        store: Store | null;
        permissions: string[];
    };
    [key: string]: unknown;
};

type Props = {
    organizations: Organization[];
    stores: Store[];
};

export default function PlatformIndex({ organizations, stores }: Props) {
    const { auth, tenant } = usePage<SharedProps>().props;
    const organizationForm = useForm({ name: '' });
    const storeForm = useForm({ name: '', code: '' });
    const [showOrganizationForm, setShowOrganizationForm] = useState(organizations.length === 0);
    const [showStoreForm, setShowStoreForm] = useState(
        Boolean(tenant.organization && stores.length === 0 && tenant.permissions.includes('stores.create')),
    );

    const createOrganization = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const isFirstOrganization = organizations.length === 0;

        organizationForm.post('/organizations', {
            preserveScroll: true,
            onSuccess: () => {
                organizationForm.reset();
                setShowOrganizationForm(false);
                setShowStoreForm(isFirstOrganization);
            },
        });
    };

    const createStore = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        storeForm.post('/stores', {
            preserveScroll: true,
            onSuccess: () => {
                storeForm.reset();
                setShowStoreForm(false);
            },
        });
    };

    const switchOrganization = (organization: Organization) => {
        if (organization.id === tenant.organization?.id) {
            return;
        }

        router.post(`/context/organizations/${organization.id}`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                storeForm.reset();
                setShowStoreForm(false);
            },
        });
    };

    const switchStore = (store: Store) => {
        if (store.id === tenant.store?.id) {
            return;
        }

        router.post(`/context/stores/${store.id}`, {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Platform Core" />

            <main className="mx-auto min-h-screen max-w-5xl space-y-8 px-6 py-10">
                <header className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Platform Core</h1>
                        <p className="text-sm text-slate-600">Signed in as {auth.user.email}</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => router.post('/logout')}
                        className="rounded border border-slate-300 px-4 py-2"
                    >
                        Sign out
                    </button>
                </header>

                <section className="grid gap-4 md:grid-cols-2">
                    <div className="rounded-xl border border-slate-200 p-5">
                        <h2 className="font-semibold">Active organization</h2>
                        <p className="mt-2 text-slate-700">{tenant.organization?.name ?? 'None selected'}</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 p-5">
                        <h2 className="font-semibold">Active store</h2>
                        <p className="mt-2 text-slate-700">{tenant.store?.name ?? 'None selected'}</p>
                    </div>
                </section>

                <section>
                    <div className="flex items-center justify-between gap-4">
                        <h2 className="text-lg font-semibold">Organizations</h2>
                        {!showOrganizationForm && (
                            <button
                                type="button"
                                onClick={() => setShowOrganizationForm(true)}
                                className="rounded border border-slate-300 px-4 py-2 text-sm"
                            >
                                + Create Organization
                            </button>
                        )}
                    </div>
                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                        {organizations.map((organization) => (
                            <div
                                key={organization.id}
                                className="flex items-center justify-between gap-4 rounded-lg border border-slate-200 p-4"
                            >
                                <div>
                                    <span className="font-medium">{organization.name}</span>
                                    <span className="ml-2 text-xs text-slate-500">#{organization.id}</span>
                                </div>
                                {organization.id === tenant.organization?.id ? (
                                    <span className="rounded bg-emerald-100 px-3 py-1 text-sm text-emerald-800">Active</span>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => switchOrganization(organization)}
                                        className="rounded border border-slate-300 px-3 py-1 text-sm hover:border-slate-500"
                                    >
                                        Switch
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>

                    {showOrganizationForm && (
                        <form
                            onSubmit={createOrganization}
                            className="mt-4 max-w-md space-y-4 rounded-xl border border-slate-200 p-5"
                        >
                            <h3 className="font-semibold">
                                {organizations.length === 0 ? 'Create your organization' : 'Create Organization'}
                            </h3>
                            <div>
                                <label htmlFor="organization-name" className="block text-sm font-medium text-slate-700">
                                    Organization name
                                </label>
                                <input
                                    id="organization-name"
                                    type="text"
                                    required
                                    maxLength={255}
                                    autoComplete="organization"
                                    value={organizationForm.data.name}
                                    onChange={(event) => organizationForm.setData('name', event.target.value)}
                                    className="mt-1 block w-full rounded border border-slate-300 px-3 py-2"
                                />
                                {organizationForm.errors.name && (
                                    <p className="mt-1 text-sm text-red-600">{organizationForm.errors.name}</p>
                                )}
                            </div>
                            <div className="flex gap-3">
                                <button
                                    type="submit"
                                    disabled={organizationForm.processing}
                                    className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50"
                                >
                                    {organizationForm.processing ? 'Creating…' : 'Create'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        organizationForm.clearErrors();
                                        setShowOrganizationForm(false);
                                    }}
                                    className="rounded border border-slate-300 px-4 py-2"
                                >
                                    Cancel
                                </button>
                            </div>
                        </form>
                    )}
                </section>

                <section>
                    <div className="flex items-center justify-between gap-4">
                        <h2 className="text-lg font-semibold">
                            Stores{tenant.organization ? ` — ${tenant.organization.name}` : ''}
                        </h2>
                        {tenant.organization && tenant.permissions.includes('stores.create') && !showStoreForm && (
                            <button
                                type="button"
                                onClick={() => setShowStoreForm(true)}
                                className="rounded border border-slate-300 px-4 py-2 text-sm"
                            >
                                + Create Store
                            </button>
                        )}
                    </div>
                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                        {stores.map((store) => (
                            <div
                                key={store.id}
                                className="flex items-center justify-between gap-4 rounded-lg border border-slate-200 p-4"
                            >
                                <div>
                                    <span className="font-medium">{store.name}</span>
                                    <span className="ml-2 text-xs text-slate-500">{store.code}</span>
                                </div>
                                {store.id === tenant.store?.id ? (
                                    <span className="rounded bg-emerald-100 px-3 py-1 text-sm text-emerald-800">Active</span>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => switchStore(store)}
                                        className="rounded border border-slate-300 px-3 py-1 text-sm hover:border-slate-500"
                                    >
                                        Switch
                                    </button>
                                )}
                            </div>
                        ))}
                        {tenant.organization && stores.length === 0 && !showStoreForm && (
                            <p className="text-sm text-slate-600">No accessible active stores.</p>
                        )}
                        {!tenant.organization && (
                            <p className="text-sm text-slate-600">Create or select an organization to manage stores.</p>
                        )}
                    </div>

                    {tenant.organization && showStoreForm && tenant.permissions.includes('stores.create') && (
                        <form
                            onSubmit={createStore}
                            className="mt-4 max-w-md space-y-4 rounded-xl border border-slate-200 p-5"
                        >
                            <h3 className="font-semibold">
                                {stores.length === 0 ? 'Create your first store' : 'Create Store'}
                            </h3>
                            <div>
                                <label htmlFor="store-name" className="block text-sm font-medium text-slate-700">
                                    Store name
                                </label>
                                <input
                                    id="store-name"
                                    type="text"
                                    required
                                    maxLength={255}
                                    value={storeForm.data.name}
                                    onChange={(event) => storeForm.setData('name', event.target.value)}
                                    className="mt-1 block w-full rounded border border-slate-300 px-3 py-2"
                                />
                                {storeForm.errors.name && (
                                    <p className="mt-1 text-sm text-red-600">{storeForm.errors.name}</p>
                                )}
                            </div>
                            <div>
                                <label htmlFor="store-code" className="block text-sm font-medium text-slate-700">
                                    Store code
                                </label>
                                <input
                                    id="store-code"
                                    type="text"
                                    required
                                    maxLength={64}
                                    value={storeForm.data.code}
                                    onChange={(event) => storeForm.setData('code', event.target.value.toUpperCase())}
                                    className="mt-1 block w-full rounded border border-slate-300 px-3 py-2"
                                />
                                {storeForm.errors.code && (
                                    <p className="mt-1 text-sm text-red-600">{storeForm.errors.code}</p>
                                )}
                            </div>
                            <div className="flex gap-3">
                                <button
                                    type="submit"
                                    disabled={storeForm.processing}
                                    className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50"
                                >
                                    {storeForm.processing ? 'Creating…' : 'Create'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        storeForm.clearErrors();
                                        setShowStoreForm(false);
                                    }}
                                    className="rounded border border-slate-300 px-4 py-2"
                                >
                                    Cancel
                                </button>
                            </div>
                        </form>
                    )}
                </section>

                <section className="rounded-xl bg-slate-50 p-5">
                    <h2 className="font-semibold">Effective permissions</h2>
                    <p className="mt-2 break-words text-sm text-slate-600">
                        {tenant.permissions.length > 0 ? tenant.permissions.join(', ') : 'No permissions in the active organization.'}
                    </p>
                </section>

                {tenant.permissions.includes('catalog.view') && (
                    <Link href="/catalog/products" className="inline-flex rounded bg-slate-900 px-4 py-2 text-white">
                        Open Catalog
                    </Link>
                )}

                {tenant.permissions.includes('inventory.view') && (
                    <Link href="/inventory/stock" className="ml-3 inline-flex rounded bg-slate-900 px-4 py-2 text-white">
                        Open Inventory
                    </Link>
                )}

                {tenant.permissions.includes('sales_orders.view') && tenant.store && (
                    <Link href="/sales/orders" className="ml-3 inline-flex rounded bg-slate-900 px-4 py-2 text-white">
                        Open Sales
                    </Link>
                )}

                {tenant.permissions.includes('pos.access') && (
                    <Link href="/pos" className="ml-3 inline-flex rounded bg-emerald-700 px-4 py-2 text-white">
                        Open POS
                    </Link>
                )}
            </main>
        </>
    );
}
