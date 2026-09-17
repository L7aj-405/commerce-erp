import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SalesLayout from '@/layouts/SalesLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Customer = { id: number; display_name: string; company_name: string | null; email: string | null; phone: string | null; type: string; status: string };
type LinkData = { url: string | null; label: string; active: boolean };
type Shared = { tenant: { permissions: string[] }; [key: string]: unknown };
type Props = { customers: { data: Customer[]; links: LinkData[] }; filters: { search?: string; status?: string } };

const fieldClass = 'h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

export default function CustomerIndex({ customers, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const permissions = usePage<Shared>().props.tenant.permissions;
    const canCreate = permissions.includes('customers.create');
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/sales/customers', { ...filters, search: search || undefined }, { preserveState: true, replace: true });
    };

    return (
        <SalesLayout>
            <Head title="Clients" />
            <PageHeader
                title="Clients"
                description="Particuliers et entreprises enregistrés pour vos ventes, devis et factures."
                actions={canCreate ? <Link href="/sales/customers/create" className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg transition-soft hover:bg-primary-hover">+ Nouveau client</Link> : undefined}
            />

            <form onSubmit={submit} className="mb-6 flex flex-wrap gap-3 rounded-card border border-line bg-raised p-4">
                <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Nom, société, email ou téléphone"
                    className={`${fieldClass} w-full min-w-0 flex-1 sm:min-w-72 sm:w-auto`}
                />
                <select
                    value={filters.status ?? ''}
                    onChange={(e) => router.get('/sales/customers', { ...filters, status: e.target.value || undefined }, { preserveState: true })}
                    className={`${fieldClass} flex-1 sm:flex-none`}
                >
                    <option value="">Tous les statuts</option>
                    <option value="active">Actif</option>
                    <option value="inactive">Inactif</option>
                </select>
                <button type="submit" className="inline-flex min-h-10 flex-1 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised sm:flex-none">
                    Rechercher
                </button>
            </form>

            {customers.data.length === 0 ? (
                <EmptyState
                    title="Aucun client trouvé."
                    description="Ajustez votre recherche ou créez un nouveau client."
                    actions={canCreate ? <Link href="/sales/customers/create" className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg">+ Nouveau client</Link> : undefined}
                />
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-2.5">Nom</th>
                                    <th className="px-4 py-2.5">Société</th>
                                    <th className="px-4 py-2.5">Email</th>
                                    <th className="px-4 py-2.5">Téléphone</th>
                                    <th className="px-4 py-2.5">Type</th>
                                    <th className="px-4 py-2.5">Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                {customers.data.map((customer) => (
                                    <tr key={customer.id} className="border-t border-line">
                                        <td className="px-4 py-2.5 font-medium text-ink">
                                            <Link href={`/sales/customers/${customer.id}`}>{customer.display_name}</Link>
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{customer.company_name ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{customer.email ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{customer.phone ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{customer.type === 'company' ? 'Entreprise' : 'Particulier'}</td>
                                        <td className="px-4 py-2.5">
                                            <DocBadge tone={customer.status === 'active' ? 'positive' : 'neutral'}>{customer.status === 'active' ? 'Actif' : 'Inactif'}</DocBadge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {customers.data.map((customer) => (
                            <li key={customer.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/sales/customers/${customer.id}`} className="block truncate text-sm font-semibold text-ink">
                                            {customer.display_name}
                                        </Link>
                                        {customer.company_name && <p className="truncate text-[13px] text-ink-muted">{customer.company_name}</p>}
                                    </div>
                                    <DocBadge tone={customer.status === 'active' ? 'positive' : 'neutral'}>{customer.status === 'active' ? 'Actif' : 'Inactif'}</DocBadge>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Email</dt>
                                        <dd className="truncate text-ink-muted">{customer.email ?? '—'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Téléphone</dt>
                                        <dd className="truncate text-ink-muted">{customer.phone ?? '—'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Type</dt>
                                        <dd className="text-ink-muted">{customer.type === 'company' ? 'Entreprise' : 'Particulier'}</dd>
                                    </div>
                                </dl>
                                <Link
                                    href={`/sales/customers/${customer.id}`}
                                    className="mt-3 inline-flex min-h-9 items-center justify-center rounded-field border border-line-strong px-3 text-[13px] font-medium text-ink transition-soft hover:bg-sage"
                                >
                                    Voir
                                </Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={customers.links} />
        </SalesLayout>
    );
}
