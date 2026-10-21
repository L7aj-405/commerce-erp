import { router } from '@inertiajs/react';
import { useI18n } from '@/i18n';

export type FinanceStore = { id: number; name: string; code: string };

type Props = {
    path: string;
    period: string;
    storeId: number | null;
    stores: FinanceStore[];
    extra?: Record<string, string | number | undefined>;
};

/** Shared month + store filter bar for every Finance page. Navigating keeps the user's global active store untouched — this is Finance's own, independent filter. */
export default function FinanceFilters({ path, period, storeId, stores, extra }: Props) {
    const { t } = useI18n();
    const go = (next: { month?: string; store_id?: string }) => {
        router.get(
            path,
            {
                month: next.month ?? period,
                store_id: next.store_id ?? (storeId ? String(storeId) : ''),
                ...extra,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <div className="mb-6 flex flex-wrap items-end gap-3 rounded-card border border-line bg-surface p-4">
            <label className="block">
                <span className="mb-1.5 block text-[13px] font-medium text-ink">{t('common.period')}</span>
                <input
                    type="month"
                    value={period}
                    onChange={(e) => e.target.value && go({ month: e.target.value })}
                    className="control-input h-10"
                />
            </label>
            <label className="block">
                <span className="mb-1.5 block text-[13px] font-medium text-ink">{t('common.store')}</span>
                <select
                    value={storeId ?? ''}
                    onChange={(e) => go({ store_id: e.target.value })}
                    className="control-select h-10 min-w-56"
                >
                    <option value="">{t('common.allStores')}</option>
                    {stores.map((store) => (
                        <option key={store.id} value={store.id}>
                            {store.name} · {store.code}
                        </option>
                    ))}
                </select>
            </label>
        </div>
    );
}
