import { useDebouncedValue } from '@/components/pos/useDebouncedValue';
import SandboxReturnsBadge from '@/components/SandboxReturnsBadge';
import { FilterSearch } from '@/components/ui/AdvancedFilterBar';
import { ButtonLink } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { rowLink, TablePager, TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import SalesLayout from '@/layouts/SalesLayout';
import type { SharedPageProps } from '@/types/app';
import type { BadgeTone } from '@/utils/labels';
import { formatDateTime as formatDate, formatMoney } from '@/utils/format';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type LinkData = { url: string | null; label: string; active: boolean };
type Result = {
    id: number;
    order_number: string;
    customer_name: string | null;
    fulfilled_at: string;
    deadline: string;
    eligible: boolean;
    returns_enabled: boolean;
    invoice_numbers: string[];
};
type ReturnRow = {
    id: number;
    return_number: string;
    status: string;
    total_incl_tax: string;
    currency_code: string;
    sales_order: { id: number; order_number: string; customer_name: string | null };
};
type Props = {
    returns: { data: ReturnRow[]; links: LinkData[]; from?: number | null; to?: number | null; total?: number };
    searchUrl: string;
};

const statusTone: Record<string, BadgeTone> = { draft: 'warning', received: 'positive', cancelled: 'danger' };

export default function ReturnsIndex({ returns, searchUrl }: Props) {
    const { t } = useI18n();
    const { tenant } = usePage<SharedPageProps>().props;
    const canViewOrders = tenant.permissions.includes('sales_orders.view');
    const [query, setQuery] = useState('');
    const debounced = useDebouncedValue(query.trim(), 250);
    const [rows, setRows] = useState<Result[]>([]);
    const [lookup, setLookup] = useState<'idle' | 'loading' | 'error' | 'done'>('idle');
    const abort = useRef<AbortController | null>(null);

    // Eligible-order lookup for starting a return (GET returns.search). It is not a
    // filter of the history list below — that list has no server-side filters.
    useEffect(() => {
        abort.current?.abort();
        if (!debounced) {
            setRows([]);
            setLookup('idle');
            return;
        }
        const controller = new AbortController();
        abort.current = controller;
        setLookup('loading');
        fetch(`${searchUrl}?search=${encodeURIComponent(debounced)}`, { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then((payload) => {
                setRows(payload.data ?? []);
                setLookup('done');
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setRows([]);
                    setLookup('error');
                }
            });
        return () => controller.abort();
    }, [debounced, searchUrl]);

    const statusLabel = (status: string) =>
        ({ draft: t('returns.draft'), received: t('returns.received'), cancelled: t('returns.cancelled') })[status] ?? status.replaceAll('_', ' ');
    const customer = (row: ReturnRow) => row.sales_order.customer_name || t('common.counterCustomer');
    const orderRef = (row: ReturnRow) =>
        canViewOrders ? (
            <Link href={`/sales/orders/${row.sales_order.id}`} className="font-medium text-ink underline-offset-2 hover:text-accent hover:underline">
                <bdi>{row.sales_order.order_number}</bdi>
            </Link>
        ) : (
            <bdi className="font-medium text-ink">{row.sales_order.order_number}</bdi>
        );

    return (
        <SalesLayout>
            <Head title={t('returns.title')} />
            <SandboxReturnsBadge kind="return" />
            <PageHeader title={t('returns.title')} description={t('returns.description')} />

            <div className="grid items-start gap-4 2xl:grid-cols-[minmax(22rem,30rem)_minmax(0,1fr)]">
                {/* Start a return from a delivered sale */}
                <section aria-labelledby="return-start" className="surface-card p-4 sm:p-5">
                    <h2 id="return-start" className="text-base font-semibold text-ink">
                        {t('returns.startTitle')}
                    </h2>
                    <p className="mt-0.5 mb-3 text-sm text-ink-muted">{t('returns.searchHelp')}</p>
                    <FilterSearch value={query} onChange={setQuery} loading={lookup === 'loading'} label={t('returns.search')} placeholder={t('returns.search')} />
                    <div className="mt-2 divide-y divide-line" aria-live="polite">
                        {lookup === 'done' && rows.length === 0 && <p className="py-4 text-sm text-ink-muted">{t('returns.noSale')}</p>}
                        {lookup === 'error' && (
                            <p className="mt-2 rounded-field bg-danger-soft px-3 py-2 text-sm text-danger" role="alert">
                                {t('returns.lookupError')}
                            </p>
                        )}
                        {rows.map((row) => (
                            <div key={row.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div className="min-w-0">
                                    <p className="font-semibold text-ink">
                                        <bdi>{row.order_number}</bdi> · {row.customer_name || t('common.counterCustomer')}
                                    </p>
                                    <p className="text-[13px] text-ink-muted">
                                        {t('returns.invoiceDelivered')
                                            .replace('{invoice}', row.invoice_numbers.join(', ') || '—')
                                            .replace('{date}', formatDate(row.fulfilled_at))}
                                    </p>
                                    <div className="mt-1.5">
                                        <DocBadge tone={row.eligible ? 'positive' : 'danger'}>
                                            {row.eligible ? t('returns.authorized') : row.returns_enabled ? t('returns.deadlineExceeded') : t('returns.disabled')} ·{' '}
                                            {t('returns.until').replace('{date}', row.deadline ? formatDate(row.deadline) : '—')}
                                        </DocBadge>
                                    </div>
                                </div>
                                <ButtonLink href={`/sales/orders/${row.id}/returns/create`} size="sm" variant="secondary">
                                    {t('returns.create')}
                                </ButtonLink>
                            </div>
                        ))}
                    </div>
                </section>

                {/* History */}
                <div className="min-w-0">
                    <div className="mb-3">
                        <h2 className="text-base font-semibold text-ink">{t('returns.history')}</h2>
                        <p className="text-sm text-ink-muted">{t('returns.historyHelp')}</p>
                    </div>

                    <TableShell
                        label={t('returns.history')}
                        state={returns.data.length === 0 ? 'empty' : 'ready'}
                        emptyTitle={t('returns.empty')}
                        emptyDescription={t('returns.emptyHelp')}
                        className="hidden lg:block"
                        footer={returns.data.length === 0 ? undefined : <TablePager links={returns.links} from={returns.from} to={returns.to} total={returns.total} />}
                    >
                        <table className="erp-table min-w-[680px] text-sm">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('returns.number')}</th>
                                    <th className="px-4 py-2.5">{t('returns.orderCustomer')}</th>
                                    <th className="px-4 py-2.5">{t('common.status')}</th>
                                    <th className="num px-4 py-2.5">{t('returns.returnedAmount')}</th>
                                    <th className="px-4 py-2.5">
                                        <span className="sr-only">{t('common.actions')}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {returns.data.map((row) => {
                                    const link = rowLink(`/sales/returns/${row.id}`);
                                    return (
                                        <tr key={row.id} onClick={link.onClick} className={link.className}>
                                            <td className="px-4 py-3">
                                                <Link href={`/sales/returns/${row.id}`} className="font-semibold text-ink hover:text-accent hover:underline">
                                                    <bdi>{row.return_number}</bdi>
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3">
                                                {orderRef(row)}
                                                <span className="block text-[13px] text-ink-muted">{customer(row)}</span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <DocBadge tone={statusTone[row.status] ?? 'neutral'}>{statusLabel(row.status)}</DocBadge>
                                            </td>
                                            <td className="num px-4 py-3 font-semibold text-ink" dir="ltr">
                                                {formatMoney(row.total_incl_tax, row.currency_code)}
                                            </td>
                                            <td className="px-4 py-3 text-end">
                                                <ButtonLink href={`/sales/returns/${row.id}`} size="sm" variant="outline">
                                                    {t('common.open')}
                                                    <span className="sr-only"> {row.return_number}</span>
                                                </ButtonLink>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </TableShell>

                    <div className="lg:hidden">
                        {returns.data.length === 0 ? (
                            <EmptyState compact title={t('returns.empty')} description={t('returns.emptyHelp')} />
                        ) : (
                            <ul className="grid gap-3 sm:grid-cols-2">
                                {returns.data.map((row) => (
                                    <li key={row.id} className="surface-card flex flex-col p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <Link href={`/sales/returns/${row.id}`} className="block truncate text-base font-semibold text-ink hover:underline">
                                                    <bdi>{row.return_number}</bdi>
                                                </Link>
                                                <p className="truncate text-[13px] text-ink-muted">
                                                    {orderRef(row)} · {customer(row)}
                                                </p>
                                            </div>
                                            <DocBadge tone={statusTone[row.status] ?? 'neutral'}>{statusLabel(row.status)}</DocBadge>
                                        </div>
                                        <p className="metric-value mt-3 text-end text-base font-semibold text-ink">{formatMoney(row.total_incl_tax, row.currency_code)}</p>
                                        <ButtonLink href={`/sales/returns/${row.id}`} size="sm" variant="outline" className="mt-3 w-full">
                                            {t('common.open')}
                                            <span className="sr-only"> {row.return_number}</span>
                                        </ButtonLink>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div className="mt-4">
                            <TablePager links={returns.links} from={returns.from} to={returns.to} total={returns.total} />
                        </div>
                    </div>
                </div>
            </div>
        </SalesLayout>
    );
}
