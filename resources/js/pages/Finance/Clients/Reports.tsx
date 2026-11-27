import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import { ParentTabs, UNAVAILABLE, useSection } from '@/components/finance/FinanceClientsKit';
import { Alert } from '@/components/ui/Alert';
import DocBadge from '@/components/ui/DocBadge';
import { Button, ButtonLink, DownloadLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import { TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Profit = {
    from: string; to: string; truncated: boolean;
    rows: { customer_id: number; customer: string; revenue: string; covered_revenue: string; cost: string | null; margin: string | null; margin_rate: string | null; coverage_rate: string | null; status: 'complete' | 'partial' | 'unavailable'; missing_cost_lines: number; incomplete: boolean }[];
    totals: { revenue: string; covered_revenue: string; cost: string | null; margin: string | null; margin_rate: string | null; coverage_rate: string | null; status: 'complete' | 'partial' | 'unavailable'; missing_cost_lines: number; incomplete: number };
};
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    profitability: Profit | null;
    statementCustomers: { id: number; display_name: string }[];
    can: { export: boolean; accounts: boolean };
};

type Card = { key: string; title: TranslationKey; help: TranslationKey; href: string; picker?: boolean; exports?: { label: string; href: string }[] };
const pct = (rate: string | null) => (rate === null ? UNAVAILABLE : `${(Number(rate) * 100).toFixed(1)} %`);

/**
 * FC-09 — reports hub. Each card opens the page that owns the report (same service, same filters) or an existing export route;
 * exports re-check finance.export server-side and reconcile with their page. Customer profitability is computed from the committed
 * cost snapshots only and flags incomplete cost data per customer.
 */
export default function FinanceClientsReports({ organization, period, periodLabel, storeId, stores, profitability, statementCustomers, can }: Props) {
    const { t } = useI18n();
    const section = useSection(['catalog', 'profitability'] as const, 'catalog');
    const [statementCustomer, setStatementCustomer] = useState('');
    const money = (v: string | null) => (v === null ? UNAVAILABLE : formatMoney(v));
    const query = new URLSearchParams({ month: period, ...(storeId ? { store_id: String(storeId) } : {}) }).toString();
    // Ordered by purpose. Every card is an access point to an authoritative report (no calculation is redone here); two reports with
    // distinct purposes keep distinct destinations (balances = the account list, statements = a chosen customer's statement).
    const cards: Card[] = [
        { key: 'sales', title: 'nav.salesReport', help: 'fc.reports.sales.help', href: `/finance/ventes?${query}`, exports: [{ label: 'Excel', href: `/finance/export/xlsx?${query}` }, { label: 'PDF', href: `/finance/export/pdf?${query}` }] },
        { key: 'billing', title: 'fc5.rep.vat', help: 'fc5.rep.vat.help', href: `/finance/clients/facturation?section=summary&${query}` },
        { key: 'cash', title: 'nav.cashRevenue', help: 'fc.reports.cash.help', href: `/finance/ca-encaisse?${query}`, exports: [
            { label: 'Excel', href: `/finance/ca-encaisse/export/xlsx?${query}` }, { label: 'PDF', href: `/finance/ca-encaisse/export/pdf?${query}&orientation=landscape` }] },
        { key: 'balances', title: 'fc5.rep.balances', help: 'fc5.rep.balances.help', href: '/finance/clients/comptes', exports: [
            { label: 'CSV', href: '/finance/clients/comptes/export/csv' }, { label: 'Excel', href: '/finance/clients/comptes/export/xlsx' }] },
        { key: 'statements', title: 'fc5.rep.statements', help: 'fc5.rep.statements.help', href: '', picker: true },
        { key: 'aging', title: 'fc.rec.s.aging', help: 'fc5.rep.aging.help', href: `/finance/clients/creances?section=aging&${query}`, exports: [
            { label: 'CSV', href: `/finance/clients/creances/balance-agee/export/csv?${query}` }, { label: 'Excel', href: `/finance/clients/creances/balance-agee/export/xlsx?${query}` }] },
        { key: 'forecast', title: 'fc.rec.s.forecasts', help: 'fc5.rep.forecast.help', href: `/finance/clients/creances?section=forecasts&${query}` },
        { key: 'credit', title: 'fc5.rep.credit', help: 'fc5.rep.credit.help', href: '/finance/clients/credit' },
        { key: 'instruments', title: 'fc.inst.s.portfolio', help: 'fc5.rep.instruments.help', href: '/finance/clients/instruments' },
        { key: 'journal', title: 'nav.fc.journal', help: 'fc5.rep.journal.help', href: `/finance/clients/journal?${query}`, exports: [
            { label: 'CSV', href: `/finance/clients/journal/export/csv?${query}` }, { label: 'Excel', href: `/finance/clients/journal/export/xlsx?${query}` }] },
        { key: 'profitability', title: 'fc.reports.profitability', help: 'p3.rep.profitability.help', href: `/finance/clients/rapports?section=profitability&${query}`, exports: [
            { label: 'CSV', href: `/finance/clients/rapports/rentabilite/export/csv?${query}` }, { label: 'Excel', href: `/finance/clients/rapports/rentabilite/export/xlsx?${query}` }] },
    ];

    return (
        <ApplicationShell wide>
            <Head title={`${t('fc.reports.title')} — Finance`} />
            <PageHeader title={t('fc.reports.title')} description={`${organization.name} · ${periodLabel} — ${t('fc.reports.description')}`} />
            <ParentTabs group="reports" active={section} />
            <FinanceFilters path="/finance/clients/rapports" extra={{ section }} period={period} storeId={storeId} stores={stores} />

            {section === 'catalog' && (
                <section aria-label={t('fc.reports.available')} className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {cards.map((card) => (
                        <article key={card.key} className="surface-card flex flex-col gap-3 p-4">
                            <div><h2 className="text-base font-semibold text-ink">{t(card.title)}</h2><p className="mt-1 text-sm text-ink-muted">{t(card.help)}</p></div>
                            {card.picker ? (
                                can.accounts ? (
                                    <form
                                        className="mt-auto flex flex-wrap items-end gap-2"
                                        onSubmit={(e) => { e.preventDefault(); if (statementCustomer) router.get(`/finance/clients/comptes/${statementCustomer}`, { section: 'statement' }); }}
                                    >
                                        <select className="control-select h-9 min-w-0 flex-1" aria-label={t('fc5.rep.statements')} value={statementCustomer} onChange={(e) => setStatementCustomer(e.target.value)}>
                                            <option value="">{t('p3.rep.statement.pick')}</option>
                                            {statementCustomers.map((c) => <option key={c.id} value={c.id}>{c.display_name}</option>)}
                                        </select>
                                        <Button type="submit" size="sm" variant="outline" disabled={!statementCustomer}>{t('p3.rep.statement.open')}</Button>
                                    </form>
                                ) : <p className="mt-auto text-[13px] text-ink-muted">{t('p3.rep.statement.noAccess')}</p>
                            ) : (
                                <div className="mt-auto flex flex-wrap gap-2">
                                    <ButtonLink href={card.href} size="sm" variant="outline">{t('p3.rep.consult')}</ButtonLink>
                                    {can.export && card.exports?.map((link) => <DownloadLink key={link.href} href={link.href} size="sm" variant="secondary">{t('p3.rep.export')} {link.label}</DownloadLink>)}
                                </div>
                            )}
                        </article>
                    ))}
                </section>
            )}

            {section === 'profitability' && profitability && (
                <>
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <p className="text-sm text-ink-muted">{t('fc5.profit.note')}</p>
                        {can.export && <div className="flex gap-2"><DownloadLink href={`/finance/clients/rapports/rentabilite/export/csv?${query}`} variant="secondary" size="sm">CSV</DownloadLink><DownloadLink href={`/finance/clients/rapports/rentabilite/export/xlsx?${query}`} variant="secondary" size="sm">Excel</DownloadLink></div>}
                    </div>
                    {profitability.totals.incomplete === 1 && <div className="mb-3"><Alert tone="warning">{profitability.totals.status === 'unavailable' ? t('p3.profit.unavailableNote') : t('fc5.profit.incomplete')} ({profitability.totals.missing_cost_lines}) · {t('p3.profit.coverage')} : {pct(profitability.totals.coverage_rate)}</Alert></div>}
                    {profitability.truncated && <div className="mb-3"><Alert tone="warning">{t('fc2.accounts.tooMany')}</Alert></div>}
                    <TableShell label={t('fc.reports.profitability')} state={profitability.rows.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc5.profit.empty')}>
                        <table className="erp-table min-w-[900px] text-sm">
                            <thead><tr><th className="px-4 py-2.5">{t('common.customer')}</th><th className="num px-4 py-2.5">{t('fc5.profit.revenue')}</th><th className="num px-4 py-2.5">{t('fc5.profit.covered')}</th><th className="num px-4 py-2.5">{t('p3.profit.coverage')}</th>
                                <th className="num px-4 py-2.5">{t('fc5.profit.cost')}</th><th className="num px-4 py-2.5">{t('fc5.profit.margin')}</th><th className="num px-4 py-2.5">{t('fc5.profit.rate')}</th><th className="px-4 py-2.5">{t('common.status')}</th></tr></thead>
                            <tbody>
                                {profitability.rows.map((r) => (
                                    <tr key={r.customer_id}>
                                        <td className="px-4 py-2.5 font-medium">{r.customer}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{formatMoney(r.revenue)}</td><td className="num px-4 py-2.5" dir="ltr">{formatMoney(r.covered_revenue)}</td><td className="num px-4 py-2.5" dir="ltr">{pct(r.coverage_rate)}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{money(r.cost)}</td><td className="num px-4 py-2.5 font-medium" dir="ltr">{money(r.margin)}</td><td className="num px-4 py-2.5" dir="ltr">{pct(r.margin_rate)}</td>
                                        <td className="px-4 py-2.5">{r.status === 'unavailable' ? <DocBadge tone="warning">{t('p3.profit.unavailable')} ({r.missing_cost_lines})</DocBadge> : r.incomplete ? <DocBadge tone="warning">{t('p3.profit.partial')} ({r.missing_cost_lines})</DocBadge> : <DocBadge tone="positive">{t('fc5.profit.complete')}</DocBadge>}</td>
                                    </tr>
                                ))}
                                <tr className="font-semibold"><td className="px-4 py-2.5">{t('common.total')}</td><td className="num px-4 py-2.5" dir="ltr">{formatMoney(profitability.totals.revenue)}</td><td className="num px-4 py-2.5" dir="ltr">{formatMoney(profitability.totals.covered_revenue)}</td><td className="num px-4 py-2.5" dir="ltr">{pct(profitability.totals.coverage_rate)}</td>
                                    <td className="num px-4 py-2.5" dir="ltr">{money(profitability.totals.cost)}</td><td className="num px-4 py-2.5" dir="ltr">{money(profitability.totals.margin)}</td><td className="num px-4 py-2.5" dir="ltr">{pct(profitability.totals.margin_rate)}</td><td /></tr>
                            </tbody>
                        </table>
                    </TableShell>
                </>
            )}
        </ApplicationShell>
    );
}
