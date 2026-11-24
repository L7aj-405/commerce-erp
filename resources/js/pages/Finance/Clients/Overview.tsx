import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import { FinancePlaceholder, UNAVAILABLE } from '@/components/finance/FinanceClientsKit';
import { ButtonLink } from '@/components/ui/Button';
import { MetricCard } from '@/components/ui/MetricCard';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head } from '@inertiajs/react';

type Metrics = {
    sales: string | null;
    billing: string | null;
    collections: string | null;
    receivables: string | null;
    refunds: string | null;
    refund_obligations: string | null;
    unbilled_orders: string | null;
};
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    metrics: Metrics;
    can: { accounts: boolean };
};

const money = (value: string | null) => (value === null ? UNAVAILABLE : formatMoney(value));

/** FC-01 — customer-finance dashboard. Figures are the existing monthly-situation values; nothing is recomputed here. */
export default function FinanceClientsOverview({ organization, period, periodLabel, storeId, stores, metrics, can }: Props) {
    const { t } = useI18n();
    const shortcuts: { href: string; label: TranslationKey; show: boolean }[] = [
        { href: '/finance/clients/comptes', label: 'nav.fc.accounts', show: can.accounts },
        { href: '/finance/clients/facturation', label: 'nav.fc.invoicing', show: true },
        { href: '/finance/clients/encaissements', label: 'nav.fc.collections', show: true },
        { href: '/finance/clients/creances', label: 'nav.fc.receivables', show: true },
    ];

    return (
        <ApplicationShell wide>
            <Head title={`${t('fc.overview.title')} — Finance`} />
            <PageHeader title={t('fc.overview.title')} description={`${organization.name} · ${periodLabel} — ${t('fc.overview.description')}`} />
            <FinanceFilters path="/finance/clients" period={period} storeId={storeId} stores={stores} />

            <section aria-label={t('fc.overview.title')} className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard label={t('fc.m.sales')} value={money(metrics.sales)} helper={t('fc.m.sales.help')} unconfigured={metrics.sales === null} />
                <MetricCard label={t('fc.m.billing')} value={money(metrics.billing)} helper={t('fc.m.billing.help')} unconfigured={metrics.billing === null} />
                <MetricCard label={t('fc.m.collections')} value={money(metrics.collections)} helper={t('fc.m.collections.help')} tone="positive" unconfigured={metrics.collections === null} />
                <MetricCard label={t('fc.m.receivables')} value={money(metrics.receivables)} helper={t('fc.m.receivables.help')} tone="warning" unconfigured={metrics.receivables === null} />
            </section>
            <section aria-label={t('fc.overview.secondary')} className="mb-8 grid gap-3 sm:grid-cols-3">
                <MetricCard level="secondary" label={t('fc.m.refunds')} value={money(metrics.refunds)} tone="negative" unconfigured={metrics.refunds === null} />
                <MetricCard level="secondary" label={t('fc.m.unbilled')} value={money(metrics.unbilled_orders)} helper={t('fc.m.unbilled.help')} unconfigured={metrics.unbilled_orders === null} />
                <MetricCard
                    level="secondary"
                    label={t('fc.m.refundObligations')}
                    value={money(metrics.refund_obligations)}
                    helper={t('fc.m.refundObligations.help')}
                    unconfigured={metrics.refund_obligations === null}
                />
            </section>

            <section className="mb-8" aria-labelledby="fc-future">
                <h2 id="fc-future" className="mb-3 text-base font-semibold text-ink">
                    {t('fc.overview.upcoming')}
                </h2>
                <div className="grid gap-4 md:grid-cols-3">
                    {/* Future functionality: advanced customer alerts, credit risk, consolidated customer forecasts. */}
                    {(['fc.overview.alerts', 'fc.overview.creditRisk', 'fc.overview.forecast'] as const).map((key) => (
                        <div key={key} className="surface-card p-4">
                            <h3 className="mb-3 text-sm font-semibold text-ink">{t(key)}</h3>
                            <FinancePlaceholder />
                        </div>
                    ))}
                </div>
            </section>

            <nav aria-label={t('fc.overview.shortcuts')} className="flex flex-wrap gap-2">
                {shortcuts
                    .filter((shortcut) => shortcut.show)
                    .map((shortcut) => (
                        <ButtonLink key={shortcut.href} href={shortcut.href} variant="outline" size="sm">
                            {t(shortcut.label)}
                        </ButtonLink>
                    ))}
            </nav>
        </ApplicationShell>
    );
}
