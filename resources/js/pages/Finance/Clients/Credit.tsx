import { ParentTabs, SectionTabs, UNAVAILABLE, useSection } from '@/components/finance/FinanceClientsKit';
import { Alert } from '@/components/ui/Alert';
import { Button, ButtonLink } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import { SelectField, TextAreaField, TextField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import { PageNav } from '@/components/finance/PageNav';
import { TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatBusinessDate, formatMoney } from '@/utils/format';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

type Row = {
    customer_id: number; customer: string; credit_enabled: boolean; credit_limit: string | null; payment_term_days: number | null; policy: string; financial_status: string;
    currency_code: string | null; exposure: string; overdue: string; available: string | null; mixed_currency: boolean;
};
type Exception = { id: number; customer_id: number; display_name: string; authorized_amount: string; reason: string; expires_on: string | null; consumed_at: string | null; sales_order_id: number | null };
type Props = {
    organization: { id: number; name: string }; enforcement: 'off' | 'warn' | 'block'; deductAdvances: boolean; rows: Row[]; filter: Filter; counts: Record<Filter, number>; meta: { total: number; page: number; pages: number }; exceptions: Exception[]; customers: { id: number; display_name: string }[];
    businessDate: string; can: { policy: boolean; override: boolean }; truncated: boolean;
};

/** One register + filters (the former population tabs are filters now); the exceptions workflow keeps its own tab. */
const FILTERS = ['all', 'limits', 'exposure', 'overruns', 'blocked', 'watch', 'overdue'] as const;
type Filter = (typeof FILTERS)[number];
const FILTER_LABEL: Record<Filter, TranslationKey> = {
    all: 'p3.credit.f.all', limits: 'p3.credit.f.limits', exposure: 'p3.credit.f.exposure', overruns: 'p3.credit.f.overruns', blocked: 'p3.credit.f.blocked', watch: 'p3.credit.f.watch', overdue: 'p3.credit.f.overdue',
};
/** Former `?section=` population values are mapped to their filter server-side, so existing bookmarks keep working. */
const SECTIONS = ['register', 'authorizations', 'limits', 'exposure', 'overruns', 'blocked', 'terms'] as const;

type AlertDef = { key: TranslationKey; tone: 'danger' | 'warning' | 'neutral' };

export default function FinanceClientsCredit({ organization, enforcement, rows, filter, counts, meta, exceptions, customers, businessDate, can }: Props) {
    const { t } = useI18n();
    const requested = useSection(SECTIONS, 'register');
    const section = requested === 'authorizations' ? 'authorizations' : 'register';
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors ?? {};
    const [form, setForm] = useState({ customer_id: '', authorized_amount: '', reason: '', expires_on: '' });
    const money = (v: string | null, c: string | null) => (v === null ? UNAVAILABLE : formatMoney(v, c ?? 'MAD'));
    const list = rows;
    const alerts = (r: Row): AlertDef[] => [
        ...(r.available !== null && Number(r.available) < 0 ? [{ key: 'p3.credit.alert.overLimit', tone: 'danger' } as AlertDef] : []),
        ...(r.financial_status === 'blocked' ? [{ key: 'p3.credit.alert.blocked', tone: 'danger' } as AlertDef] : []),
        ...(r.financial_status === 'watch' ? [{ key: 'p3.credit.alert.watch', tone: 'warning' } as AlertDef] : []),
        ...(!r.mixed_currency && Number(r.overdue) > 0 ? [{ key: 'p3.credit.alert.overdue', tone: 'warning' } as AlertDef] : []),
        ...(r.mixed_currency ? [{ key: 'p3.credit.alert.mixed', tone: 'neutral' } as AlertDef] : []),
    ];
    const grant = (e: FormEvent) => {
        e.preventDefault();
        router.post('/finance/clients/credit/exceptions', form, { preserveScroll: true, onSuccess: () => setForm({ customer_id: '', authorized_amount: '', reason: '', expires_on: '' }) });
    };
    const modeLabel = t(enforcement === 'off' ? 'fc4.policy.off' : enforcement === 'warn' ? 'fc4.policy.warn' : 'fc4.policy.block');
    const exceptionStatus = (x: Exception) => (x.consumed_at ? t('p3.credit.consumed') : x.expires_on && x.expires_on.slice(0, 10) < businessDate ? t('p3.credit.expired') : t('fc4.exception.open'));

    return (
        <ApplicationShell wide>
            <Head title={`${t('fc.credit.title')} — Finance`} />
            <PageHeader
                title={t('fc.credit.title')}
                description={`${organization.name} — ${t('fc.credit.description')}`}
                actions={can.policy ? <ButtonLink href="/customer-credit-settings" variant="outline" size="sm">{t('p3.credit.policyLink')}</ButtonLink> : undefined}
            />
            <ParentTabs group="receivables" active="credit" />
            <p className="mb-3 text-[13px] text-ink-muted">{t('p3.credit.policyNow')} <strong className="text-ink">{modeLabel}</strong> · {t('p3.credit.policyHelp')}</p>
            <SectionTabs sections={[{ key: 'register', label: t('p3.credit.register') }, { key: 'authorizations', label: t('p3.credit.exceptions') }]} active={section} label={t('fc.credit.title')} />

            {section === 'register' && (
                <>
                    <div className="mb-3 flex flex-wrap items-end gap-3">
                        <label className="flex flex-col gap-1 text-[13px] text-ink-muted">{t('p3.credit.filter')}
                            <select className="control-select h-10" value={filter} onChange={(e) => router.get('/finance/clients/credit', { filter: e.target.value }, { preserveScroll: true })}>
                                {FILTERS.map((f) => <option key={f} value={f}>{t(FILTER_LABEL[f])} ({counts[f]})</option>)}
                            </select>
                        </label>
                    </div>
                    <TableShell label={t('p3.credit.register')} state={list.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc4.credit.empty')}>
                        <table className="erp-table min-w-[1000px] text-sm">
                            <thead><tr>
                                <th className="px-4 py-2.5">{t('common.customer')}</th><th className="px-4 py-2.5">{t('fc2.col.financialStatus')}</th><th className="num px-4 py-2.5">{t('fc2.summary.limit')}</th><th className="num px-4 py-2.5">{t('fc2.summary.exposure')}</th>
                                <th className="num px-4 py-2.5">{t('fc2.col.available')}</th><th className="num px-4 py-2.5">{t('fc2.col.overdue')}</th><th className="px-4 py-2.5">{t('fc2.terms.policy')}</th>
                                <th className="px-4 py-2.5">{t('p3.credit.alerts')}</th><th className="px-4 py-2.5"><span className="sr-only">{t('common.actions')}</span></th>
                            </tr></thead>
                            <tbody>
                                {list.map((r) => (
                                    <tr key={r.customer_id}>
                                        <td className="px-4 py-2.5 font-medium">{r.customer}</td>
                                        <td className="px-4 py-2.5"><DocBadge tone={r.financial_status === 'blocked' ? 'danger' : r.financial_status === 'watch' ? 'warning' : 'positive'}>{t(`fc2.status.${r.financial_status}` as TranslationKey)}</DocBadge></td>
                                        <td className="num px-4 py-2.5" dir="ltr">{r.credit_enabled ? money(r.credit_limit, r.currency_code) : UNAVAILABLE}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{r.mixed_currency ? UNAVAILABLE : money(r.exposure, r.currency_code)}</td>
                                        <td className={`num px-4 py-2.5 ${r.available !== null && Number(r.available) < 0 ? 'text-danger' : ''}`} dir="ltr">{money(r.available, r.currency_code)}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{r.mixed_currency ? UNAVAILABLE : money(r.overdue, r.currency_code)}</td>
                                        <td className="px-4 py-2.5">{t(r.policy === 'block' ? 'fc2.terms.policy.block' : 'fc2.terms.policy.warn')}</td>
                                        <td className="px-4 py-2.5"><div className="flex flex-wrap gap-1">{alerts(r).map((a) => <DocBadge key={a.key} tone={a.tone}>{t(a.key)}</DocBadge>)}</div></td>
                                        <td className="px-4 py-2.5 text-end"><Link className="text-[13px] underline" href={`/finance/clients/comptes/${r.customer_id}?section=terms`}>{t('p3.credit.conditions')}</Link></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <PageNav page={meta.page} pages={meta.pages} total={meta.total} />
                    </TableShell>
                </>
            )}

            {section === 'authorizations' && (
                <>
                    {can.override ? (
                        <form onSubmit={grant} className="surface-card mb-4 p-4" aria-label={t('fc4.exception.title')}>
                            <h3 className="mb-3 text-base font-semibold text-ink">{t('fc4.exception.title')}</h3>
                            <div className="grid gap-3 md:grid-cols-4">
                                <SelectField label={t('common.customer')} value={form.customer_id} onChange={(e) => setForm({ ...form, customer_id: e.target.value })} error={errors.customer_id} required>
                                    <option value="">—</option>{customers.map((c) => <option key={c.id} value={c.id}>{c.display_name}</option>)}
                                </SelectField>
                                <TextField label={t('fc4.exception.amount')} inputMode="decimal" value={form.authorized_amount} onChange={(e) => setForm({ ...form, authorized_amount: e.target.value })} error={errors.authorized_amount} required />
                                <TextField type="date" label={t('fc4.exception.expires')} value={form.expires_on} onChange={(e) => setForm({ ...form, expires_on: e.target.value })} error={errors.expires_on} />
                                <TextAreaField label={t('fc2.opening.reason')} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} error={errors.reason} rows={2} className="md:col-span-4" required />
                            </div>
                            <p className="mt-2 text-[13px] text-ink-muted">{t('fc4.exception.help')}</p>
                            <div className="mt-3"><Button type="submit">{t('fc4.exception.submit')}</Button></div>
                        </form>
                    ) : <div className="mb-4"><Alert tone="info">{t('fc4.exception.noRight')}</Alert></div>}
                    <TableShell label={t('p3.credit.exceptions')} state={exceptions.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc4.exception.empty')}>
                        <table className="erp-table min-w-[720px] text-sm">
                            <thead><tr><th className="px-4 py-2.5">{t('common.customer')}</th><th className="num px-4 py-2.5">{t('fc4.exception.amount')}</th><th className="px-4 py-2.5">{t('fc2.opening.reason')}</th><th className="px-4 py-2.5">{t('fc4.exception.expires')}</th><th className="px-4 py-2.5">{t('common.status')}</th></tr></thead>
                            <tbody>{exceptions.map((x) => (
                                <tr key={x.id}>
                                    <td className="px-4 py-2.5 font-medium">{x.display_name}</td>
                                    <td className="num px-4 py-2.5" dir="ltr">{formatMoney(x.authorized_amount)}</td>
                                    <td className="px-4 py-2.5 text-ink-muted">{x.reason}</td>
                                    <td className="px-4 py-2.5">{formatBusinessDate(x.expires_on, UNAVAILABLE)}</td>
                                    <td className="px-4 py-2.5">
                                        {exceptionStatus(x)}
                                        {x.consumed_at && x.sales_order_id && <> · {t('p3.credit.consumedOn')} <Link className="underline" href={`/sales/orders/${x.sales_order_id}`}>#{x.sales_order_id}</Link> · {formatBusinessDate(x.consumed_at)}</>}
                                    </td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </TableShell>
                </>
            )}
        </ApplicationShell>
    );
}
