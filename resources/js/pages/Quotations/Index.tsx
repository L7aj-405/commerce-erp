import { AdvancedFilterBar, ChoiceFilter, DateFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { rowLink, TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import SalesLayout from '@/layouts/SalesLayout';
import type { SharedPageProps } from '@/types/app';
import { formatDate, formatMoney } from '@/utils/format';
import { label, quotationStatusLabel, quotationStatusTone } from '@/utils/labels';
import { Head, Link, usePage } from '@inertiajs/react';

type Quotation = {
    id: number;
    quotation_number: string | null;
    quotation_date: string;
    valid_until: string | null;
    customer_name: string | null;
    customer_company: string | null;
    total_incl_tax: string;
    currency_code: string;
    status: string;
    created_by: { name: string } | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Filters = { search?: string; status?: string; quotation_date?: string; validity?: string };
type Props = {
    quotations: { data: Quotation[]; links: PageLink[]; from?: number | null; to?: number | null; total?: number };
    filters: Filters;
};

// Exactly the single-value params QuotationController@index validates.
const FILTER_KEYS = ['search', 'status', 'quotation_date', 'validity'] as const;
const STATUSES = ['draft', 'issued', 'accepted', 'rejected', 'expired', 'converted', 'superseded'];

export default function QuotationIndex({ quotations, filters }: Props) {
    const { t } = useI18n();
    const { tenant } = usePage<SharedPageProps>().props;
    const canCreate = tenant.permissions.includes('quotations.create');
    const f = useQueryFilters('/quotations', filters, { keys: [...FILTER_KEYS] });

    const statusOptions = STATUSES.map((value) => ({ value, label: label(quotationStatusLabel, value) }));
    const validityOptions = [
        { value: 'valid', label: t('quotations.valid') },
        { value: 'expired', label: t('quotations.expired') },
    ];
    const optionLabel = (options: { value: string; label: string }[], value: string) => options.find((option) => option.value === value)?.label ?? value;

    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.status) chips.push({ key: 'status', label: t('common.status'), value: optionLabel(statusOptions, filters.status), onRemove: () => f.remove('status') });
    if (filters.validity)
        chips.push({ key: 'validity', label: t('quotations.validity'), value: optionLabel(validityOptions, filters.validity), onRemove: () => f.remove('validity') });
    if (filters.quotation_date)
        chips.push({ key: 'quotation_date', label: t('common.date'), value: formatDate(filters.quotation_date), onRemove: () => f.remove('quotation_date') });

    const expired = (q: Quotation) => q.status === 'issued' && q.valid_until !== null && new Date(q.valid_until) < new Date(new Date().toDateString());
    const customer = (q: Quotation) => q.customer_company ?? q.customer_name ?? '—';
    const number = (q: Quotation) => q.quotation_number ?? t('quotations.draft');

    const filtered = f.activeCount > 0;
    const resetAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : canCreate ? (
        <ButtonLink href="/quotations/create">{t('quotations.new')}</ButtonLink>
    ) : undefined;
    const emptyTitle = filtered ? t('filters.noMatch') : t('quotations.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('quotations.description');

    const validity = (q: Quotation) => (
        <span className="inline-flex flex-wrap items-center gap-1.5">
            <span className="tabular-nums">{q.valid_until ? formatDate(q.valid_until) : '—'}</span>
            {expired(q) && <DocBadge tone="warning">{t('quotations.expired')}</DocBadge>}
        </span>
    );

    return (
        <SalesLayout>
            <Head title={t('quotations.title')} />
            <PageHeader
                title={t('quotations.title')}
                description={t('quotations.description')}
                actions={
                    canCreate ? (
                        <ButtonLink href="/quotations/create">
                            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
                                <path d="M12 5v14M5 12h14" />
                            </svg>
                            {t('quotations.new')}
                        </ButtonLink>
                    ) : undefined
                }
            />

            <div className="mb-4">
                <AdvancedFilterBar
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('quotations.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter label={t('common.status')} options={statusOptions} value={filters.status} onChange={(value) => f.set('status', value)} />
                    <ChoiceFilter label={t('quotations.validity')} options={validityOptions} value={filters.validity} onChange={(value) => f.set('validity', value)} />
                    <DateFilter label={t('common.date')} value={filters.quotation_date} onChange={(value) => f.set('quotation_date', value)} />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('quotations.title')}
                state={quotations.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={resetAction}
                className="hidden xl:block"
                footer={quotations.data.length === 0 ? undefined : <TablePager links={quotations.links} from={quotations.from} to={quotations.to} total={quotations.total} />}
            >
                <table className="erp-table min-w-[800px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('quotations.title')}</th>
                            <th className="px-4 py-2.5">{t('common.date')}</th>
                            <th className="px-4 py-2.5">{t('common.customer')}</th>
                            <th className="px-4 py-2.5">{t('quotations.validity')}</th>
                            <th className="px-4 py-2.5">{t('common.status')}</th>
                            <th className="num px-4 py-2.5">{t('quotations.totalTaxIncluded')}</th>
                            <th className="hidden px-4 py-2.5 2xl:table-cell">{t('quotations.createdBy')}</th>
                            <th className="px-4 py-2.5">
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {quotations.data.map((q) => {
                            const row = rowLink(`/quotations/${q.id}`);
                            return (
                                <tr key={q.id} onClick={row.onClick} className={row.className}>
                                    <td className="px-4 py-3">
                                        <Link href={`/quotations/${q.id}`} className="font-semibold text-ink hover:text-accent hover:underline">
                                            <bdi>{number(q)}</bdi>
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">{formatDate(q.quotation_date)}</td>
                                    <td className="max-w-72 truncate px-4 py-3 font-medium text-ink">{customer(q)}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-ink-muted">{validity(q)}</td>
                                    <td className="px-4 py-3">
                                        <DocBadge tone={quotationStatusTone(q.status)}>{label(quotationStatusLabel, q.status)}</DocBadge>
                                    </td>
                                    <td className="num px-4 py-3 font-semibold text-ink" dir="ltr">
                                        {formatMoney(q.total_incl_tax, q.currency_code)}
                                    </td>
                                    <td className="hidden max-w-48 truncate px-4 py-3 text-ink-muted 2xl:table-cell">{q.created_by?.name ?? '—'}</td>
                                    <td className="px-4 py-3 text-end">
                                        <ButtonLink href={`/quotations/${q.id}`} size="sm" variant="outline">
                                            {t('common.open')}
                                            <span className="sr-only"> {number(q)}</span>
                                        </ButtonLink>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile: stacked cards (the filter bar above is shared with desktop) */}
            <div className="xl:hidden" aria-busy={f.loading || undefined}>
                {quotations.data.length === 0 ? (
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={resetAction} />
                ) : (
                    <ul className={`grid gap-3 sm:grid-cols-2 lg:grid-cols-3 transition-opacity ${f.loading ? 'opacity-55' : ''}`}>
                        {quotations.data.map((q) => (
                            <li key={q.id} className="surface-card flex flex-col p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/quotations/${q.id}`} className="block truncate text-base font-semibold text-ink hover:underline">
                                            <bdi>{number(q)}</bdi>
                                        </Link>
                                        <p className="text-[13px] text-ink-muted tabular-nums">{formatDate(q.quotation_date)}</p>
                                    </div>
                                    <p className="metric-value shrink-0 text-end text-base font-semibold text-ink">{formatMoney(q.total_incl_tax, q.currency_code)}</p>
                                </div>
                                <p className="mt-2 truncate text-sm font-medium text-ink">{customer(q)}</p>
                                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                    <DocBadge tone={quotationStatusTone(q.status)}>{label(quotationStatusLabel, q.status)}</DocBadge>
                                    {expired(q) && <DocBadge tone="warning">{t('quotations.expired')}</DocBadge>}
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-muted">{t('quotations.validity')}</dt>
                                        <dd className="truncate text-ink tabular-nums">{q.valid_until ? formatDate(q.valid_until) : '—'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-muted">{t('quotations.createdBy')}</dt>
                                        <dd className="truncate text-ink">{q.created_by?.name ?? '—'}</dd>
                                    </div>
                                </dl>
                                <ButtonLink href={`/quotations/${q.id}`} size="sm" variant="outline" className="mt-3 w-full">
                                    {t('common.open')}
                                    <span className="sr-only"> {number(q)}</span>
                                </ButtonLink>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-4">
                    <TablePager links={quotations.links} from={quotations.from} to={quotations.to} total={quotations.total} />
                </div>
            </div>
        </SalesLayout>
    );
}
