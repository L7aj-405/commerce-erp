import { AdvanceAllocator, OpeningBalanceForm, SettlementForm, TermsForm } from '@/components/finance/CustomerAccountForms';
import type { FinAccount, OpenOrder } from '@/components/finance/CustomerAccountForms';
import { InstallmentsTable, PromisesPanel, ScheduleEditor } from '@/components/finance/CustomerCollectionPlan';
import type { PlanInstallment, Promise_ } from '@/components/finance/CustomerCollectionPlan';
import { SectionTabs, UNAVAILABLE, useQueryParams, useSection } from '@/components/finance/FinanceClientsKit';
import { Alert } from '@/components/ui/Alert';
import { Button, ButtonLink, DownloadLink } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import { MetricCard } from '@/components/ui/MetricCard';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { invoiceStatusLabel, label, paymentEntryStatusLabel, paymentMethodLabel } from '@/utils/labels';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Position = {
    currency_code: string | null; mixed_currency: boolean;
    invoiced_gross: string | null; credited: string | null; net_invoiced: string | null; paid: string | null;
    receivable: string | null; overdue: string | null; overdue_invoices: string | null; overdue_unbilled: string | null; unbilled: string | null; refund_obligation: string | null;
    opening_balance: string | null; advances_available: string | null; pending_instruments: string | null;
    credit_enabled: boolean; credit_limit: string | null; credit_exposure: string | null; available_credit: string | null; payment_term_days: number | null;
    financial_status: string;
    bands: Record<string, string | null> | null; unbilled_bands: Record<string, string | null> | null;
};
type InvoiceRow = { id: number; invoice_number: string; version: number; invoice_date: string; due_date: string | null; status: string; subtotal_excl_tax: string; tax_total: string; total_incl_tax: string };
type PaymentRow = { id: number; payment_number: string; payment_date: string; method: string; status: string; amount: string; customer_id: number | null; allocated: string; advance_left: string };
type StatementRow = { date: string; type: string; reference: string; description: string; debit: string; credit: string; balance: string; doc_type: string | null; doc_id: number | null };
type Statement = { currency_code: string | null; opening: string; closing: string; total_debit: string; total_credit: string; rows: StatementRow[]; truncated: boolean; mixed_currency: boolean } | null;
type Props = {
    customer: { id: number; display_name: string; company_name: string | null; email: string | null; phone: string | null; status: string; type: string };
    position: Position;
    can: { commercialProfile: boolean; openingBalance: boolean; terms: boolean; settlements: boolean; creditOverride: boolean; export: boolean; schedules?: boolean };
    forecast?: { by_currency: Record<string, Record<string, string>>; installments: PlanInstallment[]; instruments: { id: number; type: string; number: string; amount: string; currency_code: string; expected_date: string | null; maturity_date: string | null; state: string }[] };
    promises?: Promise_[];
    invoices?: InvoiceRow[]; invoicesCount?: number;
    payments?: PaymentRow[]; paymentsCount?: number; openOrders?: OpenOrder[]; accounts?: FinAccount[];
    statement?: Statement; period?: { from: string; to: string };
    terms?: Record<string, string | number | boolean | null> | null;
    openings?: { id: number; effective_date: string; amount: string; currency_code: string; reference: string; reason: string; status: string }[];
    exceptions?: { id: number; authorized_amount: string; reason: string; consumed_at: string | null; expires_on: string | null }[];
    enforcement?: string;
};

const SECTIONS = ['summary', 'invoices', 'payments', 'schedule', 'statement', 'terms'] as const;
type Section = (typeof SECTIONS)[number];
const SECTION_LABEL: Record<Section, TranslationKey> = {
    summary: 'fc.acct.tab.summary', invoices: 'fc.acct.tab.invoices', payments: 'fc.acct.tab.payments',
    schedule: 'fc.acct.tab.schedule', statement: 'fc.acct.tab.statement', terms: 'fc.acct.tab.terms',
};
const STATUS_TONE: Record<string, 'positive' | 'neutral' | 'warning' | 'danger'> = { normal: 'positive', watch: 'warning', overdue: 'warning', over_limit: 'danger', blocked: 'danger' };

