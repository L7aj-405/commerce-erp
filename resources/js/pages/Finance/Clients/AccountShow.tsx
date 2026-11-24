import { FinancePlaceholder, SectionTabs, UNAVAILABLE, useSection } from '@/components/finance/FinanceClientsKit';
import { ButtonLink } from '@/components/ui/Button';
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
import { Head, Link } from '@inertiajs/react';

type Summary = {
    sales_total: string | null;
    invoiced_total: string | null;
    paid_total: string | null;
    outstanding: string | null;
    refund_obligation: string | null;
    unbilled_outstanding: string | null;
    last_activity: string | null;
};
type InvoiceRow = { id: number; invoice_number: string; version: number; invoice_date: string; status: string; total_incl_tax: string };
type PaymentRow = { id: number; payment_number: string; payment_date: string; method: string; status: string; amount: string };
type Props = {
    customer: { id: number; display_name: string; company_name: string | null; email: string | null; phone: string | null; status: string };
    summary: Summary;
    invoices: InvoiceRow[];
    invoicesCount: number;
    payments: PaymentRow[];
    paymentsCount: number;
    can: { commercialProfile: boolean };
};

const SECTIONS = ['summary', 'invoices', 'payments', 'schedule', 'statement', 'terms'] as const;
type Section = (typeof SECTIONS)[number];
const SECTION_LABEL: Record<Section, TranslationKey> = {
    summary: 'fc.acct.tab.summary',
    invoices: 'fc.acct.tab.invoices',
    payments: 'fc.acct.tab.payments',
    schedule: 'fc.acct.tab.schedule',
    statement: 'fc.acct.tab.statement',
    terms: 'fc.acct.tab.terms',
};
/** Future functionality (no data model yet): due dates per customer, account statement, opening balance, financial conditions. */
const PLANNED: Partial<Record<Section, TranslationKey>> = {
    schedule: 'fc.acct.planned.schedule',
    statement: 'fc.acct.planned.statement',
    terms: 'fc.acct.planned.terms',
};

const money = (value: string | null) => (value === null ? UNAVAILABLE : formatMoney(value));

