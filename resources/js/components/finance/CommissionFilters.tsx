import { Button } from '@/components/ui/Button';
import { useI18n } from '@/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';

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
 * Server-side commission filters. Presets resolve on the server; "Personnalisé"
 * exposes the explicit date range. Dates always filter by the commission
 * entry's occurrence date (payout-period basis).
 */
export default function CommissionFilters({ path, filters, stores, salespeople, extra }: Props) {
    const { t } = useI18n();
    const { statuses: statusLabels, types: typeLabels } = useCommissionLabels();
    const periods = [
        { value: 'today', label: t('commissions.today') }, { value: 'this_week', label: t('commissions.thisWeek') },
        { value: 'this_month', label: t('commissions.thisMonth') }, { value: 'last_month', label: t('commissions.lastMonth') },
        { value: 'this_year', label: t('commissions.thisYear') }, { value: 'custom', label: t('commissions.custom') },
    ];
    const [local, setLocal] = useState({
        period: filters.period,
        from: filters.from,
        to: filters.to,
        store_id: filters.store_id ? String(filters.store_id) : '',
        salesperson: filters.salesperson ?? '',
        status: filters.status ?? '',
        entry_type: filters.entry_type ?? '',
    });

    const submit = (next = local) => {
        const query: Record<string, string> = { ...(extra ?? {}), period: next.period };
        if (next.period === 'custom') {
            query.from = next.from;
            query.to = next.to;
        }
        for (const key of ['store_id', 'salesperson', 'status', 'entry_type'] as const) {
            if (next[key]) query[key] = next[key];
        }
        router.get(path, query, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <section className="mb-5 rounded-card border border-line bg-surface p-4">
            <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('commissions.period')}>
                {periods.map((period) => (
                    <button
                        key={period.value}
                        type="button"
                        aria-pressed={local.period === period.value}
                        onClick={() => {
                            const next = { ...local, period: period.value };
                            setLocal(next);
                            if (period.value !== 'custom') submit(next);
                        }}
                        className={`rounded-full px-3 py-1.5 text-xs font-medium ${local.period === period.value ? 'bg-primary text-primary-fg' : 'bg-raised text-ink-muted hover:text-ink'}`}
                    >
                        {period.label}
                    </button>
                ))}
            </div>
            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <Field label={t('activity.from')}>
                    <input type="date" className={input} value={local.from} disabled={local.period !== 'custom'} onChange={(e) => setLocal({ ...local, from: e.target.value })} />
                </Field>
                <Field label={t('activity.to')}>
                    <input type="date" className={input} value={local.to} disabled={local.period !== 'custom'} onChange={(e) => setLocal({ ...local, to: e.target.value })} />
                </Field>
                {stores && (
                    <Field label={t('activity.store')}>
                        <select className={input} value={local.store_id} onChange={(e) => setLocal({ ...local, store_id: e.target.value })}>
                            <option value="">{t('activity.all')}</option>
                            {stores.map((store) => <option key={store.id} value={store.id}>{store.name}</option>)}
                        </select>
                    </Field>
                )}
                {salespeople && (
                    <Field label={t('commissions.salesperson')}>
                        <select className={input} value={local.salesperson} onChange={(e) => setLocal({ ...local, salesperson: e.target.value })}>
                            <option value="">{t('activity.all')}</option><option value="unattributed">{t('commissions.unattributed')}</option>
                            {salespeople.map((person) => <option key={person.id} value={person.id}>{person.name ?? `#${person.id}`}</option>)}
                        </select>
                    </Field>
                )}
                <Field label={t('common.status')}>
                    <select className={input} value={local.status} onChange={(e) => setLocal({ ...local, status: e.target.value })}>
                        <option value="">{t('activity.all')}</option>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                </Field>
                <Field label={t('commissions.type')}>
                    <select className={input} value={local.entry_type} onChange={(e) => setLocal({ ...local, entry_type: e.target.value })}>
                        <option value="">{t('activity.all')}</option>{Object.entries(typeLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                </Field>
            </div>
            <div className="mt-3 flex items-center justify-between gap-3">
                <p className="text-xs text-ink-faint">{t('commissions.appliedPeriod')} : {t('activity.from').toLocaleLowerCase()} <bdi>{filters.from}</bdi> {t('activity.to').toLocaleLowerCase()} <bdi>{filters.to}</bdi> · {t('commissions.entryDateHelp')}</p>
                <Button size="sm" variant="secondary" onClick={() => submit()}>{t('commissions.apply')}</Button>
            </div>
        </section>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return <label className="text-xs font-medium text-ink-muted">{label}<div className="mt-1">{children}</div></label>;
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

const input = 'w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none disabled:opacity-60';