/** FC-02 — customer account: six tabs, all figures from the shared viewer-scoped position service. */
export default function FinanceClientsAccountShow(props: Props) {
    const { t } = useI18n();
    const { customer, position, can } = props;
    const section = useSection(SECTIONS, 'summary');
    const cur = position.currency_code ?? 'MAD';
    const money = (value: string | null | undefined) => (value === null || value === undefined ? UNAVAILABLE : formatMoney(value, cur));
    const flash = usePage<{ errors?: Record<string, string> }>().props.errors ?? {};

    return (
        <ApplicationShell wide>
            <Head title={`${customer.display_name} — ${t('fc.accounts.title')}`} />
            <PageHeader
                title={customer.display_name}
                description={[customer.company_name && customer.company_name !== customer.display_name ? customer.company_name : null, customer.phone ?? customer.email].filter(Boolean).join(' · ')}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <DocBadge tone={STATUS_TONE[position.financial_status] ?? 'neutral'}>{t(`fc2.status.${position.financial_status}` as TranslationKey)}</DocBadge>
                        <ButtonLink href="/finance/clients/comptes" variant="secondary" size="sm">{t('fc.accounts.title')}</ButtonLink>
                        {can.commercialProfile && <ButtonLink href={`/sales/customers/${customer.id}`} variant="outline" size="sm">{t('fc.acct.commercialProfile')}</ButtonLink>}
                    </div>
                }
            />
            {position.mixed_currency && <div className="mb-4"><Alert tone="warning">{t('fc2.mixedCurrency')}</Alert></div>}
            {flash.reason && <div className="mb-4"><Alert tone="danger">{flash.reason}</Alert></div>}
            <SectionTabs sections={SECTIONS.map((key) => ({ key, label: t(SECTION_LABEL[key]) }))} active={section} label={t('fc.accounts.title')} />

            {section === 'summary' && <Summary position={position} money={money} />}
            {section === 'invoices' && <Invoices rows={props.invoices ?? []} count={props.invoicesCount ?? 0} currency={cur} />}
            {section === 'payments' && <Payments {...props} currency={cur} />}
            {section === 'schedule' && <Schedule customerId={customer.id} orders={props.openOrders ?? []} position={position} currency={cur} installments={props.forecast?.installments ?? []} promises={props.promises ?? []} canManage={Boolean(can.schedules)} />}
            {section === 'statement' && <StatementTab customerId={customer.id} statement={props.statement ?? null} period={props.period} canExport={can.export} />}
            {section === 'terms' && (
                <div className="space-y-4">
                    {can.terms ? <TermsForm customerId={customer.id} terms={props.terms ?? null} /> : <Alert tone="info">{t('fc2.readOnly')}</Alert>}
                    <p className="text-[13px] text-ink-muted">{t('fc2.terms.enforcement')} : <strong>{props.enforcement ?? 'off'}</strong></p>
                    {can.openingBalance && <OpeningBalanceForm customerId={customer.id} currency={cur} />}
                    <Openings customerId={customer.id} rows={props.openings ?? []} canManage={can.openingBalance} />
                </div>
            )}
        </ApplicationShell>
    );
}

