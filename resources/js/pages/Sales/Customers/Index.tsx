import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { Button, ButtonLink } from '@/components/ui/Button';
import { useI18n } from '@/i18n';
import SalesLayout from '@/layouts/SalesLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Customer = { id: number; display_name: string; company_name: string | null; email: string | null; phone: string | null; type: string; status: string };
type LinkData = { url: string | null; label: string; active: boolean };
type Shared = { tenant: { permissions: string[] }; [key: string]: unknown };
type Props = { customers: { data: Customer[]; links: LinkData[] }; filters: { search?: string; status?: string } };

export default function CustomerIndex({ customers, filters }: Props) {
    const { t } = useI18n();
    const [search, setSearch] = useState(filters.search ?? '');
    const permissions = usePage<Shared>().props.tenant.permissions;
    const canCreate = permissions.includes('customers.create');
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/sales/customers', { ...filters, search: search || undefined }, { preserveState: true, replace: true });
    };

    return (
        <SalesLayout>
            <Head title={t('customers.title')} />
            <PageHeader
                title={t('customers.title')}
                description={t('customers.description')}
                actions={canCreate ? <ButtonLink href="/sales/customers/create">+ {t('customers.new')}</ButtonLink> : undefined}
            />

            <form onSubmit={submit} className="mb-6 flex flex-wrap gap-3 rounded-card border border-line bg-raised p-4">
                <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder={t('customers.searchPlaceholder')}
                    className="control-input w-full min-w-0 flex-1 sm:min-w-72 sm:w-auto"
                />
                <select
                    value={filters.status ?? ''}
                    onChange={(e) => router.get('/sales/customers', { ...filters, status: e.target.value || undefined }, { preserveState: true })}
                    className="control-select flex-1 sm:flex-none"
                >
                    <option value="">{t('common.allStatuses')}</option>
                    <option value="active">{t('status.active')}</option>
                    <option value="inactive">{t('status.inactive')}</option>
                </select>
                <Button type="submit" variant="secondary" className="flex-1 sm:flex-none">{t('common.search')}</Button>
            </form>

            {customers.data.length === 0 ? (
                <EmptyState
                    title={t('customers.empty')}
                    description={t('customers.emptyHelp')}
                    actions={canCreate ? <ButtonLink href="/sales/customers/create">+ {t('customers.new')}</ButtonLink> : undefined}
                />
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="erp-table">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('customers.name')}</th>
                                    <th className="px-4 py-2.5">{t('customers.company')}</th>
                                    <th className="px-4 py-2.5">{t('customers.email')}</th>
                                    <th className="px-4 py-2.5">{t('customers.phone')}</th>
                                    <th className="px-4 py-2.5">{t('customers.type')}</th>
                                    <th className="px-4 py-2.5">{t('common.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {customers.data.map((customer) => (
                                    <tr key={customer.id} className="border-t border-line">
                                        <td className="px-4 py-2.5 font-medium text-ink">
                                            <Link href={`/sales/customers/${customer.id}`}>{customer.display_name}</Link>
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{customer.company_name ?? '—'}</td>
                                        <td className="bidi-isolate px-4 py-2.5 text-ink-muted" dir="ltr">{customer.email ?? '—'}</td>
                                        <td className="bidi-isolate px-4 py-2.5 text-ink-muted" dir="ltr">{customer.phone ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{customer.type === 'company' ? t('customers.business') : t('customers.individual')}</td>
                                        <td className="px-4 py-2.5">
                                            <DocBadge tone={customer.status === 'active' ? 'positive' : 'neutral'}>{t(customer.status === 'active' ? 'status.active' : 'status.inactive')}</DocBadge>
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
                                    <DocBadge tone={customer.status === 'active' ? 'positive' : 'neutral'}>{t(customer.status === 'active' ? 'status.active' : 'status.inactive')}</DocBadge>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('customers.email')}</dt>
                                        <dd className="bidi-isolate truncate text-ink-muted" dir="ltr">{customer.email ?? '—'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('customers.phone')}</dt>
                                        <dd className="bidi-isolate truncate text-ink-muted" dir="ltr">{customer.phone ?? '—'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('customers.type')}</dt>
                                        <dd className="text-ink-muted">{customer.type === 'company' ? t('customers.business') : t('customers.individual')}</dd>
                                    </div>
                                </dl>
                                <Link
                                    href={`/sales/customers/${customer.id}`}
                                    className="mt-3 inline-flex min-h-9 items-center justify-center rounded-field border border-line-strong px-3 text-[13px] font-medium text-ink transition-soft hover:bg-sage"
                                >
                                    {t('common.view')}
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
