import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import { FinancePlaceholder } from '@/components/finance/FinanceClientsKit';
import { ButtonLink, DownloadLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head } from '@inertiajs/react';

type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    can: { export: boolean };
};

/**
 * FC-09 — reports hub. Pure navigation: each card opens an existing report (which keeps its own calculations and
 * permission checks); each export link targets an existing export route (which re-checks finance.export server-side).
 * Customer profitability and advanced accounting exports are announced placeholders.
 */
export default function FinanceClientsReports({ organization, period, periodLabel, storeId, stores, can }: Props) {
    const { t } = useI18n();
    const query = new URLSearchParams({ month: period, ...(storeId ? { store_id: String(storeId) } : {}) }).toString();
    const reports: { key: string; title: TranslationKey; help: TranslationKey; href: string; exports?: { label: TranslationKey; href: string }[] }[] = [
        { key: 'sales', title: 'nav.salesReport', help: 'fc.reports.sales.help', href: `/finance/ventes?${query}` },
        { key: 'billing', title: 'nav.invoicingReport', help: 'fc.reports.billing.help', href: `/finance/clients/facturation?${query}` },
        {
            key: 'cash',
            title: 'nav.cashRevenue',
            help: 'fc.reports.cash.help',
            href: `/finance/ca-encaisse?${query}`,
            exports: [
                { label: 'fc.reports.xlsx', href: `/finance/ca-encaisse/export/xlsx?${query}` },
                { label: 'fc.reports.pdf', href: `/finance/ca-encaisse/export/pdf?${query}&orientation=landscape` },
            ],
        },
        {
            key: 'situation',
            title: 'fc.reports.situation',
            help: 'fc.reports.situation.help',
            href: `/finance?${query}`,
            exports: [
                { label: 'fc.reports.xlsx', href: `/finance/export/xlsx?${query}` },
                { label: 'fc.reports.pdf', href: `/finance/export/pdf?${query}` },
            ],
        },
    ];

    return (
        <ApplicationShell wide>
            <Head title={`${t('fc.reports.title')} — Finance`} />
            <PageHeader title={t('fc.reports.title')} description={`${organization.name} · ${periodLabel} — ${t('fc.reports.description')}`} />
            <FinanceFilters path="/finance/clients/rapports" period={period} storeId={storeId} stores={stores} />

            <section aria-label={t('fc.reports.available')} className="mb-8 grid gap-4 md:grid-cols-2">
                {reports.map((report) => (
                    <article key={report.key} className="surface-card flex flex-col gap-3 p-4">
                        <div>
                            <h2 className="text-base font-semibold text-ink">{t(report.title)}</h2>
                            <p className="mt-1 text-sm text-ink-muted">{t(report.help)}</p>
                        </div>
                        <div className="mt-auto flex flex-wrap gap-2">
                            <ButtonLink href={report.href} size="sm" variant="outline">
                                {t('common.open')}
                            </ButtonLink>
                            {can.export &&
                                report.exports?.map((link) => (
                                    <DownloadLink key={link.href} href={link.href} size="sm" variant="secondary">
                                        {t(link.label)}
                                    </DownloadLink>
                                ))}
                        </div>
                    </article>
                ))}
            </section>

            <section aria-labelledby="fc-reports-future">
                <h2 id="fc-reports-future" className="mb-3 text-base font-semibold text-ink">
                    {t('fc.overview.upcoming')}
                </h2>
                <div className="grid gap-4 md:grid-cols-2">
                    {/* Future functionality: customer profitability report, advanced accounting exports. */}
                    {(['fc.reports.profitability', 'fc.reports.accountingExports'] as const).map((key) => (
                        <div key={key} className="surface-card p-4">
                            <h3 className="mb-3 text-sm font-semibold text-ink">{t(key)}</h3>
                            <FinancePlaceholder />
                        </div>
                    ))}
                </div>
            </section>
        </ApplicationShell>
    );
}