function Summary({ position, money }: { position: Position; money: (v: string | null | undefined) => string }) {
    const { t } = useI18n();
    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard label={t('fc2.col.netInvoiced')} value={money(position.net_invoiced)} helper={t('fc2.help.netInvoiced')} unconfigured={position.net_invoiced === null} />
                <MetricCard label={t('fc.acct.m.paid')} value={money(position.paid)} helper={t('fc2.help.paid')} tone="positive" unconfigured={position.paid === null} />
                <MetricCard label={t('fc2.col.receivable')} value={money(position.receivable)} helper={t('fc.acct.m.outstanding.help')} tone="warning" unconfigured={position.receivable === null} />
                <MetricCard label={t('fc2.col.unbilled')} value={money(position.unbilled)} helper={t('fc2.help.unbilled')} unconfigured={position.unbilled === null} />
            </div>
            <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard level="secondary" label={t('fc2.col.overdue')} value={money(position.overdue)} tone="negative" unconfigured={position.overdue === null} />
                <MetricCard level="secondary" label={t('fc2.col.advances')} value={money(position.advances_available)} helper={t('fc2.help.advances')} unconfigured={position.advances_available === null} />
                <MetricCard level="secondary" label={t('fc2.col.refundObligation')} value={money(position.refund_obligation)} unconfigured={position.refund_obligation === null} />
                <MetricCard level="secondary" label={t('fc2.col.instruments')} value={money(position.pending_instruments)} helper={t('fc2.help.instruments')} unconfigured={position.pending_instruments === null} />
                <MetricCard level="secondary" label={t('fc2.summary.opening')} value={money(position.opening_balance)} unconfigured={position.opening_balance === null} />
                <MetricCard level="secondary" label={t('fc2.summary.exposure')} value={money(position.credit_exposure)} helper={t('fc2.help.exposure')} unconfigured={position.credit_exposure === null} />
                <MetricCard level="secondary" label={t('fc2.summary.limit')} value={position.credit_enabled ? money(position.credit_limit) : UNAVAILABLE} unconfigured={!position.credit_enabled} />
                <MetricCard level="secondary" label={t('fc2.col.available')} value={money(position.available_credit)} unconfigured={position.available_credit === null} />
            </div>
            <p className="mt-3 text-[13px] text-ink-muted">{t('fc.acct.scopeNote')}</p>
        </>
    );
}

