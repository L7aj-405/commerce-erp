import { AdvancedFilterBar, ChoiceFilter, DateRangeFilter } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { useI18n } from '@/i18n';
import { formatDate } from '@/utils/format';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export type CommissionFilterState = {
    period: string;
    from: string;
    to: string;
    store_id?: number | null;
    salesperson?: string | null;
    status: string | null;
    entry_type: string | null;
};

export function useCommissionLabels() {
    const { t } = useI18n();
    return {
        statuses: { pending: t('commissions.pending'), approved: t('commissions.approved'), paid: t('commissions.paid'), no_commission: t('commissions.noCommission') } as Record<string, string>,
        types: { sale: t('commissions.sale'), return_reversal: t('commissions.return'), correction: t('commissions.correction'), cancellation: t('commissions.cancellation'), manual_adjustment: t('commissions.adjustment') } as Record<string, string>,
    };
}

type Props = {
    path: string;
    filters: CommissionFilterState;
    stores?: { id: number; name: string; code: string }[];
    salespeople?: { id: number; name: string | null }[];
    /** Extra query parameters to preserve (e.g. sorting). */
    extra?: Record<string, string>;
};

/**
 * Server-side commission filters on the shared AdvancedFilterBar. Every change
 * navigates immediately with the same query parameters as before. Presets resolve on
 * the server; "Personnalisé" reveals the date range. `this_month` is the server
 * default, so it is the bar's unset state and what "Réinitialiser" returns to.
 * Dates always filter by the commission entry's occurrence date (payout-period basis).
 */
export default function CommissionFilters({ path, filters, stores, salespeople, extra }: Props) {
    const { t } = useI18n();
    const { statuses: statusLabels, types: typeLabels } = useCommissionLabels();
    const [loading, setLoading] = useState(false);
    const periodLabels: Record<string, string> = {
        today: t('commissions.today'),
        this_week: t('commissions.thisWeek'),
        this_month: t('commissions.thisMonth'),
        last_month: t('commissions.lastMonth'),
        this_year: t('commissions.thisYear'),
        custom: t('commissions.custom'),
    };
    const applied = {
        period: filters.period,
        from: filters.from,
        to: filters.to,
        store_id: filters.store_id ? String(filters.store_id) : '',
        salesperson: filters.salesperson ?? '',
        status: filters.status ?? '',
        entry_type: filters.entry_type ?? '',
    };

    const go = (patch: Partial<typeof applied>) => {
        const next = { ...applied, ...patch };
        const query: Record<string, string> = { ...(extra ?? {}), period: next.period };
        if (next.period === 'custom') {
            query.from = next.from;
            query.to = next.to;
        }
        for (const key of ['store_id', 'salesperson', 'status', 'entry_type'] as const) {
            if (next[key]) query[key] = next[key];
        }
        router.get(path, query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };

    const storeName = (id: string) => stores?.find((store) => String(store.id) === id)?.name ?? id;
    const personName = (id: string) =>
        id === 'unattributed' ? t('commissions.unattributed') : (salespeople?.find((person) => String(person.id) === id)?.name ?? `#${id}`);
    const options = (labels: Record<string, string>) => Object.entries(labels).map(([value, label]) => ({ value, label }));

    const chips: FilterChip[] = [];
    if (applied.period !== 'this_month') {
        chips.push({ key: 'period', label: t('commissions.period'), value: periodLabels[applied.period] ?? applied.period, onRemove: () => go({ period: 'this_month' }) });
    }
    if (stores && applied.store_id) chips.push({ key: 'store', label: t('activity.store'), value: storeName(applied.store_id), onRemove: () => go({ store_id: '' }) });
    if (salespeople && applied.salesperson) {
        chips.push({ key: 'salesperson', label: t('commissions.salesperson'), value: personName(applied.salesperson), onRemove: () => go({ salesperson: '' }) });
    }
    if (applied.status) chips.push({ key: 'status', label: t('common.status'), value: statusLabels[applied.status] ?? applied.status, onRemove: () => go({ status: '' }) });
    if (applied.entry_type) {
        chips.push({ key: 'type', label: t('commissions.type'), value: typeLabels[applied.entry_type] ?? applied.entry_type, onRemove: () => go({ entry_type: '' }) });
    }

    return (
        <div className="mb-5">
            <AdvancedFilterBar
                label={t('commissions.period')}
                chips={chips}
                activeCount={chips.length}
                loading={loading}
                onReset={() => go({ period: 'this_month', store_id: '', salesperson: '', status: '', entry_type: '' })}
            >
                {/* Every period is an explicit option; clearing returns to the server default. */}
                <ChoiceFilter
                    label={t('commissions.period')}
                    options={options(periodLabels)}
                    value={applied.period}
                    onChange={(value) => go({ period: value ?? 'this_month' })}
                />
                {applied.period === 'custom' && (
                    <DateRangeFilter
                        label={t('commissions.custom')}
                        from={applied.from}
                        to={applied.to}
                        onChange={(range) => go({ period: 'custom', from: range.from ?? '', to: range.to ?? '' })}
                    />
                )}
                {stores && (
                    <ChoiceFilter
                        label={t('activity.store')}
                        options={stores.map((store) => ({ value: String(store.id), label: store.name }))}
                        value={applied.store_id || undefined}
                        onChange={(value) => go({ store_id: value ?? '' })}
                    />
                )}
                {salespeople && (
                    <ChoiceFilter
                        label={t('commissions.salesperson')}
                        options={[
                            { value: 'unattributed', label: t('commissions.unattributed') },
                            ...salespeople.map((person) => ({ value: String(person.id), label: person.name ?? `#${person.id}` })),
                        ]}
                        value={applied.salesperson || undefined}
                        onChange={(value) => go({ salesperson: value ?? '' })}
                    />
                )}
                <ChoiceFilter label={t('common.status')} options={options(statusLabels)} value={applied.status || undefined} onChange={(value) => go({ status: value ?? '' })} />
                <ChoiceFilter label={t('commissions.type')} options={options(typeLabels)} value={applied.entry_type || undefined} onChange={(value) => go({ entry_type: value ?? '' })} />
            </AdvancedFilterBar>
            <p className="mt-2 px-1 text-xs text-ink-muted">
                {t('commissions.appliedPeriod')} : {t('activity.from').toLocaleLowerCase()} <bdi>{formatDate(filters.from)}</bdi> {t('activity.to').toLocaleLowerCase()}{' '}
                <bdi>{formatDate(filters.to)}</bdi> · {t('commissions.entryDateHelp')}
            </p>
        </div>
    );
}

/** Build a query string from the applied (server-resolved) filters, e.g. for export links. */
export function filterQuery(filters: CommissionFilterState, extra: Record<string, string> = {}): string {
    const params = new URLSearchParams({ period: filters.period, ...extra });
    if (filters.period === 'custom') {
        params.set('from', filters.from);
        params.set('to', filters.to);
    }
    if (filters.store_id) params.set('store_id', String(filters.store_id));
    if (filters.salesperson) params.set('salesperson', filters.salesperson);
    if (filters.status) params.set('status', filters.status);
    if (filters.entry_type) params.set('entry_type', filters.entry_type);
    return params.toString();
}
