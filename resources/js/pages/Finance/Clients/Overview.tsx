import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import { UNAVAILABLE } from '@/components/finance/FinanceClientsKit';
import { Alert } from '@/components/ui/Alert';
import { ButtonLink } from '@/components/ui/Button';
import { MetricCard } from '@/components/ui/MetricCard';
import PageHeader from '@/components/ui/PageHeader';
import { TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head } from '@inertiajs/react';

type Dashboard = {
    flows: { sales_ht: string; sales_ttc_net: string; billing_net_ttc: string; collections_gross: string; refunds: string; collections_net: string };
    balances: {
        as_of: string; by_currency: Record<string, { receivable: string; unbilled: string; overdue: string; undated: string; total: string }>;
        refund_obligations: string; advances: Record<string, string>; pending_instruments: Record<string, string>;
    };
    expected_collections: { by_currency: Record<string, string>; window: string };
    credit_alerts: { over_limit: number; blocked: number; with_overdue: number };
    trend: { month: string; sales: string; collections: string }[];
    truncated: boolean;
};
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    dashboard: Dashboard;
    can: { accounts: boolean };
};

/**
 * FC-01 — customer-finance dashboard. PERIOD FLOWS (what happened in the month) and BALANCES (the position at the end of the
 * month) are kept in separate blocks. Every figure is produced server-side by the same services as the detail pages; nothing is
 * summed from paginated rows in the browser. Balances are per currency (never added across currencies).
 */
