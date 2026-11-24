import { Alert } from '@/components/ui/Alert';
import { ButtonLink, DownloadLink } from '@/components/ui/Button';
import { MetricCard } from '@/components/ui/MetricCard';
import { TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import { formatMoney } from '@/utils/format';
import { Link } from '@inertiajs/react';

export type Aging = {
    as_of: string;
    rows: { customer_id: number; customer: string; currency_code: string | null; mixed_currency: boolean; receivable: string | null; unbilled: string | null; total: string | null; overdue: string | null; bands: Record<string, string> | null }[];
    totals: Record<string, Record<string, string>>;
    truncated: boolean;
};
export type Forecast = {
    today: string;
    by_currency: Record<string, Record<string, string>>;
    installments: { customer_id: number | null; customer: string; order_id: number; order_number: string; sequence: number; due_date: string; amount: string; outstanding: string; currency_code: string; is_overdue: boolean }[];
    instruments: { id: number; customer: string; type: string; number: string; amount: string; currency_code: string; expected_date: string | null; maturity_date: string | null; state: string }[];
    truncated: boolean;
};
export type Reminders = {
    followUps: { id: number; title: string; due_date: string | null; priority: string; customer: string; customer_id: number }[];
    promises: { id: number; promised_date: string; amount: string; currency_code: string; note: string | null; customer: string; customer_id: number }[];
};

const BANDS = ['not_due', '1_30', '31_60', '61_90', '90_plus', 'undated'] as const;
const BUCKETS = ['overdue', 'w1', 'w2', 'w3', 'w4', 'later', 'undated'] as const;

/** "Balance âgée": per customer, invoice receivables + unbilled exposure combined per band (the two totals stay visible). */
export function AgingSection({ aging, canExport, query }: { aging: Aging | null; canExport: boolean; query: string }) {
    const { t } = useI18n();
    if (!aging) return null;
    return (
        <>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-ink-muted">{t('fc3.aging.asOf')} <strong>{aging.as_of}</strong> — {t('fc3.aging.note')}</p>
                {canExport && (
                    <div className="flex gap-2">
                        <DownloadLink href={`/finance/clients/creances/balance-agee/export/csv?${query}`} variant="secondary" size="sm">CSV</DownloadLink>
                        <DownloadLink href={`/finance/clients/creances/balance-agee/export/xlsx?${query}`} variant="secondary" size="sm">Excel</DownloadLink>
                    </div>
                )}
            </div>
            {aging.truncated && <div className="mb-3"><Alert tone="warning">{t('fc2.accounts.tooMany')}</Alert></div>}
            <TableShell label={t('fc.rec.s.aging')} state={aging.rows.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc3.aging.empty')}>
                <table className="erp-table min-w-[1180px] text-sm">
                    <thead><tr>
                        <th className="px-3 py-2.5">{t('common.customer')}</th><th className="num px-3 py-2.5">{t('fc2.col.receivable')}</th><th className="num px-3 py-2.5">{t('fc2.col.unbilled')}</th><th className="num px-3 py-2.5">{t('common.total')}</th>
                        {BANDS.map((band) => <th key={band} className="num px-3 py-2.5">{t(`fc2.band.${band}` as TranslationKey)}</th>)}
                    </tr></thead>
                    <tbody>
                        {aging.rows.map((row) => (
                            <tr key={row.customer_id}>
                                <td className="px-3 py-2.5 font-medium"><Link href={`/finance/clients/comptes/${row.customer_id}?section=schedule`}>{row.customer}</Link></td>
                                {row.mixed_currency || !row.bands ? <td colSpan={9} className="px-3 py-2.5 text-ink-muted">{t('fc2.mixedCurrency')}</td> : (
                                    <>
                                        <td className="num px-3 py-2.5" dir="ltr">{formatMoney(row.receivable, row.currency_code ?? 'MAD')}</td>
                                        <td className="num px-3 py-2.5" dir="ltr">{formatMoney(row.unbilled, row.currency_code ?? 'MAD')}</td>
                                        <td className="num px-3 py-2.5 font-medium" dir="ltr">{formatMoney(row.total, row.currency_code ?? 'MAD')}</td>
                                        {BANDS.map((band) => <td key={band} className={`num px-3 py-2.5 ${band !== 'not_due' && band !== 'undated' && Number(row.bands?.[band]) > 0 ? 'text-danger' : ''}`} dir="ltr">{formatMoney(row.bands?.[band] ?? '0', row.currency_code ?? 'MAD')}</td>)}
                                    </>
                                )}
                            </tr>
                        ))}
                        {Object.entries(aging.totals).map(([currency, totals]) => (
                            <tr key={currency} className="font-semibold">
                                <td className="px-3 py-2.5">{t('common.total')} ({currency})</td>
                                <td className="num px-3 py-2.5" dir="ltr">{formatMoney(totals.receivable, currency)}</td><td className="num px-3 py-2.5" dir="ltr">{formatMoney(totals.unbilled, currency)}</td><td className="num px-3 py-2.5" dir="ltr">{formatMoney(totals.total, currency)}</td>
                                {BANDS.map((band) => <td key={band} className="num px-3 py-2.5" dir="ltr">{formatMoney(totals[band], currency)}</td>)}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>
        </>
    );
}

/** "Échéances": the open installments of customer payment schedules (an order without a schedule keeps its stamped due date). */
export function DuesSection({ forecast }: { forecast: Forecast | null }) {
    const { t } = useI18n();
    if (!forecast) return null;
    return (
        <>
            <TableShell label={t('fc.rec.s.dues')} state={forecast.installments.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc3.dues.empty')}>
                <table className="erp-table min-w-[820px]">
                    <thead><tr><th className="px-4 py-2.5">{t('common.customer')}</th><th className="px-4 py-2.5">{t('fc2.settle.order')}</th><th className="px-4 py-2.5">#</th><th className="px-4 py-2.5">{t('fc2.due')}</th><th className="num px-4 py-2.5">{t('common.amount')}</th><th className="num px-4 py-2.5">{t('fc2.settle.remaining')}</th></tr></thead>
                    <tbody>
                        {forecast.installments.map((row) => (
                            <tr key={`${row.order_id}-${row.sequence}`}>
                                <td className="px-4 py-2.5">{row.customer_id ? <Link href={`/finance/clients/comptes/${row.customer_id}?section=schedule`}>{row.customer}</Link> : row.customer}</td>
                                <td className="px-4 py-2.5 font-medium">{row.order_number}</td><td className="px-4 py-2.5">{row.sequence}</td>
                                <td className={`px-4 py-2.5 ${row.is_overdue ? 'text-danger' : 'text-ink-muted'}`}>{row.due_date}{row.is_overdue && ` · ${t('fc2.col.overdue')}`}</td>
                                <td className="num px-4 py-2.5" dir="ltr">{formatMoney(row.amount, row.currency_code)}</td><td className="num px-4 py-2.5 font-medium" dir="ltr">{formatMoney(row.outstanding, row.currency_code)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>
            <p className="mt-3 text-[13px] text-ink-muted">{t('fc3.dues.note')}</p>
        </>
    );
}

/** "Prévisions": expected collections per window; each order's balance is counted once (installments, else promises, else due date). */
export function ForecastSection({ forecast, monthEnd }: { forecast: Forecast | null; monthEnd: boolean }) {
    const { t } = useI18n();
    if (!forecast) return null;
    const currencies = Object.keys(forecast.by_currency);
    return (
        <>
            {forecast.truncated && <div className="mb-3"><Alert tone="warning">{t('fc2.accounts.tooMany')}</Alert></div>}
            {currencies.length === 0 && <Alert tone="info">{t('fc3.forecast.empty')}</Alert>}
            {currencies.map((currency) => (
                <div key={currency} className="mb-5">
                    <h3 className="mb-2 text-sm font-semibold text-ink">{currency}</h3>
                    <div className="grid gap-3 sm:grid-cols-4 xl:grid-cols-7">
                        {BUCKETS.map((bucket) => <MetricCard key={bucket} level="secondary" label={t(`fc3.bucket.${bucket}` as TranslationKey)} value={formatMoney(forecast.by_currency[currency]?.[bucket] ?? '0', currency)} tone={bucket === 'overdue' ? 'negative' : 'neutral'} />)}
                    </div>
                </div>
            ))}
            <p className="mb-3 text-[13px] text-ink-muted">{t('fc3.forecast.note')} {monthEnd && <ButtonLink href="/finance/situation-fin-de-mois" size="sm" variant="outline">{t('fc3.forecast.monthEnd')}</ButtonLink>}</p>
            <TableShell label={t('fc.inst.s.portfolio')} state={forecast.instruments.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc3.forecast.noInstruments')}>
                <table className="erp-table min-w-[720px]">
                    <thead><tr><th className="px-4 py-2.5">{t('common.customer')}</th><th className="px-4 py-2.5">{t('fc2.col.type')}</th><th className="px-4 py-2.5">N°</th><th className="px-4 py-2.5">{t('fc3.inst.expected')}</th><th className="num px-4 py-2.5">{t('common.amount')}</th></tr></thead>
                    <tbody>{forecast.instruments.map((i) => (
                        <tr key={i.id}><td className="px-4 py-2.5">{i.customer}</td><td className="px-4 py-2.5">{i.type === 'cheque' ? t('fc2.inst.cheque') : t('fc2.inst.effet')}</td><td className="px-4 py-2.5 font-medium">{i.number}</td>
                            <td className="px-4 py-2.5 text-ink-muted">{i.expected_date ?? i.maturity_date ?? '—'}</td><td className="num px-4 py-2.5" dir="ltr">{formatMoney(i.amount, i.currency_code)}</td></tr>
                    ))}</tbody>
                </table>
            </TableShell>
        </>
    );
}

/** "Relances": open follow-ups of the existing follow-up system + open promises. Read-only; nothing is sent automatically. */
export function RemindersSection({ reminders }: { reminders: Reminders | null }) {
    const { t } = useI18n();
    if (!reminders) return null;
    return (
        <div className="space-y-6">
            <Alert tone="info">{t('fc3.reminders.note')} <Link href="/work" className="underline">{t('nav.myWork')}</Link></Alert>
            <TableShell label={t('fc3.reminders.followUps')} state={reminders.followUps.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc3.reminders.noFollowUps')}>
                <table className="erp-table min-w-[620px]"><thead><tr><th className="px-4 py-2.5">{t('common.customer')}</th><th className="px-4 py-2.5">{t('fc3.reminders.title')}</th><th className="px-4 py-2.5">{t('fc2.due')}</th></tr></thead>
                    <tbody>{reminders.followUps.map((f) => <tr key={f.id}><td className="px-4 py-2.5"><Link href={`/finance/clients/comptes/${f.customer_id}?section=schedule`}>{f.customer}</Link></td><td className="px-4 py-2.5">{f.title}</td><td className="px-4 py-2.5 text-ink-muted">{f.due_date ?? '—'}</td></tr>)}</tbody></table>
            </TableShell>
            <TableShell label={t('fc3.reminders.promises')} state={reminders.promises.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc3.reminders.noPromises')}>
                <table className="erp-table min-w-[620px]"><thead><tr><th className="px-4 py-2.5">{t('common.customer')}</th><th className="px-4 py-2.5">{t('fc3.promise.date')}</th><th className="num px-4 py-2.5">{t('common.amount')}</th><th className="px-4 py-2.5">{t('fc2.settle.notes')}</th></tr></thead>
                    <tbody>{reminders.promises.map((p) => <tr key={p.id}><td className="px-4 py-2.5"><Link href={`/finance/clients/comptes/${p.customer_id}?section=schedule`}>{p.customer}</Link></td><td className="px-4 py-2.5">{p.promised_date.slice(0, 10)}</td>
                        <td className="num px-4 py-2.5" dir="ltr">{formatMoney(p.amount, p.currency_code)}</td><td className="px-4 py-2.5 text-ink-muted">{p.note ?? '—'}</td></tr>)}</tbody></table>
            </TableShell>
        </div>
    );
}