/** FC-02 — one customer account. Figures come from CustomerFinancialSummary (viewer-scoped; a hidden figure is an em dash). */
export default function FinanceClientsAccountShow({ customer, summary, invoices, invoicesCount, payments, paymentsCount, can }: Props) {
    const { t } = useI18n();
    const section = useSection(SECTIONS, 'summary');
    const sections = SECTIONS.map((key) => ({ key, label: t(SECTION_LABEL[key]) }));

    return (
        <ApplicationShell wide>
            <Head title={`${customer.display_name} — ${t('fc.accounts.title')}`} />
            <PageHeader
                title={customer.display_name}
                description={[customer.company_name && customer.company_name !== customer.display_name ? customer.company_name : null, customer.phone ?? customer.email]
                    .filter(Boolean)
                    .join(' · ')}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <DocBadge tone={customer.status === 'active' ? 'positive' : 'neutral'}>{t(customer.status === 'active' ? 'status.active' : 'status.inactive')}</DocBadge>
                        <ButtonLink href="/finance/clients/comptes" variant="secondary" size="sm">
                            {t('fc.accounts.title')}
                        </ButtonLink>
                        {can.commercialProfile && (
                            <ButtonLink href={`/sales/customers/${customer.id}`} variant="outline" size="sm">
                                {t('fc.acct.commercialProfile')}
                            </ButtonLink>
                        )}
                    </div>
                }
            />
            <SectionTabs sections={sections} active={section} label={t('fc.accounts.title')} />

            {section === 'summary' && (
                <>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <MetricCard label={t('fc.acct.m.sales')} value={money(summary.sales_total)} helper={t('fc.acct.m.sales.help')} unconfigured={summary.sales_total === null} />
                        <MetricCard label={t('fc.acct.m.invoiced')} value={money(summary.invoiced_total)} helper={t('fc.acct.m.invoiced.help')} unconfigured={summary.invoiced_total === null} />
                        <MetricCard label={t('fc.acct.m.paid')} value={money(summary.paid_total)} helper={t('fc.acct.m.paid.help')} tone="positive" unconfigured={summary.paid_total === null} />
                        <MetricCard label={t('fc.acct.m.outstanding')} value={money(summary.outstanding)} helper={t('fc.acct.m.outstanding.help')} tone="warning" unconfigured={summary.outstanding === null} />
                    </div>
                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                        <MetricCard level="secondary" label={t('fc.acct.m.unbilled')} value={money(summary.unbilled_outstanding)} unconfigured={summary.unbilled_outstanding === null} />
                        <MetricCard level="secondary" label={t('fc.acct.m.refundObligation')} value={money(summary.refund_obligation)} unconfigured={summary.refund_obligation === null} />
                        <MetricCard level="secondary" label={t('fc.acct.m.lastActivity')} value={summary.last_activity ?? UNAVAILABLE} unconfigured={summary.last_activity === null} />
                    </div>
                    <p className="mt-3 text-[13px] text-ink-muted">{t('fc.acct.scopeNote')}</p>
                </>
            )}

            {section === 'invoices' && (
                <TableShell label={t('fc.acct.tab.invoices')} state={invoices.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc.acct.noInvoices')}>
                    <table className="erp-table min-w-[640px]">
                        <thead>
                            <tr>
                                <th className="px-4 py-2.5">{t('finance.billing.invoiceNumber')}</th>
                                <th className="px-4 py-2.5">{t('common.date')}</th>
                                <th className="px-4 py-2.5">{t('common.status')}</th>
                                <th className="num px-4 py-2.5">{t('fc.acct.ttc')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.map((invoice) => (
                                <tr key={invoice.id}>
                                    <td className="px-4 py-2.5 font-medium">
                                        {/* Safe cross-store opener, identical to the commercial customer profile. */}
                                        <Link href={`/documents/open/invoice/${invoice.id}`} method="post" as="button" className="text-start hover:underline">
                                            {invoice.invoice_number}
                                            {invoice.version > 1 && <span className="ms-1 text-ink-faint">v{invoice.version}</span>}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2.5 text-ink-muted">{invoice.invoice_date}</td>
                                    <td className="px-4 py-2.5">
                                        <StatusBadge status={invoice.status}>{label(invoiceStatusLabel, invoice.status)}</StatusBadge>
                                    </td>
                                    <td className="num px-4 py-2.5" dir="ltr">
                                        {formatMoney(invoice.total_incl_tax)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {invoicesCount > invoices.length && <p className="px-4 py-3 text-[13px] text-ink-muted">{t('fc.acct.recentOnly')}</p>}
                </TableShell>
            )}

            {section === 'payments' && (
                <TableShell label={t('fc.acct.tab.payments')} state={payments.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc.acct.noPayments')}>
                    <table className="erp-table min-w-[640px]">
                        <thead>
                            <tr>
                                <th className="px-4 py-2.5">{t('fc.acct.paymentNumber')}</th>
                                <th className="px-4 py-2.5">{t('common.date')}</th>
                                <th className="px-4 py-2.5">{t('fc.acct.method')}</th>
                                <th className="px-4 py-2.5">{t('common.status')}</th>
                                <th className="num px-4 py-2.5">{t('common.amount')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.map((payment) => (
                                <tr key={payment.id}>
                                    <td className="px-4 py-2.5 font-medium">
                                        <Link href={`/documents/open/payment/${payment.id}`} method="post" as="button" className="text-start hover:underline">
                                            {payment.payment_number}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2.5 text-ink-muted">{payment.payment_date}</td>
                                    <td className="px-4 py-2.5 text-ink-muted">{label(paymentMethodLabel, payment.method)}</td>
                                    <td className="px-4 py-2.5">
                                        <StatusBadge status={payment.status}>{label(paymentEntryStatusLabel, payment.status)}</StatusBadge>
                                    </td>
                                    <td className="num px-4 py-2.5" dir="ltr">
                                        {formatMoney(payment.amount)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {paymentsCount > payments.length && <p className="px-4 py-3 text-[13px] text-ink-muted">{t('fc.acct.recentOnly')}</p>}
                </TableShell>
            )}

            {PLANNED[section] && <FinancePlaceholder plannedKey={PLANNED[section]} />}
        </ApplicationShell>
    );
}
