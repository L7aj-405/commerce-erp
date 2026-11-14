import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { AdvancedFilterBar, ChoiceFilter } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import MonthPicker, { currentMonthValue } from '@/components/ui/MonthPicker';
import { useI18n } from '@/i18n';

export type FinanceStore = { id: number; name: string; code: string };

type Props = {
    path: string;
    period: string;
    storeId: number | null;
    stores: FinanceStore[];
    extra?: Record<string, string | number | undefined>;
    /** Page-level actions (exports…) rendered at the end of the bar. */
    actions?: ReactNode;
};

/** Localised "novembre 2026" / Arabic month label for the period chip. */
const monthLabel = (month: string, intlLocale: string) => {
    const [year, m] = month.split('-').map(Number);
    if (!year || !m) return month;
    return new Intl.DateTimeFormat(intlLocale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(year, m - 1, 1)));
};

/**
 * Shared month + store filter bar for every Finance page. The backend accepts only
 * `month` (Y-m, defaults to the current month) and `store_id` (all stores when empty);
 * `extra` carries page-specific params unchanged. Navigating keeps the user's global
 * active store untouched — this is Finance's own, independent filter.
 */
export default function FinanceFilters({ path, period, storeId, stores, extra, actions }: Props) {
    const { t, locale } = useI18n();
    const [loading, setLoading] = useState(false);
    useEffect(() => () => setLoading(false), []);

    const go = (next: { month?: string; store_id?: string }) => {
        router.get(
            path,
            {
                month: next.month ?? period,
                store_id: next.store_id ?? (storeId ? String(storeId) : ''),
                ...extra,
            },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    };

    const store = stores.find((s) => s.id === storeId);
    const current = currentMonthValue();
    const chips: FilterChip[] = [];
    if (period !== current) {
        chips.push({ key: 'month', label: t('common.period'), value: monthLabel(period, locale === 'ar' ? 'ar-MA' : 'fr-FR'), onRemove: () => go({ month: current }) });
    }
    if (storeId) {
        chips.push({ key: 'store', label: t('common.store'), value: store ? `${store.name} · ${store.code}` : String(storeId), onRemove: () => go({ store_id: '' }) });
    }

    return (
        <div className="mb-6">
            <AdvancedFilterBar
                label={t('filters.title')}
                chips={chips}
                activeCount={chips.length}
                loading={loading}
                onReset={() => go({ month: current, store_id: '' })}
                actions={actions}
            >
                <MonthPicker label={t('common.period')} value={period} onChange={(month) => month && go({ month })} />
                <ChoiceFilter
                    label={t('common.store')}
                    allLabel={t('common.allStores')}
                    searchable={stores.length > 8}
                    options={stores.map((s) => ({ value: String(s.id), label: `${s.name} · ${s.code}` }))}
                    value={storeId ? String(storeId) : undefined}
                    onChange={(value) => go({ store_id: value ?? '' })}
                />
            </AdvancedFilterBar>
        </div>
    );
}
