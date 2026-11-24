import { SectionTabs, UNAVAILABLE, useSection } from '@/components/finance/FinanceClientsKit';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import { SelectField, TextAreaField, TextField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import { TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

type Row = {
    customer_id: number; customer: string; credit_enabled: boolean; credit_limit: string | null; payment_term_days: number | null; policy: string; financial_status: string;
    currency_code: string | null; exposure: string; overdue: string; available: string | null; mixed_currency: boolean;
};
type Exception = { id: number; customer_id: number; display_name: string; authorized_amount: string; reason: string; expires_on: string | null; consumed_at: string | null; sales_order_id: number | null };
type Props = {
    organization: { id: number; name: string }; enforcement: 'off' | 'warn' | 'block'; rows: Row[]; exceptions: Exception[]; customers: { id: number; display_name: string }[];
    can: { policy: boolean; override: boolean }; truncated: boolean;
};

const SECTIONS = ['limits', 'exposure', 'terms', 'overruns', 'blocked', 'authorizations'] as const;
const LABEL: Record<(typeof SECTIONS)[number], TranslationKey> = {
    limits: 'fc.credit.s.limits', exposure: 'fc.credit.s.exposure', terms: 'fc.credit.s.terms', overruns: 'fc.credit.s.overruns', blocked: 'fc.credit.s.blocked', authorizations: 'fc.credit.s.authorizations',
};

/** FC-06 — credit control: policy, limits, exposure, terms, overruns, blocked customers and authorized exceptions (all real, audited). */
export default function FinanceClientsCredit({ organization, enforcement, rows, exceptions, customers, can, truncated }: Props) {
    const { t } = useI18n();
    const section = useSection(SECTIONS, 'limits');
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors ?? {};
    const [mode, setMode] = useState(enforcement);
    const [form, setForm] = useState({ customer_id: '', authorized_amount: '', reason: '', expires_on: '' });
    const money = (v: string | null, c: string | null) => (v === null ? UNAVAILABLE : formatMoney(v, c ?? 'MAD'));
    const list = section === 'overruns' ? rows.filter((r) => r.available !== null && Number(r.available) < 0) : section === 'blocked' ? rows.filter((r) => r.financial_status === 'blocked')
        : section === 'terms' ? rows.filter((r) => r.payment_term_days !== null) : section === 'limits' ? rows.filter((r) => r.credit_enabled) : rows;
    const savePolicy = (e: FormEvent) => { e.preventDefault(); router.post('/finance/clients/credit/politique', { enforcement: mode }, { preserveScroll: true }); };
    const grant = (e: FormEvent) => { e.preventDefault(); router.post('/finance/clients/credit/exceptions', form, { preserveScroll: true, onSuccess: () => setForm({ customer_id: '', authorized_amount: '', reason: '', expires_on: '' }) }); };

    return (
        <ApplicationShell wide>
            <Head title={`${t('fc.credit.title')} — Finance`} />
            <PageHeader title={t('fc.credit.title')} description={`${organization.name} — ${t('fc.credit.description')}`} />
            <form onSubmit={savePolicy} className="surface-card mb-4 flex flex-wrap items-end gap-3 p-4" aria-label={t('fc4.policy.title')}>
                <SelectField label={t('fc4.policy.title')} value={mode} onChange={(e) => setMode(e.target.value as Props['enforcement'])} disabled={!can.policy} className="min-w-64">
                    <option value="off">{t('fc4.policy.off')}</option><option value="warn">{t('fc4.policy.warn')}</option><option value="block">{t('fc4.policy.block')}</option>
                </SelectField>
                {can.policy && <Button type="submit" variant="outline">{t('common.save')}</Button>}
                <p className="basis-full text-[13px] text-ink-muted">{t('fc4.policy.help')}</p>
            </form>
            {truncated && <div className="mb-4"><Alert tone="warning">{t('fc2.accounts.tooMany')}</Alert></div>}
            <SectionTabs sections={SECTIONS.map((key) => ({ key, label: t(LABEL[key]) }))} active={section} label={t('fc.credit.title')} />

            {section !== 'authorizations' && (
                <TableShell label={t(LABEL[section])} state={list.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc4.credit.empty')}>
                    <table className="erp-table min-w-[900px] text-sm">
                        <thead><tr>
                            <th className="px-4 py-2.5">{t('common.customer')}</th><th className="num px-4 py-2.5">{t('fc2.summary.limit')}</th><th className="num px-4 py-2.5">{t('fc2.summary.exposure')}</th>
                            <th className="num px-4 py-2.5">{t('fc2.col.available')}</th><th className="num px-4 py-2.5">{t('fc2.col.overdue')}</th><th className="px-4 py-2.5">{t('fc2.terms.termDays')}</th>
                            <th className="px-4 py-2.5">{t('fc2.terms.policy')}</th><th className="px-4 py-2.5">{t('fc2.col.financialStatus')}</th>
                        </tr></thead>
                        <tbody>
                            {list.map((r) => (
                                <tr key={r.customer_id}>
                                    <td className="px-4 py-2.5 font-medium"><Link href={`/finance/clients/comptes/${r.customer_id}?section=terms`}>{r.customer}</Link></td>
                                    <td className="num px-4 py-2.5" dir="ltr">{r.credit_enabled ? money(r.credit_limit, r.currency_code) : UNAVAILABLE}</td>
                                    <td className="num px-4 py-2.5" dir="ltr">{r.mixed_currency ? UNAVAILABLE : money(r.exposure, r.currency_code)}</td>
                                    <td className={`num px-4 py-2.5 ${r.available !== null && Number(r.available) < 0 ? 'text-danger' : ''}`} dir="ltr">{money(r.available, r.currency_code)}</td>
                                    <td className="num px-4 py-2.5" dir="ltr">{r.mixed_currency ? UNAVAILABLE : money(r.overdue, r.currency_code)}</td>
                                    <td className="px-4 py-2.5">{r.payment_term_days ?? UNAVAILABLE}</td>
                                    <td className="px-4 py-2.5">{t(r.policy === 'block' ? 'fc2.terms.policy.block' : 'fc2.terms.policy.warn')}</td>
                                    <td className="px-4 py-2.5"><DocBadge tone={r.financial_status === 'blocked' ? 'danger' : r.financial_status === 'watch' ? 'warning' : 'positive'}>{t(`fc2.status.${r.financial_status}` as TranslationKey)}</DocBadge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableShell>
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
                    <TableShell label={t('fc.credit.s.authorizations')} state={exceptions.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc4.exception.empty')}>
                        <table className="erp-table min-w-[720px] text-sm">
                            <thead><tr><th className="px-4 py-2.5">{t('common.customer')}</th><th className="num px-4 py-2.5">{t('fc4.exception.amount')}</th><th className="px-4 py-2.5">{t('fc2.opening.reason')}</th><th className="px-4 py-2.5">{t('fc4.exception.expires')}</th><th className="px-4 py-2.5">{t('common.status')}</th></tr></thead>
                            <tbody>{exceptions.map((x) => (
                                <tr key={x.id}><td className="px-4 py-2.5 font-medium">{x.display_name}</td><td className="num px-4 py-2.5" dir="ltr">{formatMoney(x.authorized_amount)}</td><td className="px-4 py-2.5 text-ink-muted">{x.reason}</td>
                                    <td className="px-4 py-2.5">{x.expires_on ?? UNAVAILABLE}</td><td className="px-4 py-2.5">{x.consumed_at ? t('fc4.exception.used') : t('fc4.exception.open')}</td></tr>
                            ))}</tbody>
                        </table>
                    </TableShell>
                </>
            )}
        </ApplicationShell>
    );
}