export default function FinanceClientsOverview({ organization, period, periodLabel, storeId, stores, dashboard, can }: Props) {
    const { t } = useI18n();
    const f = dashboard.flows;
    const currencies = Object.keys(dashboard.balances.by_currency);
    const money = (value: string | null | undefined, currency = 'MAD') => (value === null || value === undefined ? UNAVAILABLE : formatMoney(value, currency));
    const month = `month=${period}${storeId ? `&store_id=${storeId}` : ''}`;
    const shortcuts: { href: string; label: TranslationKey; show: boolean }[] = [
        { href: '/finance/clients/comptes', label: 'nav.fc.accounts', show: can.accounts },
        { href: `/finance/clients/facturation?${month}`, label: 'nav.fc.invoicing', show: true },
        { href: `/finance/clients/encaissements?${month}`, label: 'nav.fc.collections', show: true },
        { href: `/finance/clients/creances?section=aging&${month}`, label: 'fc.rec.s.aging', show: true },
        { href: '/finance/clients/credit', label: 'nav.fc.credit', show: true },
        { href: '/finance/clients/instruments', label: 'nav.fc.instruments', show: true },
    ];

    return (
        <ApplicationShell wide>
            <Head title={`${t('fc.overview.title')} — Finance`} />
            <PageHeader title={t('fc.overview.title')} description={`${organization.name} · ${periodLabel} — ${t('fc.overview.description')}`} />
            <FinanceFilters path="/finance/clients" period={period} storeId={storeId} stores={stores} />
            {dashboard.truncated && <div className="mb-4"><Alert tone="warning">{t('fc2.accounts.tooMany')}</Alert></div>}

            <section aria-labelledby="fc-flows" className="mb-6">
                <h2 id="fc-flows" className="mb-1 text-base font-semibold text-ink">{t('fc5.dash.flows')}</h2>
                <p className="mb-3 text-[13px] text-ink-muted">{t('fc5.dash.flowsHelp')} ({periodLabel})</p>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <MetricCard label={t('fc5.dash.salesHt')} value={money(f.sales_ht)} helper={t('fc5.dash.salesHt.help')} />
                    <MetricCard label={t('fc.m.sales')} value={money(f.sales_ttc_net)} helper={t('fc.m.sales.help')} />
                    <MetricCard label={t('fc.m.billing')} value={money(f.billing_net_ttc)} helper={t('fc.m.billing.help')} />
                    <MetricCard label={t('fc5.dash.collectionsGross')} value={money(f.collections_gross)} helper={t('fc.m.collections.help')} tone="positive" />
                    <MetricCard label={t('fc.m.refunds')} value={money(f.refunds)} tone="negative" />
                    <MetricCard label={t('fc5.dash.collectionsNet')} value={money(f.collections_net)} helper={t('fc5.dash.collectionsNet.help')} tone="positive" />
                </div>
            </section>

            <section aria-labelledby="fc-balances" className="mb-6">
                <h2 id="fc-balances" className="mb-1 text-base font-semibold text-ink">{t('fc5.dash.balances')}</h2>
                <p className="mb-3 text-[13px] text-ink-muted">{t('fc5.dash.balancesHelp')} {dashboard.balances.as_of}</p>
                {currencies.length === 0 && <Alert tone="info">{t('fc3.aging.empty')}</Alert>}
                {currencies.map((currency) => {
                    const b = dashboard.balances.by_currency[currency]!;
                    return (
                        <div key={currency} className="mb-4">
                            {currencies.length > 1 && <h3 className="mb-2 text-sm font-semibold text-ink">{currency}</h3>}
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                <MetricCard label={t('fc2.col.receivable')} value={money(b.receivable, currency)} helper={t('fc.m.receivables.help')} tone="warning" />
                                <MetricCard label={t('fc5.dash.overdue')} value={money(b.overdue, currency)} helper={t('fc5.dash.overdue.help')} tone="negative" />
                                <MetricCard label={t('fc2.col.unbilled')} value={money(b.unbilled, currency)} helper={t('fc2.help.unbilled')} />
                                <MetricCard label={t('fc5.dash.undated')} value={money(b.undated, currency)} helper={t('fc5.dash.undated.help')} />
                            </div>
                        </div>
                    );
                })}
                <div className="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard level="secondary" label={t('fc2.col.advances')} value={Object.keys(dashboard.balances.advances).length ? Object.entries(dashboard.balances.advances).map(([c, v]) => money(v, c)).join(' · ') : money('0.0000')} helper={t('fc2.help.advances')} />
                    <MetricCard level="secondary" label={t('fc.m.refundObligations')} value={money(dashboard.balances.refund_obligations)} helper={t('fc.m.refundObligations.help')} />
                    <MetricCard level="secondary" label={t('fc2.col.instruments')} value={Object.keys(dashboard.balances.pending_instruments).length ? Object.entries(dashboard.balances.pending_instruments).map(([c, v]) => money(v, c)).join(' · ') : money('0.0000')} helper={t('fc2.help.instruments')} />
                    <MetricCard level="secondary" label={t('fc5.dash.expected')} value={Object.keys(dashboard.expected_collections.by_currency).length ? Object.entries(dashboard.expected_collections.by_currency).map(([c, v]) => money(v, c)).join(' · ') : money('0.0000')} helper={t('fc5.dash.expected.help')} tone="forecast" />
                </div>
            </section>

            <section aria-labelledby="fc-alerts" className="mb-6">
                <h2 id="fc-alerts" className="mb-3 text-base font-semibold text-ink">{t('fc5.dash.alerts')}</h2>
                <div className="grid gap-3 sm:grid-cols-3">
                    <MetricCard level="secondary" label={t('fc5.dash.overLimit')} value={String(dashboard.credit_alerts.over_limit)} href="/finance/clients/credit?section=overruns" tone={dashboard.credit_alerts.over_limit > 0 ? 'negative' : 'neutral'} />
                    <MetricCard level="secondary" label={t('fc5.dash.blocked')} value={String(dashboard.credit_alerts.blocked)} href="/finance/clients/credit?section=blocked" tone={dashboard.credit_alerts.blocked > 0 ? 'warning' : 'neutral'} />
                    <MetricCard level="secondary" label={t('fc5.dash.withOverdue')} value={String(dashboard.credit_alerts.with_overdue)} href="/finance/clients/credit?section=exposure" tone={dashboard.credit_alerts.with_overdue > 0 ? 'warning' : 'neutral'} />
                </div>
            </section>

            <section aria-labelledby="fc-trend" className="mb-6">
                <h2 id="fc-trend" className="mb-3 text-base font-semibold text-ink">{t('fc5.dash.trend')}</h2>
                <TableShell label={t('fc5.dash.trend')} state="ready">
                    <table className="erp-table min-w-[480px]">
                        <thead><tr><th className="px-4 py-2.5">{t('common.period')}</th><th className="num px-4 py-2.5">{t('fc.m.sales')}</th><th className="num px-4 py-2.5">{t('fc5.dash.collectionsNet')}</th></tr></thead>
                        <tbody>{dashboard.trend.map((row) => <tr key={row.month}><td className="px-4 py-2.5">{row.month}</td><td className="num px-4 py-2.5" dir="ltr">{money(row.sales)}</td><td className="num px-4 py-2.5" dir="ltr">{money(row.collections)}</td></tr>)}</tbody>
                    </table>
                </TableShell>
            </section>

            <nav aria-label={t('fc.overview.shortcuts')} className="flex flex-wrap gap-2">
                {shortcuts.filter((shortcut) => shortcut.show).map((shortcut) => <ButtonLink key={shortcut.href} href={shortcut.href} variant="outline" size="sm">{t(shortcut.label)}</ButtonLink>)}
            </nav>
        </ApplicationShell>
    );
}
