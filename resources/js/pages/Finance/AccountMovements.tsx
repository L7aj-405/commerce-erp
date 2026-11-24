import { Alert } from '@/components/ui/Alert';
import { Button, ButtonLink, DownloadLink } from '@/components/ui/Button';
import { SelectField, TextField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { paymentMethodLabel } from '@/utils/labels';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

type Movement = {
    key: string;
    type: string;
    date: string;
    direction: 'in' | 'out';
    money_in: string | null;
    money_out: string | null;
    currency_code: string;
    status: 'posted' | 'reversed' | string;
    reference: string | null;
    external_reference: string | null;
    method: string | null;
    counterparty: string | null;
    documents: string[];
    notes: string | null;
    reason: string | null;
    instrument: { number: string; type: string; state: string } | null;
    store: string | null;
    user: string | null;
    balance_scope: 'in_balance' | 'pre_opening' | 'after_as_of' | 'unconfigured';
    running_balance: string | null;
    linked: { key: string; type: string } | null;
    url: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Filters = { date_from: string | null; date_to: string | null; type: string | null; reference: string | null; store_id: number | string | null };
type Props = {
    account: { id: number; name: string; code: string; type: string; status: string; currency_code: string };
    position: { as_of: string; configured: boolean; effective_date: string | null; opening_amount: string | null; balance: string | null };
    running: { available: boolean; reason: 'not_configured' | 'not_reconciled' | null; has_reversals: boolean };
    filters: Filters;
    types: string[];
    stores: { id: number; name: string; code: string }[];
    rows: { data: Movement[]; links: LinkData[]; total: number; from?: number | null; to?: number | null };
    can: { export: boolean };
    exportMaxRows: number;
};

const key = (value: string) => value as TranslationKey;

export default function AccountMovements({ account, position, running, filters, types, stores, rows, can }: Props) {
    const { t } = useI18n();
    const page = usePage<{ errors?: Record<string, string> }>();
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
        type: filters.type ?? '',
        reference: filters.reference ?? '',
        store_id: filters.store_id ? String(filters.store_id) : '',
    });
    const base = `/financial-accounts/${account.id}/movements`;
    const clean = (values: Record<string, string>) => Object.fromEntries(Object.entries(values).filter(([, v]) => v !== ''));
    const query = new URLSearchParams(clean(form)).toString();
    const visit = (values: Record<string, string>) =>
        router.get(base, clean(values), { preserveState: true, preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit(form);
    };
    const reset = () => {
        const empty = { date_from: '', date_to: '', type: '', reference: '', store_id: '' };
        setForm(empty);
        visit(empty);
    };
    const money = (value: string | null, currency = account.currency_code) => (value === null ? '' : formatMoney(value, currency));
    const showRunning = running.available;
    const exportError = page.props.errors?.export;
    const methodLabel = (method: string | null) => (method ? (paymentMethodLabel[method] ?? (method === 'traite' ? t('accountMovements.instrument.traite') : method)) : null);

    return (
        <ApplicationShell wide>
            <Head title={`${t('accountMovements.title')} — ${account.name}`} />
            <PageHeader
                title={t('accountMovements.title')}
                description={`${account.name} · ${account.code} · ${account.currency_code}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <ButtonLink href="/financial-accounts" variant="secondary" size="sm">
                            {t('accountMovements.backToAccounts')}
                        </ButtonLink>
                        {can.export && (
                            <>
                                <DownloadLink href={`${base}/export/csv${query ? `?${query}` : ''}`} variant="secondary" size="sm" title={t('accountMovements.exportHint')}>
                                    {t('accountMovements.exportCsv')}
                                </DownloadLink>
                                <DownloadLink href={`${base}/export/xlsx${query ? `?${query}` : ''}`} variant="secondary" size="sm" title={t('accountMovements.exportHint')}>
                                    {t('accountMovements.exportXlsx')}
                                </DownloadLink>
                            </>
                        )}
                    </div>
                }
            />

            <div className="mb-4 grid gap-3 sm:grid-cols-2">
                <div className="surface-card p-4">
                    <p className="text-xs font-medium text-ink-muted">
                        {t('accountMovements.currentBalance')} {t('accountMovements.asOf')} {position.as_of}
                    </p>
                    <p className="mt-1 text-xl font-semibold tabular-nums" dir="ltr">
                        {position.configured ? money(position.balance) : t('accountMovements.notConfigured')}
                    </p>
                </div>
                <div className="surface-card p-4">
                    <p className="text-xs font-medium text-ink-muted">{t('accountMovements.openingBalance')}</p>
                    <p className="mt-1 text-xl font-semibold tabular-nums" dir="ltr">
                        {position.opening_amount !== null ? money(position.opening_amount) : t('accountMovements.notConfigured')}
                    </p>
                    {position.effective_date && (
                        <p className="text-xs text-ink-muted">
                            {t('accountMovements.since')} {position.effective_date}
                        </p>
                    )}
                </div>
            </div>

            <div className="mb-4 space-y-2">
                {exportError && <Alert tone="danger">{exportError}</Alert>}
                {running.reason === 'not_configured' && (
                    <Alert tone="warning" title={t('accountMovements.notConfiguredTitle')}>
                        {t('accountMovements.notConfiguredBody')}
                    </Alert>
                )}
                {running.reason === 'not_reconciled' && (
                    <Alert tone="danger" title={t('accountMovements.notReconciledTitle')}>
                        {t('accountMovements.notReconciledBody')}
                    </Alert>
                )}
                {running.has_reversals && <Alert tone="info">{t('accountMovements.reversalNote')}</Alert>}
            </div>

            <form onSubmit={submit} className="surface-card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6" aria-label={t('common.search')}>
                <TextField type="date" label={t('accountMovements.filter.dateFrom')} value={form.date_from} onChange={(e) => setForm({ ...form, date_from: e.target.value })} />
                <TextField type="date" label={t('accountMovements.filter.dateTo')} value={form.date_to} onChange={(e) => setForm({ ...form, date_to: e.target.value })} />
                <SelectField label={t('accountMovements.filter.type')} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                    <option value="">{t('accountMovements.filter.allTypes')}</option>
                    {types.map((type) => (
                        <option key={type} value={type}>
                            {t(key(`accountMovements.type.${type}`))}
                        </option>
                    ))}
                </SelectField>
                <TextField
                    label={t('accountMovements.filter.reference')}
                    placeholder={t('accountMovements.filter.referencePlaceholder')}
                    value={form.reference}
                    maxLength={100}
                    onChange={(e) => setForm({ ...form, reference: e.target.value })}
                />
                <SelectField label={t('accountMovements.filter.store')} value={form.store_id} onChange={(e) => setForm({ ...form, store_id: e.target.value })}>
                    <option value="">{t('common.allStores')}</option>
                    {stores.map((store) => (
                        <option key={store.id} value={store.id}>
                            {store.name}
                        </option>
                    ))}
                </SelectField>
                <div className="flex items-end gap-2">
                    <Button type="submit" loading={busy}>
                        {t('accountMovements.filter.apply')}
                    </Button>
                    <Button type="button" variant="secondary" onClick={reset}>
                        {t('common.reset')}
                    </Button>
                </div>
            </form>

            <TableShell
                label={t('accountMovements.title')}
                state={rows.data.length === 0 ? 'empty' : 'ready'}
                busy={busy}
                emptyTitle={t('accountMovements.empty')}
                emptyDescription={t('accountMovements.emptyHint')}
                footer={<TablePager links={rows.links} from={rows.from} to={rows.to} total={rows.total} />}
            >
                <table className="erp-table min-w-[960px]">
                    <thead>
                        <tr>
                            <th scope="col">{t('accountMovements.col.date')}</th>
                            <th scope="col">{t('accountMovements.col.movement')}</th>
                            <th scope="col">{t('accountMovements.col.description')}</th>
                            <th scope="col" className="text-end">
                                {t('accountMovements.col.in')}
                            </th>
                            <th scope="col" className="text-end">
                                {t('accountMovements.col.out')}
                            </th>
                            {showRunning && (
                                <th scope="col" className="text-end">
                                    {t('accountMovements.col.running')}
                                </th>
                            )}
                            <th scope="col">{t('accountMovements.col.user')}</th>
                            <th scope="col">{t('accountMovements.col.status')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.data.map((row) => {
                            const outOfBalance = row.balance_scope !== 'in_balance';
                            return (
                                <tr key={row.key} className={outOfBalance ? 'opacity-75' : undefined}>
                                    <td className="whitespace-nowrap tabular-nums" dir="ltr">
                                        {row.date}
                                    </td>
                                    <td>
                                        <p className="font-medium">{t(key(`accountMovements.type.${row.type}`))}</p>
                                        {row.reference &&
                                            (row.url ? (
                                                <Link href={row.url} className="text-sm underline">
                                                    {row.reference}
                                                </Link>
                                            ) : (
                                                <p className="text-sm text-ink-muted">{row.reference}</p>
                                            ))}
                                        {row.external_reference && <p className="text-xs text-ink-muted">{row.external_reference}</p>}
                                    </td>
                                    <td className="max-w-[320px]">
                                        {row.counterparty && <p>{row.counterparty}</p>}
                                        {row.documents.length > 0 && <p className="text-xs text-ink-muted">{row.documents.join(', ')}</p>}
                                        {row.instrument && (
                                            <p className="text-xs text-ink-muted">
                                                {row.instrument.type === 'traite' ? t('accountMovements.instrument.traite') : t('accountMovements.instrument.cheque')} {row.instrument.number} (
                                                {t(key(`accountMovements.instrumentState.${row.instrument.state}`))})
                                            </p>
                                        )}
                                        {methodLabel(row.method) && <p className="text-xs text-ink-muted">{methodLabel(row.method)}</p>}
                                        {row.reason && (
                                            <p className="text-xs text-ink-muted">
                                                {t('accountMovements.reason')} : {row.reason}
                                            </p>
                                        )}
                                        {row.linked && (
                                            <p className="text-xs text-ink-muted">
                                                {t('accountMovements.linked')} : {t(key(`accountMovements.type.${row.linked.type}`))}
                                            </p>
                                        )}
                                        {row.store && <p className="text-xs text-ink-muted">{row.store}</p>}
                                    </td>
                                    <td className="text-end tabular-nums" dir="ltr">
                                        {money(row.money_in, row.currency_code)}
                                    </td>
                                    <td className="text-end tabular-nums" dir="ltr">
                                        {money(row.money_out, row.currency_code)}
                                    </td>
                                    {showRunning && (
                                        <td className="text-end tabular-nums" dir="ltr">
                                            {row.running_balance === null ? '—' : money(row.running_balance, row.currency_code)}
                                        </td>
                                    )}
                                    <td>{row.user ?? '—'}</td>
                                    <td>
                                        <StatusBadge variant={row.status === 'reversed' ? 'neutral' : 'success'}>{t(key(`accountMovements.status.${row.status}`))}</StatusBadge>
                                        {outOfBalance && <p className="mt-1 text-xs text-warning">{t(key(`accountMovements.scope.${row.balance_scope}`))}</p>}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>
        </ApplicationShell>
    );
}