function Invoices({ rows, count, currency }: { rows: InvoiceRow[]; count: number; currency: string }) {
    const { t } = useI18n();
    return (
        <TableShell label={t('fc.acct.tab.invoices')} state={rows.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc.acct.noInvoices')}>
            <table className="erp-table min-w-[820px]">
                <thead><tr>
                    <th className="px-4 py-2.5">{t('finance.billing.invoiceNumber')}</th><th className="px-4 py-2.5">{t('common.date')}</th><th className="px-4 py-2.5">{t('fc2.due')}</th>
                    <th className="px-4 py-2.5">{t('common.status')}</th><th className="num px-4 py-2.5">{t('fc2.ht')}</th><th className="num px-4 py-2.5">{t('fc2.tva')}</th><th className="num px-4 py-2.5">{t('fc.acct.ttc')}</th>
                </tr></thead>
                <tbody>
                    {rows.map((i) => (
                        <tr key={i.id}>
                            <td className="px-4 py-2.5 font-medium">
                                <Link href={`/documents/open/invoice/${i.id}`} method="post" as="button" className="text-start hover:underline">{i.invoice_number}{i.version > 1 && <span className="ms-1 text-ink-faint">v{i.version}</span>}</Link>
                            </td>
                            <td className="px-4 py-2.5 text-ink-muted">{i.invoice_date}</td>
                            <td className="px-4 py-2.5 text-ink-muted">{i.due_date ?? UNAVAILABLE}</td>
                            <td className="px-4 py-2.5"><StatusBadge status={i.status}>{label(invoiceStatusLabel, i.status)}</StatusBadge></td>
                            <td className="num px-4 py-2.5" dir="ltr">{formatMoney(i.subtotal_excl_tax, currency)}</td>
                            <td className="num px-4 py-2.5" dir="ltr">{formatMoney(i.tax_total, currency)}</td>
                            <td className="num px-4 py-2.5" dir="ltr">{formatMoney(i.total_incl_tax, currency)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {count > rows.length && <p className="px-4 py-3 text-[13px] text-ink-muted">{t('fc.acct.recentOnly')}</p>}
        </TableShell>
    );
}

function Payments(props: Props & { currency: string }) {
    const { t } = useI18n();
    const { customer, can, currency } = props;
    const rows = props.payments ?? [];
    const orders = props.openOrders ?? [];
    return (
        <>
            {can.settlements && (props.accounts?.length ?? 0) > 0 && <SettlementForm customerId={customer.id} accounts={props.accounts ?? []} orders={orders} />}
            {can.settlements && (props.accounts?.length ?? 0) === 0 && <div className="mb-4"><Alert tone="info">{t('fc2.settle.noAccount')}</Alert></div>}
            <TableShell label={t('fc.acct.tab.payments')} state={rows.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc.acct.noPayments')}>
                <table className="erp-table min-w-[820px]">
                    <thead><tr>
                        <th className="px-4 py-2.5">{t('fc.acct.paymentNumber')}</th><th className="px-4 py-2.5">{t('common.date')}</th><th className="px-4 py-2.5">{t('fc.acct.method')}</th>
                        <th className="px-4 py-2.5">{t('common.status')}</th><th className="num px-4 py-2.5">{t('common.amount')}</th><th className="num px-4 py-2.5">{t('fc2.settle.allocated')}</th><th className="px-4 py-2.5">{t('fc2.advance')}</th>
                    </tr></thead>
                    <tbody>
                        {rows.map((p) => (
                            <tr key={p.id}>
                                <td className="px-4 py-2.5 font-medium"><Link href={`/documents/open/payment/${p.id}`} method="post" as="button" className="text-start hover:underline">{p.payment_number}</Link></td>
                                <td className="px-4 py-2.5 text-ink-muted">{p.payment_date}</td>
                                <td className="px-4 py-2.5 text-ink-muted">{label(paymentMethodLabel, p.method)}</td>
                                <td className="px-4 py-2.5"><StatusBadge status={p.status}>{label(paymentEntryStatusLabel, p.status)}</StatusBadge></td>
                                <td className="num px-4 py-2.5" dir="ltr">{formatMoney(p.amount, currency)}</td>
                                <td className="num px-4 py-2.5" dir="ltr">{formatMoney(p.allocated, currency)}</td>
                                <td className="px-4 py-2.5">
                                    {p.customer_id && p.status === 'posted' && Number(p.advance_left) > 0 ? (
                                        can.settlements ? <AdvanceAllocator customerId={customer.id} paymentId={p.id} left={p.advance_left} orders={orders} currency={currency} /> : formatMoney(p.advance_left, currency)
                                    ) : UNAVAILABLE}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {(props.paymentsCount ?? 0) > rows.length && <p className="px-4 py-3 text-[13px] text-ink-muted">{t('fc.acct.recentOnly')}</p>}
            </TableShell>
        </>
    );
}

function Schedule({ customerId, orders, position, currency, installments, promises, canManage }: { customerId: number; orders: OpenOrder[]; position: Position; currency: string; installments: PlanInstallment[]; promises: Promise_[]; canManage: boolean }) {
    const { t } = useI18n();
    return (
        <>
            <TableShell label={t('fc.acct.tab.schedule')} state={orders.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc2.schedule.empty')}>
                <table className="erp-table min-w-[560px]">
                    <thead><tr><th className="px-4 py-2.5">{t('fc2.settle.order')}</th><th className="px-4 py-2.5">{t('common.date')}</th><th className="px-4 py-2.5">{t('fc2.due')}</th><th className="num px-4 py-2.5">{t('fc2.settle.remaining')}</th><th /></tr></thead>
                    <tbody>
                        {orders.map((o) => (
                            <tr key={o.id}>
                                <td className="px-4 py-2.5 font-medium"><Link href={`/documents/open/sales_order/${o.id}`} method="post" as="button" className="text-start hover:underline">{o.order_number}</Link></td>
                                <td className="px-4 py-2.5 text-ink-muted">{o.sale_date}</td>
                                <td className={`px-4 py-2.5 ${o.is_overdue ? 'text-danger' : 'text-ink-muted'}`}>{o.due_date ?? t('fc2.undated')}{o.is_overdue && ` · ${t('fc2.col.overdue')}`}</td>
                                <td className="num px-4 py-2.5" dir="ltr">{formatMoney(o.remaining, currency)}</td>
                                <td className="px-4 py-2.5 text-end">{canManage && <ScheduleEditor customerId={customerId} order={o} currency={currency} />}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>
            <p className="mt-3 text-[13px] text-ink-muted">{t('fc2.schedule.note')}</p>
            <div className="mt-5"><InstallmentsTable rows={installments} /></div>
            {position.bands && (
                <div className="mt-4 grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                    {Object.entries(position.unbilled_bands ?? position.bands).map(([band, value]) => (
                        <MetricCard key={band} level="secondary" label={t(`fc2.band.${band}` as TranslationKey)} value={value === null ? UNAVAILABLE : formatMoney(value, currency)} unconfigured={value === null} />
                    ))}
                </div>
            )}
            <PromisesPanel customerId={customerId} promises={promises} orders={orders} canManage={canManage} currency={currency} />
        </>
    );
}

function StatementTab({ customerId, statement, period, canExport }: { customerId: number; statement: Statement; period?: { from: string; to: string }; canExport: boolean }) {
    const { t } = useI18n();
    const params = useQueryParams();
    const [from, setFrom] = useState(period?.from ?? '');
    const [to, setTo] = useState(period?.to ?? '');
    const go = () => router.get(`/finance/clients/comptes/${customerId}`, { section: 'statement', from, to }, { preserveState: true, preserveScroll: true });
    const q = new URLSearchParams({ from: period?.from ?? from, to: period?.to ?? to }).toString();
    const cur = statement?.currency_code ?? 'MAD';
    void params;
    return (
        <>
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <label className="text-sm">{t('accountMovements.filter.dateFrom')}<input type="date" className="control-input mt-1 block h-10 px-3" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
                <label className="text-sm">{t('accountMovements.filter.dateTo')}<input type="date" className="control-input mt-1 block h-10 px-3" value={to} onChange={(e) => setTo(e.target.value)} /></label>
                <Button onClick={go} variant="outline">{t('accountMovements.filter.apply')}</Button>
                {canExport && statement && !statement.mixed_currency && (
                    <>
                        <DownloadLink href={`/finance/clients/comptes/${customerId}/releve/pdf?${q}`} variant="secondary" size="sm">PDF</DownloadLink>
                        <DownloadLink href={`/finance/clients/comptes/${customerId}/releve/xlsx?${q}`} variant="secondary" size="sm">Excel</DownloadLink>
                    </>
                )}
            </div>
            {statement === null && <Alert tone="info">{t('fc2.statement.forbidden')}</Alert>}
            {statement?.mixed_currency && <Alert tone="warning">{t('fc2.mixedCurrency')}</Alert>}
            {statement && !statement.mixed_currency && (
                <TableShell label={t('fc.acct.tab.statement')} state="ready">
                    <table className="erp-table min-w-[820px]">
                        <thead><tr><th className="px-4 py-2.5">{t('common.date')}</th><th className="px-4 py-2.5">{t('fc2.col.type')}</th><th className="px-4 py-2.5">{t('fc2.col.reference')}</th><th className="px-4 py-2.5">{t('fc.acct.tab.statement')}</th>
                            <th className="num px-4 py-2.5">{t('fc2.debit')}</th><th className="num px-4 py-2.5">{t('fc2.credit')}</th><th className="num px-4 py-2.5">{t('fc2.balance')}</th></tr></thead>
                        <tbody>
                            <tr className="font-semibold"><td className="px-4 py-2.5" colSpan={6}>{t('fc2.statement.opening')}</td><td className="num px-4 py-2.5" dir="ltr">{formatMoney(statement.opening, cur)}</td></tr>
                            {statement.rows.map((r, i) => (
                                <tr key={`${r.type}-${i}`}>
                                    <td className="px-4 py-2.5 text-ink-muted">{r.date}</td>
                                    <td className="px-4 py-2.5">{t(`fc2.stmt.${r.type}` as TranslationKey)}</td>
                                    <td className="px-4 py-2.5 font-medium">{r.doc_type && r.doc_id ? <Link href={`/documents/open/${r.doc_type}/${r.doc_id}`} method="post" as="button" className="text-start hover:underline">{r.reference}</Link> : r.reference}</td>
                                    <td className="px-4 py-2.5 text-ink-muted">{r.description}</td>
                                    <td className="num px-4 py-2.5" dir="ltr">{Number(r.debit) === 0 ? '' : formatMoney(r.debit, cur)}</td>
                                    <td className="num px-4 py-2.5" dir="ltr">{Number(r.credit) === 0 ? '' : formatMoney(r.credit, cur)}</td>
                                    <td className="num px-4 py-2.5" dir="ltr">{formatMoney(r.balance, cur)}</td>
                                </tr>
                            ))}
                            <tr className="font-semibold"><td className="px-4 py-2.5" colSpan={4}>{t('fc2.statement.closing')}</td>
                                <td className="num px-4 py-2.5" dir="ltr">{formatMoney(statement.total_debit, cur)}</td><td className="num px-4 py-2.5" dir="ltr">{formatMoney(statement.total_credit, cur)}</td><td className="num px-4 py-2.5" dir="ltr">{formatMoney(statement.closing, cur)}</td></tr>
                        </tbody>
                    </table>
                    {statement.truncated && <p className="px-4 py-3 text-[13px] text-warning">{t('fc2.statement.truncated')}</p>}
                </TableShell>
            )}
            <p className="mt-3 text-[13px] text-ink-muted">{t('fc2.statement.note')}</p>
        </>
    );
}

function Openings({ customerId, rows, canManage }: { customerId: number; rows: NonNullable<Props['openings']>; canManage: boolean }) {
    const { t } = useI18n();
    const reverse = (id: number) => {
        const reason = window.prompt(t('fc2.opening.reversePrompt'));
        if (reason && reason.trim()) router.post(`/finance/clients/comptes/${customerId}/solde-ouverture/${id}/inverser`, { reason }, { preserveScroll: true });
    };
    return (
        <TableShell label={t('fc2.opening.title')} state={rows.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc2.opening.empty')}>
            <table className="erp-table min-w-[640px]">
                <thead><tr><th className="px-4 py-2.5">{t('common.date')}</th><th className="px-4 py-2.5">{t('fc2.col.reference')}</th><th className="px-4 py-2.5">{t('fc2.opening.reason')}</th><th className="num px-4 py-2.5">{t('common.amount')}</th><th className="px-4 py-2.5">{t('common.status')}</th><th /></tr></thead>
                <tbody>
                    {rows.map((o) => (
                        <tr key={o.id} className={o.status === 'reversed' ? 'opacity-60' : undefined}>
                            <td className="px-4 py-2.5">{o.effective_date.slice(0, 10)}</td><td className="px-4 py-2.5 font-medium">{o.reference}</td><td className="px-4 py-2.5 text-ink-muted">{o.reason}</td>
                            <td className="num px-4 py-2.5" dir="ltr">{formatMoney(o.amount, o.currency_code)}</td>
                            <td className="px-4 py-2.5">{o.status === 'reversed' ? t('fc2.opening.reversed') : t('fc2.opening.posted')}</td>
                            <td className="px-4 py-2.5 text-end">{canManage && o.status === 'posted' && <Button size="sm" variant="outline" onClick={() => reverse(o.id)}>{t('fc2.opening.reverse')}</Button>}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </TableShell>
    );
}
