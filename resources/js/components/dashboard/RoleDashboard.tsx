import { OrderStatesChart, RankedMoneyChart, SalesCollectionsChart } from '@/components/dashboard/DashboardCharts';
import { ButtonLink } from '@/components/ui/Button';
import { PanelTitle } from '@/components/ui/Panel';
import { useI18n } from '@/i18n';
import {
    IconAlertBox,
    IconBanknote,
    IconCart,
    IconChart,
    IconClipboard,
    IconClockCoins,
    IconContact,
    IconFileText,
    IconPackage,
    IconRegister,
    IconShoppingBag,
    IconSync,
    IconUsers,
    IconWallet,
    IconWarehouse,
} from '@/layouts/navigation';
import type { DashboardProps } from '@/pages/Platform/Index';
import type { SharedPageProps } from '@/types/app';
import { formatInteger, formatMoney } from '@/utils/format';
import { Link, router, usePage } from '@inertiajs/react';
import { useId } from 'react';
import type { ReactNode } from 'react';

/**
 * Role-aware dashboard (Visual Direction V3). Every figure is a backend prop that
 * is already permission-gated server-side; every action is a real, permitted route.
 * The user's real permissions only change ORDER and emphasis — never which data exists.
 *
 * Hierarchy: KPIs → priority tasks beside the main trend → quick actions →
 * secondary analysis → setup progress (only while incomplete).
 */
type Focus = 'sales' | 'inventory' | 'finance';
type Ranked = { focus: Focus[] };
type Tint = 'violet' | 'blue' | 'rose' | 'mint' | 'amber';

const tints: Record<Tint, string> = {
    violet: 'bg-violet-soft text-violet',
    blue: 'bg-blue-soft text-blue',
    rose: 'bg-rose-soft text-rose',
    mint: 'bg-mint-soft text-mint',
    amber: 'bg-amber-soft text-amber',
};
const actionTiles: Record<Tint, string> = {
    violet: 'bg-accent-soft/70 [&_svg]:text-accent hover:bg-accent-soft',
    blue: 'bg-blue-soft/80 [&_svg]:text-blue hover:bg-blue-soft',
    mint: 'bg-mint-soft/70 [&_svg]:text-mint hover:bg-mint-soft',
    amber: 'bg-amber-soft/80 [&_svg]:text-amber hover:bg-amber-soft',
    rose: 'bg-rose-soft/80 [&_svg]:text-rose hover:bg-rose-soft',
};
/** Soft corner wash behind each KPI (decorative light, not data). */
const washes: Record<Tint, string> = {
    violet: 'rgb(49 173 153 / 0.22)',
    blue: 'rgb(20 113 162 / 0.15)',
    rose: 'rgb(191 82 70 / 0.15)',
    mint: 'rgb(39 128 78 / 0.18)',
    amber: 'rgb(214 146 52 / 0.24)',
};
const strokes: Record<Tint, string> = {
    violet: 'var(--color-violet)',
    blue: 'var(--color-blue)',
    rose: 'var(--color-rose)',
    mint: 'var(--color-mint)',
    amber: 'var(--color-amber)',
};

const checklist = ['organization', 'store', 'products', 'stock', 'first_sale'] as const;

