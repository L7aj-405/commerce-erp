import SandboxPaymentBadge from '@/components/SandboxPaymentBadge';
import { AdvancedFilterBar, ChoiceFilter, DateFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge, { Badge } from '@/components/ui/StatusBadge';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import PaymentsLayout from '@/layouts/PaymentsLayout';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type Account = { id: number; name: string; code: string };
type Payment = {
    id: number;
    payment_number: string;
    method: string;
    status: string;
    amount: string;
    currency_code: string;
    payment_date: string;
    reference: string | null;
    financial_account: Account | null;
    allocations: { sales_order: { id: number; order_number: string; customer_name: string | null; customer_company: string | null } }[];
    received_by: { name: string } | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    payments: { data: Payment[]; links: PageLink[]; from?: number | null; to?: number | null; total?: number };
    accounts: Account[];
    filters: Record<string, string | number | undefined>;
};

export default function PaymentIndex({ payments, accounts, filters }: Props) {
    const { t } = useI18n();
    // Exactly the params PaymentController@index validates.
    const f = useQueryFilters('/payments', filters as Record<string, string | undefined>, {
        keys: ['search', 'date', 'method', 'status', 'account'],
    });
    const methodOptions = [
        { value: 'cash', label: t('payments.cash') },
        { value: 'card', label: t('payments.card') },
        { value: 'bank_transfer', label: t('payments.bankTransfer') },
        { value: 'cheque', label: t('payments.cheque') },
    ];
    const statusOptions = [
        { value: 'posted', label: t('status.posted') },
        { value: 'reversed', label: t('status.reversed') },
    ];
    const accountOptions = accounts.map((account) => ({ value: String(account.id), label: `${account.code} · ${account.name}` }));
    const value = (key: string) => (filters[key] === undefined || filters[key] === null || filters[key] === '' ? undefined : String(filters[key]));

    const chips: FilterChip[] = [];
    if (value('search')) chips.push({ key: 'search', label: t('filters.search'), value: value('search')!, onRemove: () => f.remove('search') });
    if (value('date')) chips.push({ key: 'date', label: t('common.date'), value: formatDate(value('date')!), onRemove: () => f.remove('date') });
    const optionChip = (key: string, label: string, options: { value: string; label: string }[]) => {
        const current = value(key);
        if (current) chips.push({ key, label, value: options.find((option) => option.value === current)?.label ?? current, onRemove: () => f.remove(key) });
    };
    optionChip('method', t('payments.method'), methodOptions);
    optionChip('status', t('common.status'), statusOptions);
    optionChip('account', t('payments.account'), accountOptions);
    const filtered = chips.length > 0;

    const emptyTitle = filtered ? t('filters.noMatch') : t('payments.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('payments.emptyHelp');
    const emptyAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : undefined;
    const pager = <TablePager links={payments.links} from={payments.from} to={payments.to} total={payments.total} />;

    return (
        <PaymentsLayout>
            <Head title={t('payments.title')} />
            <PageHeader
                title={t('payments.title')}
                description={t('payments.description')}
            />
            <SandboxPaymentBadge />

            <div className="mb-4">
                <AdvancedFilterBar
                    label={t('payments.title')}
                    search={
                        <FilterSearch
                            value={f.search}
                            onChange={f.setSearch}
                            loading={f.searching}
                            label={t('common.search')}
                            placeholder={t('payments.searchPlaceholder')}
                        />
                    }
                    chips={chips}
                    activeCount={chips.length}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <DateFilter label={t('common.date')} value={value('date')} onChange={(date) => f.set('date', date)} />
                    <ChoiceFilter label={t('payments.method')} allLabel={t('payments.allMethods')} options={methodOptions} value={value('method')} onChange={(next) => f.set('method', next)} />
                    <ChoiceFilter label={t('common.status')} allLabel={t('common.allStatuses')} options={statusOptions} value={value('status')} onChange={(next) => f.set('status', next)} />
                    <ChoiceFilter
                        label={t('payments.account')}
                        allLabel={t('payments.allAccounts')}
                        searchable={accountOptions.length > 8}
                        options={accountOptions}
                        value={value('account')}
                        onChange={(next) => f.set('account', next)}
                    />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('payments.title')}
                state={payments.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={emptyAction}
                className="hidden md:block"
                footer={payments.data.length === 0 ? undefined : pager}
            >
                <table className="erp-table min-w-[1040px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-3">{t('payments.number')}</th>
                            <th className="px-4 py-3">{t('common.date')}</th>
                            <th className="px-4 py-3">{t('common.customer')}</th>
                            <th className="px-4 py-3">{t('payments.orderInvoice')}</th>
                            <th className="px-4 py-3">{t('payments.method')}</th>
                            <th className="px-4 py-3">{t('payments.financialAccount')}</th>
                            <th className="px-4 py-3">{t('common.status')}</th>
                            <th className="num px-4 py-3">{t('payments.amount')}</th>
                            <th className="px-4 py-3 text-end">
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {payments.data.map((payment) => {
                            const order = payment.allocations[0]?.sales_order;
                            const customer = order?.customer_name ?? order?.customer_company ?? t('common.counterCustomer');
                            const reversed = payment.status === 'reversed';

                            return (
                                <tr key={payment.id}>
                                    <td className="px-4 py-3">
                                        <Link href={`/payments/${payment.id}`} className="font-semibold text-ink underline-offset-2 hover:underline">
                                            <bdi>{payment.payment_number}</bdi>
                                        </Link>
                                        {payment.reference && (
                                            <p className="mt-0.5 text-xs text-ink-faint">
                                                <bdi>{payment.reference}</bdi>
                                            </p>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 tabular-nums text-ink-muted">{formatDate(payment.payment_date)}</td>
                                    <td className="px-4 py-3 font-medium text-ink">{customer}</td>
                                    <td className="px-4 py-3">
                                        {order ? (
                                            <Link href={`/sales/orders/${order.id}`} className="font-medium text-ink underline-offset-2 hover:underline">
                                                <bdi>{order.order_number}</bdi>
                                            </Link>
                                        ) : (
                                            <span className="text-ink-faint">—</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <MethodBadge method={payment.method} />
                                    </td>
                                    <td className="px-4 py-3 text-ink-muted">{payment.financial_account?.name ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        <StatusBadge status={payment.status} />
                                    </td>
                                    <td className={`num px-4 py-3 font-semibold ${reversed ? 'text-ink-muted' : 'text-ink'}`} dir="ltr">
                                        {formatMoney(payment.amount, payment.currency_code)}
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        <Link
                                            href={`/payments/${payment.id}`}
                                            aria-label={`${t('common.open')} ${payment.payment_number}`}
                                            className="inline-flex min-h-9 items-center rounded-field px-3 text-sm font-medium text-ink transition-soft hover:bg-sage"
                                        >
                                            {t('common.open')}
                                        </Link>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile: stacked cards */}
            {payments.data.length === 0 ? (
                <div className="md:hidden">
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={emptyAction} />
                </div>
            ) : (
                <div className="space-y-3 md:hidden" aria-busy={f.loading || undefined}>
                    <ul className={`space-y-3 transition-opacity ${f.loading ? 'opacity-55' : ''}`}>
                        {payments.data.map((payment) => {
                            const order = payment.allocations[0]?.sales_order;
                            const customer = order?.customer_name ?? order?.customer_company ?? t('common.counterCustomer');
                            const reversed = payment.status === 'reversed';

                            return (
                                <li key={payment.id} className="surface-card p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <Link href={`/payments/${payment.id}`} className="block truncate font-semibold text-ink underline-offset-2 hover:underline">
                                                <bdi>{payment.payment_number}</bdi>
                                            </Link>
                                            <p className="truncate text-sm text-ink-muted">{customer}</p>
                                        </div>
                                        <p className={`shrink-0 font-semibold tabular-nums ${reversed ? 'text-ink-muted' : 'text-ink'}`} dir="ltr">
                                            {formatMoney(payment.amount, payment.currency_code)}
                                        </p>
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <MethodBadge method={payment.method} />
                                        <StatusBadge status={payment.status} />
                                    </div>
                                    <dl className="mt-4 grid grid-cols-2 gap-x-3 gap-y-2 text-[13px]">
                                        <Info label={t('common.date')} value={formatDate(payment.payment_date)} />
                                        <Info label={t('payments.account')} value={payment.financial_account?.name ?? '—'} />
                                        <div className="min-w-0">
                                            <dt className="text-ink-faint">{t('payments.order')}</dt>
                                            <dd className="truncate text-ink-muted">
                                                {order ? (
                                                    <Link href={`/sales/orders/${order.id}`} className="underline-offset-2 hover:underline">
                                                        <bdi>{order.order_number}</bdi>
                                                    </Link>
                                                ) : (
                                                    '—'
                                                )}
                                            </dd>
                                        </div>
                                        <Info label={t('payments.receivedBy')} value={payment.received_by?.name ?? '—'} />
                                    </dl>
                                </li>
                            );
                        })}
                    </ul>
                    {pager}
                </div>
            )}
        </PaymentsLayout>
    );
}

function MethodBadge({ method }: { method: string }) {
    const { t } = useI18n();
    const labels: Record<string, string> = { cash: t('payments.cash'), card: t('payments.card'), bank_transfer: t('payments.bankTransfer'), cheque: t('payments.cheque') };

    return <Badge variant="neutral">{labels[method] ?? method.replaceAll('_', ' ')}</Badge>;
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0">
            <dt className="text-ink-faint">{label}</dt>
            <dd className="truncate text-ink-muted">{value}</dd>
        </div>
    );
}
