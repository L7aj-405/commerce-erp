import { PageNav } from '@/components/finance/PageNav';
import { Alert } from '@/components/ui/Alert';
import { Button, ButtonLink, DownloadLink } from '@/components/ui/Button';
import { MetricCard } from '@/components/ui/MetricCard';
import { TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import { formatMoney } from '@/utils/format';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

export type Aging = {
    as_of: string;
    rows: { customer_id: number; customer: string; currency_code: string | null; mixed_currency: boolean; receivable: string | null; unbilled: string | null; total: string | null; overdue: string | null; bands: Record<string, string> | null }[];
    totals: Record<string, Record<string, string>>;
    truncated: boolean;
    total_rows: number; page: number; pages: number; per_page: number;
};
export type Forecast = {
    today: string;
    by_currency: Record<string, Record<string, string>>;
    installments: { customer_id: number | null; customer: string; order_id: number; order_number: string; sequence: number; due_date: string; amount: string; outstanding: string; currency_code: string; is_overdue: boolean }[];
    instruments: { id: number; customer: string; type: string; number: string; amount: string; currency_code: string; expected_date: string | null; maturity_date: string | null; state: string }[];
    truncated: boolean;
};
export type ReminderTemplates = Record<string, { label: { fr: string; ar: string }; fr: string; ar: string; customized: boolean }>;
export type Reminders = {
    templates: ReminderTemplates;
    overdueCustomers: { customer_id: number; customer: string; overdue: string; currency_code: string }[];
    canManageTemplates: boolean;
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
                <PageNav page={aging.page} pages={aging.pages} total={aging.total_rows} />
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
/** Manual reminder: pick an overdue customer, a template and a language; preview the rendered text, copy it or record a follow-up. Nothing is sent. */
function ReminderComposer({ reminders }: { reminders: Reminders }) {
    const { t } = useI18n();
    const [customerId, setCustomerId] = useState<string>(reminders.overdueCustomers[0] ? String(reminders.overdueCustomers[0].customer_id) : '');
    const [template, setTemplate] = useState<string>(Object.keys(reminders.templates)[0] ?? 'courtesy');
    const [language, setLanguage] = useState<'fr' | 'ar'>('fr');
    const [message, setMessage] = useState('');
    const [edit, setEdit] = useState<{ fr: string; ar: string } | null>(null);
    const preview = async () => {
        if (!customerId) return;
        const query = new URLSearchParams({ customer_id: customerId, template, language }).toString();
        const response = await fetch(`/finance/clients/creances/relances/apercu?${query}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (response.ok) setMessage(((await response.json()) as { message: string }).message);
    };
    const record = () => router.post('/finance/clients/creances/relances/suivi', { customer_id: customerId, template, language }, { preserveScroll: true });
    const save = () => edit && router.post('/finance/clients/creances/relances/modele', { template, fr: edit.fr, ar: edit.ar }, { preserveScroll: true, onSuccess: () => setEdit(null) });
    return (
        <section aria-label={t('fc6.reminder.title')} className="surface-card space-y-3 p-4">
            <h3 className="text-sm font-semibold text-ink">{t('fc6.reminder.title')}</h3>
            <p className="text-[13px] text-ink-muted">{t('fc6.reminder.help')}</p>
            {reminders.overdueCustomers.length === 0 ? <p className="text-sm text-ink-muted">{t('fc6.reminder.none')}</p> : (
                <div className="flex flex-wrap items-end gap-3">
                    <label className="text-sm">{t('common.customer')}
                        <select className="control-select mt-1 block h-10" value={customerId} onChange={(e) => { setCustomerId(e.target.value); setMessage(''); }}>
                            {reminders.overdueCustomers.map((c) => <option key={c.customer_id} value={c.customer_id}>{c.customer} — {formatMoney(c.overdue, c.currency_code)}</option>)}
                        </select>
                    </label>
                    <label className="text-sm">{t('fc6.reminder.template')}
                        <select className="control-select mt-1 block h-10" value={template} onChange={(e) => { setTemplate(e.target.value); setMessage(''); setEdit(null); }}>
                            {Object.entries(reminders.templates).map(([key, tpl]) => <option key={key} value={key}>{tpl.label[language]}</option>)}
                        </select>
                    </label>
                    <label className="text-sm">{t('fc6.reminder.language')}
                        <select className="control-select mt-1 block h-10" value={language} onChange={(e) => { setLanguage(e.target.value as 'fr' | 'ar'); setMessage(''); }}>
                            <option value="fr">Français</option><option value="ar">العربية</option>
                        </select>
                    </label>
                    <Button variant="outline" onClick={preview}>{t('fc6.reminder.preview')}</Button>
                </div>
            )}
            {message && (
                <div className="space-y-2">
                    <textarea readOnly dir={language === 'ar' ? 'rtl' : 'ltr'} value={message} rows={5} className="control-input w-full p-3 text-sm" />
                    <div className="flex gap-2">
                        <Button variant="secondary" size="sm" onClick={() => void navigator.clipboard?.writeText(message)}>{t('fc6.reminder.copy')}</Button>
                        <Button variant="secondary" size="sm" onClick={record}>{t('fc6.reminder.followUp')}</Button>
                    </div>
                </div>
            )}
            {reminders.canManageTemplates && (
                <div className="border-t border-line pt-3">
                    {edit === null ? <Button variant="outline" size="sm" onClick={() => setEdit({ fr: reminders.templates[template]?.fr ?? '', ar: reminders.templates[template]?.ar ?? '' })}>{t('fc6.reminder.edit')}</Button> : (
                        <div className="space-y-2">
                            <p className="text-[13px] text-ink-muted">{t('fc6.reminder.placeholders')} {'{customer} {organization} {amount} {currency} {due_date} {oldest_days}'}</p>
                            <textarea value={edit.fr} onChange={(e) => setEdit({ ...edit, fr: e.target.value })} rows={4} dir="ltr" className="control-input w-full p-3 text-sm" aria-label="Français" />
                            <textarea value={edit.ar} onChange={(e) => setEdit({ ...edit, ar: e.target.value })} rows={4} dir="rtl" className="control-input w-full p-3 text-sm" aria-label="العربية" />
                            <div className="flex gap-2"><Button size="sm" onClick={save}>{t('common.save')}</Button><Button variant="outline" size="sm" onClick={() => setEdit(null)}>{t('common.cancel')}</Button></div>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

export function RemindersSection({ reminders }: { reminders: Reminders | null }) {
    const { t } = useI18n();
    if (!reminders) return null;
    return (
        <div className="space-y-6">
            <Alert tone="info">{t('fc3.reminders.note')} <Link href="/work" className="underline">{t('nav.myWork')}</Link></Alert>
            <ReminderComposer reminders={reminders} />
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