export default function RoleDashboard({ firstName, dashboard, onboarding, executiveDashboard }: DashboardProps & { firstName: string }) {
    const { t } = useI18n();
    const { tenant } = usePage<SharedPageProps>().props;
    const can = (permission: string) => tenant.permissions.includes(permission);
    const hasStore = !!tenant.store;

    // Focus order from real permissions (executive view → finance first).
    const focusOrder: Focus[] = [];
    const pushFocus = (focus: Focus, when: boolean) => when && !focusOrder.includes(focus) && focusOrder.push(focus);
    pushFocus('finance', executiveDashboard !== null);
    pushFocus('sales', can('pos.access') || can('sales_orders.view'));
    pushFocus('inventory', can('inventory.view') || can('procurement.view'));
    pushFocus('finance', can('finance.view') || can('payments.view'));
    const rank = (item: Ranked) => Math.min(...item.focus.map((focus) => (focusOrder.includes(focus) ? focusOrder.indexOf(focus) : 99)));
    const byRole = <T extends Ranked>(items: T[]) => [...items].sort((a, b) => rank(a) - rank(b));

    /* Priority tasks -------------------------------------------------------- */
    type Attention = Ranked & { key: string; label: string; value: number; display: string; href: string; icon: ReactNode; tint: Tint };
    const attention: Attention[] = [];
    const pushCount = (key: string, value: number | null, label: string, href: string, icon: ReactNode, tint: Tint, focus: Focus[]) => {
        if (value !== null) attention.push({ key, label, value, display: formatInteger(value), href, icon, tint, focus });
    };
    pushCount('payments', dashboard.payments_to_receive, t('platform.paymentsToReceive'), '/payments', <IconWallet />, 'amber', ['finance', 'sales']);
    pushCount('catalog', dashboard.out_of_stock_articles_pending, t('platform.itemsToCatalog'), '/procurement/out-of-stock-articles', <IconAlertBox />, 'rose', ['inventory']);
    pushCount('woo', dashboard.woo_stock_tasks_pending, t('platform.wooStockPending'), '/integrations/woocommerce/stock-tasks', <IconSync />, 'blue', ['inventory']);
    // Skip executive items already covered by a counter above or by a key figure.
    const kpiPaths = new Set(['/finance/creances', '/finance/ventes', '/finance/ca-encaisse']);
    executiveDashboard?.attention.forEach((item) => {
        const path = item.href.split('?')[0];
        if (kpiPaths.has(path) || attention.some((existing) => existing.href === item.href)) return;
        const numeric = Number(item.value);
        attention.push({
            key: `exec-${item.label}`,
            label: item.label,
            value: Number.isFinite(numeric) ? numeric : 0,
            display: item.kind === 'money' ? formatMoney(item.value) : formatInteger(numeric),
            href: item.href,
            icon: item.kind === 'money' ? <IconBanknote /> : <IconClipboard />,
            tint: 'amber',
            focus: ['finance'],
        });
    });
    const pending = byRole(attention.filter((item) => item.value > 0));
    const cleared = byRole(attention.filter((item) => item.value <= 0));

    /* KPIs ------------------------------------------------------------------- */
    type Kpi = { key: string; label: string; value: string; href: string; icon: ReactNode; tint: Tint; variation?: string | null; series?: number[] };
    const series = executiveDashboard?.charts.sales_vs_collections ?? [];
    const kpis: Kpi[] = executiveDashboard
        ? [
              {
                  key: 'sales',
                  label: t('platform.netSales'),
                  value: formatMoney(executiveDashboard.primary.net_sales.value),
                  href: '/finance/ventes',
                  icon: <IconShoppingBag />,
                  tint: 'violet',
                  variation: executiveDashboard.primary.net_sales.variation,
                  series: series.map((point) => Number(point.sales)),
              },
              {
                  key: 'orders',
                  label: t('commissions.dashboard.orders'),
                  value: formatInteger(executiveDashboard.primary.orders.value),
                  href: '/sales/orders',
                  icon: <IconPackage />,
                  tint: 'blue',
                  variation: executiveDashboard.primary.orders.variation,
              },
              {
                  key: 'receivables',
                  label: t('platform.receivables'),
                  value: formatMoney(executiveDashboard.primary.receivables.value),
                  href: '/finance/creances',
                  icon: <IconUsers />,
                  tint: 'amber',
                  variation: null,
              },
              {
                  key: 'collections',
                  label: t('platform.netCollections'),
                  value: formatMoney(executiveDashboard.primary.net_collections.value),
                  href: '/finance/ca-encaisse',
                  icon: <IconBanknote />,
                  tint: 'mint',
                  variation: executiveDashboard.primary.net_collections.variation,
                  series: series.map((point) => Number(point.collections)),
              },
          ]
        : byRole(
              [
                  { key: 'sales', label: t('platform.salesToday'), raw: dashboard.sales_today, href: '/sales/orders', icon: <IconShoppingBag />, tint: 'violet' as Tint, focus: ['sales'] as Focus[] },
                  { key: 'products', label: t('products.title'), raw: dashboard.product_count, href: '/catalog/products', icon: <IconPackage />, tint: 'blue' as Tint, focus: ['inventory'] as Focus[] },
                  { key: 'stocked', label: t('platform.stockedItems'), raw: dashboard.stocked_item_count, href: '/inventory/stock', icon: <IconWarehouse />, tint: 'mint' as Tint, focus: ['inventory'] as Focus[] },
              ].filter((kpi) => kpi.raw !== null),
          ).map((kpi) => ({ key: kpi.key, label: kpi.label, href: kpi.href, icon: kpi.icon, tint: kpi.tint, value: formatInteger(kpi.raw ?? 0) }));

    /* Quick actions (create flows) + daily shortcuts (navigation) ------------ */
    const quickActions = [
        { href: '/pos', label: t('platform.newSale'), icon: <IconRegister />, tint: 'violet' as Tint, show: can('pos.access') && hasStore },
        { href: '/catalog/products/create', label: t('platform.addProduct'), icon: <IconPackage />, tint: 'blue' as Tint, show: can('products.create') },
        { href: '/sales/customers/create', label: t('dash.newCustomer'), icon: <IconContact />, tint: 'mint' as Tint, show: can('customers.create') },
        { href: '/quotations/create', label: t('dash.newQuote'), icon: <IconFileText />, tint: 'amber' as Tint, show: can('quotations.create') && hasStore },
        { href: '/procurement', label: t('shell.newPurchase'), icon: <IconCart />, tint: 'rose' as Tint, show: can('procurement.manage') },
    ].filter((action) => action.show);

    const shortcuts = byRole(
        [
            { href: '/sales/orders', label: t('nav.orders'), icon: <IconClipboard />, show: can('sales_orders.view') && hasStore, focus: ['sales'] as Focus[] },
            { href: '/sales/customers', label: t('nav.customers'), icon: <IconUsers />, show: can('customers.view'), focus: ['sales'] as Focus[] },
            { href: '/inventory/stock', label: t('nav.stockState'), icon: <IconWarehouse />, show: can('inventory.view'), focus: ['inventory'] as Focus[] },
            { href: '/procurement', label: t('nav.procurement'), icon: <IconCart />, show: can('procurement.view'), focus: ['inventory'] as Focus[] },
            { href: '/payments', label: t('nav.payments'), icon: <IconWallet />, show: can('payments.view') && hasStore, focus: ['finance'] as Focus[] },
            { href: '/finance', label: t('nav.finance'), icon: <IconChart />, show: can('finance.view'), focus: ['finance'] as Focus[] },
        ].filter((item) => item.show),
    ).slice(0, 6);

    const setupIncomplete = checklist.some((key) => !onboarding[key]);

    const tasksCard = attention.length > 0 && (
        <section aria-labelledby="dash-attention" className="surface-glass flex flex-col p-4 sm:p-5">
            <div className="mb-3.5">
                <PanelTitle
                    id="dash-attention"
                    icon={<IconBell />}
                    tone="amber"
                    title={t('dash.alertsTasks')}
                    aside={
                        pending.length > 0 ? (
                            <span className="grid min-w-7 place-items-center rounded-md bg-amber-soft px-1.5 py-0.5 text-[13px] font-semibold text-amber">
                                {pending.length}
                                <span className="sr-only"> {t('dash.pending')}</span>
                            </span>
                        ) : undefined
                    }
                />
            </div>
            {pending.length === 0 ? (
                <p className="flex items-center gap-2 rounded-[10px] bg-success-soft px-3 py-2.5 text-sm font-semibold text-success">
                    <IconCheck />
                    {t('dash.allClear')}
                </p>
            ) : (
                <ul className="divide-y divide-line">
                    {pending.map((item) => (
                        <li key={item.key}>
                            <Link href={item.href} className="group -mx-2 flex min-h-13 items-center gap-3 rounded-xl px-2 py-2 transition-soft hover:bg-sage">
                                <span aria-hidden="true" className={`grid size-9 shrink-0 place-items-center rounded-[10px] [&_svg]:size-[18px] ${tints[item.tint]}`}>
                                    {item.icon}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-semibold text-ink">{item.label}</span>
                                    <span className="text-[13px] text-ink-muted">{t('dash.pending')}</span>
                                </span>
                                <span className="metric-value text-base font-bold text-ink">{item.display}</span>
                                <svg aria-hidden="true" className="directional-icon shrink-0 text-ink-faint rtl:rotate-180" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
                                    <path d="m9 6 6 6-6 6" />
                                </svg>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            {cleared.length > 0 && (
                <div className="mt-auto border-t border-line pt-3">
                    <p className="mb-2 text-[13px] font-medium text-ink-muted">{t('dash.upToDate')} :</p>
                    <ul className="flex flex-wrap gap-1.5">
                        {cleared.map((item) => (
                            <li key={item.key}>
                                <Link
                                    href={item.href}
                                    className="inline-flex min-h-8 items-center gap-1.5 rounded-[9px] border border-line bg-raised px-2.5 text-[13px] font-medium text-ink transition-soft hover:border-line-strong hover:bg-surface [&_svg]:size-3.5 [&_svg]:text-success"
                                >
                                    <IconCheck />
                                    {item.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );

    const quickActionsCard = (quickActions.length > 0 || shortcuts.length > 0) && (
        <section aria-labelledby="dash-actions" className="surface-glass glass-soft p-4 sm:p-5">
            <div className="mb-3.5">
                <PanelTitle id="dash-actions" icon={<IconBolt />} title={t('dash.quickActions')} />
            </div>
            {quickActions.length > 0 && (
                <ul className="grid grid-cols-2 gap-2 min-[520px]:grid-cols-3 lg:grid-cols-5">
                    {quickActions.map((action) => (
                        <li key={action.href}>
                            <Link
                                href={action.href}
                                className={`flex h-full min-h-[4.75rem] flex-col items-center justify-center gap-2 rounded-xl px-2 py-3 text-center text-[13px] leading-tight font-semibold text-ink transition-soft [&_svg]:size-5 ${actionTiles[action.tint]}`}
                            >
                                <span aria-hidden="true">{action.icon}</span>
                                {action.label}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            {shortcuts.length > 0 && (
                <div className={quickActions.length > 0 ? 'mt-3.5 border-t border-line pt-3' : ''}>
                    <p className="mb-2 text-[13px] font-medium text-ink-muted">{t('dash.shortcuts')}</p>
                    <ul className="flex flex-wrap gap-1.5">
                        {shortcuts.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    className="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-line bg-surface px-2.5 text-[13px] font-medium text-ink transition-soft hover:border-line-strong hover:bg-raised [&_svg]:size-[15px]"
                                >
                                    <span aria-hidden="true" className="text-ink-muted">
                                        {item.icon}
                                    </span>
                                    {item.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );

    return (
        <div className="space-y-3.5">
            <header className="flex flex-wrap items-center justify-between gap-4 pb-0.5">
                <div className="min-w-0">
                    <p className="text-[13px] text-ink-muted">
                        {t('dash.hello')} <span className="font-semibold text-ink">{firstName}</span>
                    </p>
                    <h1 className="mt-0.5 text-[1.75rem] leading-[1.1] font-bold tracking-[-0.04em] text-ink sm:text-[2.125rem]">{t('nav.dashboard')}</h1>
                    <p className="mt-1 text-[13px] text-ink-muted">
                        {t('dash.subtitle')}
                        <span className="text-ink-faint">
                            {' '}
                            · {tenant.organization?.name}
                            {tenant.store ? ` · ${tenant.store.name}` : ''}
                        </span>
                    </p>
                </div>
                {executiveDashboard && (
                    <label className="glass-pill flex min-h-10 items-center gap-2 rounded-full ps-3.5 pe-2.5 text-sm font-semibold text-ink focus-within:ring-2 focus-within:ring-primary/30">
                        <svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" className="text-ink-muted">
                            <rect x="3" y="5" width="18" height="16" rx="2.5" />
                            <path d="M8 3v4M16 3v4M3 10h18" />
                        </svg>
                        <span className="sr-only">{t('common.period')}</span>
                        <input
                            type="month"
                            value={executiveDashboard.period.month}
                            onChange={(event) =>
                                event.target.value && router.get('/platform', { month: event.target.value }, { preserveState: true, preserveScroll: true })
                            }
                            className="h-9 border-0 bg-transparent px-1 text-sm font-semibold text-ink focus:outline-none"
                        />
                    </label>
                )}
            </header>

            {/* 1 — Key figures */}
            {kpis.length > 0 && (
                <section aria-label={t('dash.keyFigures')} className={`grid gap-3 sm:grid-cols-2 ${kpis.length >= 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3'}`}>
                    {kpis.map((kpi) => (
                        <KpiCard {...kpi} key={kpi.key} />
                    ))}
                </section>
            )}

            {/* 2-4 — Executive: trend, tasks, order states, actions, ranking, secondary.
                Approved reference: three tracks 1.7 : 1 : 1 from 1360px (trend | tasks | order states,
                then actions | top products | indicators); two tracks from lg; one below. */}
            {executiveDashboard ? (
                <div className="grid gap-3 lg:grid-cols-[minmax(0,1.5fr)_minmax(260px,1fr)] dash:grid-cols-[minmax(0,1.7fr)_minmax(280px,1fr)_minmax(275px,1fr)]">
                    <SalesCollectionsChart compact points={executiveDashboard.charts.sales_vs_collections} />
                    {tasksCard}
                    {/* 2 tracks: actions | order states. 3 tracks: order states joins row 1. */}
                    {quickActionsCard && <div className="grid min-w-0 dash:order-1">{quickActionsCard}</div>}
                    <OrderStatesChart compact states={executiveDashboard.charts.order_states} />
                    <div className="grid min-w-0 dash:order-1">
                        <RankedMoneyChart
                            compact
                            title={t('platform.topProducts')}
                            description={t('platform.topProductsHelp')}
                            points={executiveDashboard.charts.top_products}
                            emptyLabel={t('platform.noProductSales')}
                            emptyHelp={t('dashboardCharts.productsEmptyHelp')}
                        />
                    </div>
                    <section aria-labelledby="dash-secondary" className="surface-glass glass-soft p-4 sm:p-5 dash:order-1">
                        <PanelTitle id="dash-secondary" icon={<IconBarChart />} title={t('dash.moreIndicators')} />
                        <dl className="mt-2.5 divide-y divide-line">
                            <MetricRow label={t('platform.averageBasket')} value={formatMoney(executiveDashboard.secondary.average_basket)} />
                            <MetricRow label={t('platform.partiallyPaid')} value={formatInteger(executiveDashboard.secondary.partial_orders)} />
                            <MetricRow label={t('platform.unpaid')} value={formatInteger(executiveDashboard.secondary.unpaid_orders)} />
                            <MetricRow label={t('platform.refunds')} value={formatMoney(executiveDashboard.secondary.refunds)} />
                            <MetricRow
                                label={t('finance.sales.receivedReturns')}
                                value={`${formatInteger(executiveDashboard.secondary.returns.count)} · ${formatMoney(executiveDashboard.secondary.returns.amount)}`}
                            />
                            <MetricRow label={t('platform.itemsToCatalog')} value={formatInteger(executiveDashboard.secondary.out_of_stock)} />
                        </dl>
                    </section>
                </div>
            ) : (
                <div className={`grid gap-3 ${tasksCard ? 'lg:grid-cols-[minmax(0,1.6fr)_minmax(280px,1fr)] dash:grid-cols-[minmax(0,2.1fr)_minmax(320px,1fr)]' : ''}`}>
                    {quickActionsCard}
                    {tasksCard}
                </div>
            )}

            {/* 5 — Setup progress, only while something is left to do */}
            {setupIncomplete && (
                <section aria-labelledby="dash-setup" className="grid gap-3 lg:grid-cols-[1.1fr_1fr]">
                    <div className="surface-glass glass-soft p-4 sm:p-5">
                        <PanelTitle id="dash-setup" icon={<IconLayers />} title={t('platform.getStarted')} description={t('platform.getStartedHelp')} />
                        <ol className="mt-3.5 space-y-2">
                            {checklist.map((key) => (
                                <li key={key} className="flex items-center gap-3 text-sm">
                                    <span
                                        aria-hidden="true"
                                        className={`grid size-7 shrink-0 place-items-center rounded-full text-xs font-semibold ${onboarding[key] ? 'bg-mint-soft text-mint' : 'border border-line-strong text-ink-muted'}`}
                                    >
                                        {onboarding[key] ? '✓' : ''}
                                    </span>
                                    <span className={onboarding[key] ? 'text-ink-muted line-through' : 'font-medium text-ink'}>
                                        {t(`platform.checklist.${key}` as Parameters<typeof t>[0])}
                                    </span>
                                    <span className="sr-only">{onboarding[key] ? t('dash.upToDate') : t('dash.pending')}</span>
                                </li>
                            ))}
                        </ol>
                    </div>
                    <div className="surface-glass glass-soft p-4 sm:p-5">
                        <PanelTitle as="h3" icon={<IconPlay />} title={t('platform.nextAction')} description={t('platform.nextActionHelp')} />
                        <div className="mt-3.5 grid gap-2">
                            {can('products.import') && (
                                <ButtonLink href="/catalog/products/import" className="btn-gradient min-h-12 rounded-[11px] text-[15px]">
                                    {t('imports.products.title')}
                                    <svg aria-hidden="true" className="directional-icon rtl:rotate-180" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M5 12h14M13 6l6 6-6 6" />
                                    </svg>
                                </ButtonLink>
                            )}
                            {can('products.create') && (
                                <ButtonLink href="/catalog/products/create" variant="secondary">
                                    {t('platform.addProduct')}
                                </ButtonLink>
                            )}
                            {can('inventory.opening') && (
                                <ButtonLink href="/inventory/stock" variant="secondary">
                                    {t('platform.enterOpeningStock')}
                                </ButtonLink>
                            )}
                            {hasStore && can('pos.access') && (
                                <ButtonLink href="/pos" variant="secondary">
                                    {t('platform.openPos')}
                                </ButtonLink>
                            )}
                        </div>
                    </div>
                </section>
            )}
        </div>
    );
}

/** Splits "22 524,00 DH" into figure + unit so the unit can be set smaller. */
function splitUnit(value: string): { figure: string; unit: string | null } {
    const match = value.match(/^(.*\d)\s*(DH|MAD|EUR|€|\$|USD)$/u);
    return match ? { figure: match[1]!, unit: match[2]! } : { figure: value, unit: null };
}

function KpiCard({ label, value, href, icon, tint, variation, series }: { label: string; value: string; href: string; icon: ReactNode; tint: Tint; variation?: string | null; series?: number[] }) {
    const { t } = useI18n();
    const numeric = variation == null ? null : Number(variation);
    // A change chip only when the backend provides a real comparison; a trend only with a real, non-empty series.
    const hasDelta = numeric !== null && !Number.isNaN(numeric);
    const hasSeries = !!series && series.some((point) => point > 0);
    const { figure, unit } = splitUnit(value);
    return (
        <Link
            href={href}
            className="group surface-glass glass-strong relative isolate flex min-h-32 flex-col overflow-hidden px-[18px] py-4 transition-soft hover:-translate-y-px"
        >
            {/* Decorative light in the trailing corner (not data). */}
            <span
                aria-hidden="true"
                className="pointer-events-none absolute -end-4 -bottom-8 -z-10 h-28 w-[58%] rtl:scale-x-[-1]"
                style={{ background: `radial-gradient(ellipse at right bottom, ${washes[tint]}, transparent 70%)` }}
            />
            <div className="flex items-center gap-4">
                <span aria-hidden="true" className={`grid size-[46px] shrink-0 place-items-center rounded-[14px] shadow-[0_4px_10px_rgb(0_114_103/0.08)] [&_svg]:size-[22px] ${tints[tint]}`}>
                    {icon}
                </span>
                <p className="min-w-0 truncate text-[15px] font-semibold text-ink">{label}</p>
            </div>
            {/* Indent follows the page direction; the figure itself stays LTR ("21 096,00 DH"). */}
            <div className="-mt-3 ps-[62px]">
                <p className="metric-value flex min-w-0 items-baseline gap-1.5 text-ink rtl:justify-end" dir="ltr">
                    <span className="truncate text-[1.75rem] leading-tight font-bold tracking-[-0.04em] sm:text-[1.875rem]">{figure}</span>
                    {unit && <span className="shrink-0 text-[15px] font-medium text-ink-muted">{unit}</span>}
                </p>
            </div>
            {hasDelta && numeric !== null && (
                <p className="mt-auto flex flex-wrap items-center gap-x-1.5 gap-y-1 ps-[62px] pt-2 text-xs">
                    <span className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-semibold ${numeric >= 0 ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'}`}>
                        <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round">
                            <path d={numeric >= 0 ? 'M7 17 17 7M9 7h8v8' : 'M7 7l10 10M17 9v8H9'} />
                        </svg>
                        {numeric >= 0 ? '+' : ''}
                        {numeric.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %
                    </span>
                    <span className="text-ink-muted">{t('platform.vsPreviousMonth')}</span>
                </p>
            )}
            {hasSeries && series && (
                <div aria-hidden={false} className="pointer-events-none absolute end-0 bottom-0 -z-10 w-[44%] opacity-90 rtl:scale-x-[-1]">
                    <Sparkline values={series} color={strokes[tint]} />
                </div>
            )}
        </Link>
    );
}

/** Trend line from a REAL backend series (daily values of the selected period); spans the card width. */
function Sparkline({ values, color }: { values: number[]; color: string }) {
    const { t } = useI18n();
    const gradientId = useId();
    const width = 240;
    const height = 44;
    const max = Math.max(...values, 1);
    const step = values.length > 1 ? width / (values.length - 1) : width;
    const points = values.map((value, index) => [index * step, height - 3 - (value / max) * (height - 8)] as const);
    const line = points.map(([x, y], index) => `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
    const area = `${line} L${width},${height} L0,${height} Z`;
    return (
        <svg width="100%" height={height} viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" role="img" aria-label={t('dash.trendLabel')} className="block overflow-visible">
            <defs>
                <linearGradient id={gradientId} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stopColor={color} stopOpacity="0.2" />
                    <stop offset="100%" stopColor={color} stopOpacity="0" />
                </linearGradient>
            </defs>
            <path d={area} fill={`url(#${gradientId})`} />
            <path d={line} fill="none" stroke={color} strokeWidth="2" vectorEffect="non-scaling-stroke" strokeLinejoin="round" strokeLinecap="round" />
        </svg>
    );
}

function MetricRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-[7px]">
            <dt className="text-[13px] text-ink-muted">{label}</dt>
            <dd className="metric-value text-[13px] font-bold text-ink" dir="ltr">{value}</dd>
        </div>
    );
}

const ic = { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };
function IconBell() {
    return (
        <svg {...ic}>
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
        </svg>
    );
}
function IconBolt() {
    return (
        <svg {...ic}>
            <path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z" />
        </svg>
    );
}
function IconBarChart() {
    return (
        <svg {...ic}>
            <path d="M4 20h16M7 16v-4M12 16V7M17 16v-6" />
        </svg>
    );
}
function IconLayers() {
    return (
        <svg {...ic}>
            <path d="m12 3 9 5-9 5-9-5 9-5Z" />
            <path d="m3 13 9 5 9-5" />
        </svg>
    );
}
function IconPlay() {
    return (
        <svg {...ic}>
            <path d="M7 4.5v15l12-7.5-12-7.5Z" />
        </svg>
    );
}
function IconCheck() {
    return (
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
            <path d="m5 12.5 4.5 4.5L19 7.5" />
        </svg>
    );
}
