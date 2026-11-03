import { useFreshAuth } from '@/components/auth/FreshAuthProvider';
import { Button } from '@/components/ui/Button';
import FilterSelect from '@/components/ui/FilterSelect';
import SearchInput from '@/components/ui/SearchInput';
import { Spinner } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/toast';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import { formatDate, formatInteger } from '@/utils/format';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useMemo, useRef, useState } from 'react';

export type ResetCategory = {
    key: string;
    module: string;
    roots: { table: string; count: number; filters: { status: boolean; warehouse: boolean } }[];
};

type RecordRow = { id: number; reference: string; date: string | null; status: string | null; party: string | null; warehouses: string[] };
type RecordFilters = { search: string; status: string; warehouse_id: string };
/** Per root table: every row of the category (`all`), the rows matching filters (`filtered`) or picked ids (`ids`). */
type RootChoice = { mode: 'all' } | { mode: 'filtered'; filters: RecordFilters; total: number } | { mode: 'ids'; ids: number[] };

type Blocker = { code: string; table?: string; column?: string; parent_table?: string; category?: string | null; count: number; samples?: string[]; limit?: number };
type SelectivePreview = {
    fingerprint: string;
    previewed_at: string;
    executable: boolean;
    selected: Record<string, number>;
    selected_total: number;
    delete_total: number;
    unlink_total: number;
    released_reservations: number;
    breakdown: Record<string, { delete: Record<string, number>; unlink: Record<string, number> }>;
    samples: Record<string, string[]>;
    blockers: Blocker[];
    protected: Record<string, number>;
};

const MODULE_ORDER = ['sales', 'documents', 'finance', 'purchasing', 'logistics', 'history', 'activity'];
const emptyFilters: RecordFilters = { search: '', status: '', warehouse_id: '' };

/** Human label of a table — never the raw database name. */
function useLabels() {
    const { t } = useI18n();
    const label = (prefix: string, key: string, fallback: TranslationKey) => {
        const value = t(`${prefix}.${key}` as TranslationKey) as string | undefined;
        return value && value !== `${prefix}.${key}` ? value : t(fallback);
    };
    return {
        table: (table: string) => label('dataReset.table', table, 'dataReset.table.other'),
        category: (key: string) => label('dataReset.category', key, 'dataReset.table.other'),
        module: (key: string) => label('dataReset.module', key, 'dataReset.table.other'),
    };
}

export default function SelectiveReset({
    organization,
    categories,
    warehouses,
    confirmationPhrase,
}: {
    organization: { id: number; name: string };
    categories: ResetCategory[];
    warehouses: { id: number; name: string }[];
    confirmationPhrase: string;
}) {
    const { t } = useI18n();
    const labels = useLabels();
    const { request } = useFreshAuth();
    const toast = useToast();
    const [selected, setSelected] = useState<string[]>([]);
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [choices, setChoices] = useState<Record<string, RootChoice>>({});
    const [picker, setPicker] = useState<string | null>(null);
    const [preview, setPreview] = useState<SelectivePreview | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [error, setError] = useState('');
    const [orgName, setOrgName] = useState('');
    const [phrase, setPhrase] = useState('');
    const [total, setTotal] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [executing, setExecuting] = useState(false);
    const [done, setDone] = useState<{ deleted: number; unlinked: number; released: number } | null>(null);
    const inFlight = useRef(false);

    const byModule = useMemo(() => {
        const groups: Record<string, ResetCategory[]> = {};
        categories.forEach((c) => (groups[c.module] ??= []).push(c));
        return MODULE_ORDER.filter((m) => groups[m]).map((m) => [m, groups[m]] as const);
    }, [categories]);
    const selectedRoots = categories.filter((c) => selected.includes(c.key)).flatMap((c) => c.roots);

    // Any change of the selection invalidates the preview: it must be recomputed.
    useEffect(() => {
        setPreview(null);
        setErrors({});
    }, [selected, dateFrom, dateTo, choices]);

    const toggle = (key: string) => setSelected((current) => (current.includes(key) ? current.filter((k) => k !== key) : [...current, key]));

    const selection = () => {
        const records: Record<string, number[]> = {};
        const filters: Record<string, Record<string, string | number>> = {};
        selectedRoots.forEach((root) => {
            const choice = choices[root.table];
            if (choice?.mode === 'ids') records[root.table] = choice.ids;
            if (choice?.mode === 'filtered') {
                filters[root.table] = Object.fromEntries(Object.entries(choice.filters).filter(([, v]) => v !== '').map(([k, v]) => [k, k === 'warehouse_id' ? Number(v) : v]));
            }
        });
        return { categories: selected, date_from: dateFrom || null, date_to: dateTo || null, records, filters };
    };

    const analyse = async () => {
        if (selected.length === 0) return;
        setPreviewing(true);
        setError('');
        setDone(null);
        try {
            const response = await request<{ data: SelectivePreview }>({ method: 'post', url: '/settings/data-reset/selective/preview', data: { selection: selection() } });
            setPreview(response.data.data);
        } catch (e) {
            const payload = axios.isAxiosError(e) ? (e.response?.data as { message?: string } | undefined) : undefined;
            setError(payload?.message ?? t('dataReset.previewError'));
        } finally {
            setPreviewing(false);
        }
    };

    const execute = async () => {
        if (!preview || inFlight.current) return;
        const next: Record<string, string> = {};
        if (orgName !== organization.name) next.organization_name = t('dataReset.confirmMismatch');
        if (phrase !== confirmationPhrase) next.confirmation_phrase = t('dataReset.confirmMismatch');
        if (Number(total) !== preview.delete_total) next.confirmed_total = t('dataReset.sel.totalMismatch');
        if (Object.keys(next).length > 0) {
            setErrors(next);
            return;
        }
        inFlight.current = true;
        setExecuting(true);
        try {
            const response = await request<{ data: { deleted_records: number; unlinked_records: number; released_reservations: number } }>({
                method: 'post',
                url: '/settings/data-reset/selective/execute',
                data: { selection: selection(), fingerprint: preview.fingerprint, confirmation_phrase: phrase, organization_name: orgName, confirmed_total: Number(total) },
            });
            setDone({ deleted: response.data.data.deleted_records, unlinked: response.data.data.unlinked_records, released: response.data.data.released_reservations });
            toast.success(t('dataReset.success'));
        } catch (e) {
            if (axios.isCancel(e)) return;
            const payload = axios.isAxiosError(e) ? (e.response?.data as { code?: string; reference?: string | null; errors?: Record<string, string[]> } | undefined) : undefined;
            if (axios.isAxiosError(e) && e.response?.status === 422 && payload?.errors) {
                setErrors(Object.fromEntries(Object.entries(payload.errors).map(([k, v]) => [k, v[0]])));
            } else if (payload?.code === 'STALE_RESET_PREVIEW' || payload?.code === 'RESET_BLOCKED') {
                setPreview(null);
                setError(t('dataReset.stale'));
            } else {
                setError(payload?.reference ? t('dataReset.executeErrorRef').replace('{reference}', payload.reference) : t('dataReset.executeError'));
            }
        } finally {
            inFlight.current = false;
            setExecuting(false);
        }
    };

    if (done) {
        return (
            <section className="rounded-card border border-success/30 bg-surface p-8 text-center shadow-soft" role="status">
                <h2 className="text-lg font-semibold text-success">{t('dataReset.success')}</h2>
                <p className="mt-2 text-sm text-ink-muted">
                    {t('dataReset.sel.doneSummary')
                        .replace('{deleted}', formatInteger(done.deleted))
                        .replace('{unlinked}', formatInteger(done.unlinked))
                        .replace('{released}', formatInteger(done.released))}
                </p>
                <Button type="button" className="mt-5" onClick={() => router.reload()}>{t('dataReset.sel.newCleanup')}</Button>
            </section>
        );
    }

    return (
        <div className="space-y-6">
            {/* 1. What to clean */}
            <Step number={1} title={t('dataReset.sel.step1')} help={t('dataReset.sel.step1Help')}>
                <div className="space-y-5">
                    {byModule.map(([module, items]) => (
                        <div key={module}>
                            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-faint">{labels.module(module)}</h3>
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {items.map((category) => {
                                    const on = selected.includes(category.key);
                                    const count = category.roots.reduce((s, r) => s + r.count, 0);
                                    return (
                                        <label
                                            key={category.key}
                                            className={`flex cursor-pointer items-start gap-3 rounded-card border p-4 transition-soft ${on ? 'border-danger/50 bg-danger-soft/40' : 'border-line bg-surface hover:border-line-strong'}`}
                                        >
                                            <input type="checkbox" className="mt-1 size-4 accent-danger" checked={on} onChange={() => toggle(category.key)} />
                                            <span className="min-w-0 flex-1">
                                                <span className="flex items-center justify-between gap-2">
                                                    <span className="font-semibold text-ink">{labels.category(category.key)}</span>
                                                    <span className="rounded-full bg-raised px-2 py-0.5 text-xs font-semibold tabular-nums text-ink-muted">{formatInteger(count)}</span>
                                                </span>
                                                <span className="mt-1 block text-xs leading-5 text-ink-muted">{t(`dataReset.categoryHelp.${category.key}` as TranslationKey)}</span>
                                                <span className={`mt-2 inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ${on ? 'bg-danger-soft text-danger' : 'bg-success-soft text-success'}`}>
                                                    {on ? t('dataReset.sel.willDelete') : t('dataReset.sel.kept')}
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                    <p className="rounded-field border border-line bg-raised px-4 py-3 text-xs leading-5 text-ink-muted">{t('dataReset.sel.stockNote')}</p>
                </div>
            </Step>

            {/* 2. Narrow down */}
            {selected.length > 0 && (
                <Step number={2} title={t('dataReset.sel.step2')} help={t('dataReset.sel.step2Help')}>
                    <div className="grid gap-3 sm:grid-cols-2 lg:max-w-xl">
                        <label className="block text-sm font-medium text-ink">
                            {t('dataReset.sel.dateFrom')}
                            <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="control-input mt-1 w-full" />
                        </label>
                        <label className="block text-sm font-medium text-ink">
                            {t('dataReset.sel.dateTo')}
                            <input type="date" value={dateTo} min={dateFrom || undefined} onChange={(e) => setDateTo(e.target.value)} className="control-input mt-1 w-full" />
                        </label>
                    </div>
                    <p className="mt-2 text-xs text-ink-muted">{t('dataReset.sel.businessDateHelp')}</p>
                    <ul className="mt-4 divide-y divide-line rounded-field border border-line">
                        {selectedRoots.map((root) => {
                            const choice = choices[root.table] ?? { mode: 'all' };
                            return (
                                <li key={root.table} className="flex flex-col gap-2 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                                    <span className="font-medium text-ink">{labels.table(root.table)}</span>
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="text-xs text-ink-muted">
                                            {choice.mode === 'all' && (dateFrom || dateTo ? t('dataReset.sel.allInRange') : t('dataReset.sel.allRecords').replace('{count}', formatInteger(root.count)))}
                                            {choice.mode === 'ids' && t('dataReset.sel.pickedCount').replace('{count}', formatInteger(choice.ids.length))}
                                            {choice.mode === 'filtered' && t('dataReset.sel.filteredCount').replace('{count}', formatInteger(choice.total))}
                                        </span>
                                        <Button type="button" size="sm" variant="secondary" onClick={() => setPicker(root.table)}>{t('dataReset.sel.chooseRecords')}</Button>
                                        {choice.mode !== 'all' && (
                                            <Button type="button" size="sm" variant="ghost" onClick={() => setChoices((c) => { const n = { ...c }; delete n[root.table]; return n; })}>{t('dataReset.sel.resetChoice')}</Button>
                                        )}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </Step>
            )}

            {picker && (
                <RecordPicker
                    table={picker}
                    hasStatus={selectedRoots.find((r) => r.table === picker)?.filters.status ?? false}
                    hasWarehouse={selectedRoots.find((r) => r.table === picker)?.filters.warehouse ?? false}
                    warehouses={warehouses}
                    dateFrom={dateFrom}
                    dateTo={dateTo}
                    initial={choices[picker]}
                    onCancel={() => setPicker(null)}
                    onApply={(choice) => {
                        setChoices((c) => ({ ...c, [picker]: choice }));
                        setPicker(null);
                    }}
                />
            )}

            {/* 3. Always kept */}
            <Step number={selected.length > 0 ? 3 : 2} title={t('dataReset.sel.step3')} help={t('dataReset.sel.step3Help')}>
                <ul className="flex flex-wrap gap-2">
                    {['customers', 'suppliers', 'contacts', 'catalogue', 'warehouses', 'users', 'settings', 'numbering', 'stock', 'audit'].map((key) => (
                        <li key={key} className="inline-flex items-center gap-1.5 rounded-full bg-success-soft px-3 py-1 text-xs font-medium text-success">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" aria-hidden="true"><path d="m5 13 4 4L19 7" /></svg>
                            {t(`dataReset.keep.${key}` as TranslationKey)}
                        </li>
                    ))}
                </ul>
            </Step>

            {/* 4. Impact + 5. Confirmation */}
            {selected.length > 0 && (
                <Step number={4} title={t('dataReset.sel.step4')} help={t('dataReset.sel.step4Help')}>
                    <Button type="button" variant="secondary" loading={previewing} loadingText={t('dataReset.previewing')} onClick={() => void analyse()}>
                        {preview ? t('dataReset.sel.reanalyse') : t('dataReset.sel.analyse')}
                    </Button>
                    {error && <p className="mt-3 rounded-field border border-danger/25 bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{error}</p>}
                    {previewing && <div className="mt-4 flex items-center gap-2 text-sm text-ink-muted" role="status"><Spinner size="sm" /> {t('dataReset.previewing')}</div>}
                    {preview && !previewing && (
                        <ImpactPanel
                            preview={preview}
                            onAddCategory={(key) => setSelected((s) => (s.includes(key) ? s : [...s, key]))}
                        />
                    )}
                    {preview?.executable && (
                        <div className="mt-6 border-t border-line pt-6">
                            <h3 className="font-semibold text-ink">{t('dataReset.confirmTitle')}</h3>
                            <p className="mt-1 text-sm text-ink-muted">{t('dataReset.sel.confirmHelp').replace('{count}', formatInteger(preview.delete_total))}</p>
                            <div className="mt-4 grid gap-4 md:grid-cols-3">
                                <Field label={t('dataReset.organizationName')} help={t('dataReset.organizationNameHelp').replace('{name}', organization.name)} error={errors.organization_name}>
                                    <input value={orgName} onChange={(e) => setOrgName(e.target.value)} autoComplete="off" disabled={executing} className="control-input w-full" />
                                </Field>
                                <Field label={t('dataReset.sel.totalLabel')} help={t('dataReset.sel.totalHelp').replace('{count}', String(preview.delete_total))} error={errors.confirmed_total}>
                                    <input value={total} onChange={(e) => setTotal(e.target.value.replace(/\D/g, ''))} inputMode="numeric" autoComplete="off" disabled={executing} dir="ltr" className="control-input w-full tabular-nums" />
                                </Field>
                                <Field label={t('dataReset.phrase')} help={t('dataReset.phraseHelp').replace('{phrase}', confirmationPhrase)} error={errors.confirmation_phrase}>
                                    <input value={phrase} onChange={(e) => setPhrase(e.target.value)} autoComplete="off" disabled={executing} dir="ltr" className="control-input w-full font-mono" />
                                </Field>
                            </div>
                            <Button type="button" variant="danger" className="mt-5" loading={executing} loadingText={t('dataReset.executing')} onClick={() => void execute()}>
                                {t('dataReset.sel.execute').replace('{count}', formatInteger(preview.delete_total))}
                            </Button>
                        </div>
                    )}
                </Step>
            )}
        </div>
    );
}

function Step({ number, title, help, children }: { number: number; title: string; help: string; children: React.ReactNode }) {
    return (
        <section className="rounded-card border border-line bg-surface p-4 shadow-soft sm:p-6">
            <div className="mb-4 flex items-start gap-3">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-sage text-sm font-semibold text-primary">{number}</span>
                <div>
                    <h2 className="font-semibold text-ink">{title}</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">{help}</p>
                </div>
            </div>
            {children}
        </section>
    );
}

function Field({ label, help, error, children }: { label: string; help: string; error?: string; children: React.ReactNode }) {
    return (
        <label className="block text-sm font-medium text-ink">
            {label}
            <span className="mb-2 mt-1 block text-xs font-normal text-ink-muted">{help}</span>
            {children}
            {error && <span className="mt-1 block text-xs text-danger">{error}</span>}
        </label>
    );
}

function ImpactPanel({ preview, onAddCategory }: { preview: SelectivePreview; onAddCategory: (key: string) => void }) {
    const { t } = useI18n();
    const labels = useLabels();
    const metrics = [
        { label: t('dataReset.sel.mSelected'), value: preview.selected_total },
        { label: t('dataReset.sel.mDelete'), value: preview.delete_total, danger: true },
        { label: t('dataReset.sel.mUnlink'), value: preview.unlink_total },
        { label: t('dataReset.sel.mReleased'), value: preview.released_reservations },
    ];
    const blockerText = (b: Blocker) => {
        if (b.code === 'too_large') return t('dataReset.sel.bTooLarge').replace('{count}', formatInteger(b.count)).replace('{limit}', formatInteger(b.limit ?? 0));
        if (b.code === 'receipt_reservation') return t('dataReset.sel.bReceipt').replace('{count}', formatInteger(b.count));
        const key = b.code === 'outside_selection' ? 'dataReset.sel.bOutside' : b.code === 'preserved_category' ? 'dataReset.sel.bPreserved' : 'dataReset.sel.bProtected';
        return t(key as TranslationKey)
            .replace('{count}', formatInteger(b.count))
            .replace('{child}', labels.table(b.table ?? ''))
            .replace('{parent}', labels.table(b.parent_table ?? ''))
            .replace('{category}', b.category ? labels.category(b.category) : '');
    };

    return (
        <div className="mt-5 space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${preview.executable ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'}`}>
                    {preview.executable ? t('dataReset.safeToReset') : preview.delete_total === 0 ? t('dataReset.sel.nothing') : t('dataReset.blockers')}
                </span>
                <span className="text-xs text-ink-muted">{t('dataReset.previewedAt')} <bdi>{formatDate(preview.previewed_at)}</bdi></span>
            </div>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {metrics.map((m) => (
                    <div key={m.label} className="rounded-field bg-raised p-3">
                        <p className="text-xs font-semibold uppercase tracking-wide text-ink-faint">{m.label}</p>
                        <p className={`mt-1 text-2xl font-semibold tabular-nums ${m.danger ? 'text-danger' : 'text-ink'}`}>{formatInteger(m.value)}</p>
                    </div>
                ))}
            </div>

            {preview.blockers.length > 0 && (
                <div className="rounded-field border border-danger/25 bg-danger-soft p-4" role="alert">
                    <h3 className="font-semibold text-danger">{t('dataReset.blockers')}</h3>
                    <p className="mt-1 text-sm text-danger">{t('dataReset.sel.blockedHelp')}</p>
                    <ul className="mt-3 space-y-2">
                        {preview.blockers.map((b, i) => (
                            <li key={i} className="rounded-field bg-surface/80 p-3 text-sm text-ink">
                                <p>{blockerText(b)}</p>
                                {b.samples && b.samples.length > 0 && <p className="mt-1 text-xs text-ink-muted"><bdi>{b.samples.join(' · ')}</bdi>{b.count > b.samples.length ? ' …' : ''}</p>}
                                {b.code === 'preserved_category' && b.category && (
                                    <Button type="button" size="sm" variant="secondary" className="mt-2" onClick={() => onAddCategory(b.category as string)}>
                                        {t('dataReset.sel.addCategory').replace('{category}', labels.category(b.category))}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid gap-5 lg:grid-cols-2">
                <div>
                    <h3 className="text-sm font-semibold text-ink">{t('dataReset.sel.breakdown')}</h3>
                    <ul className="mt-2 divide-y divide-line rounded-field border border-line text-sm">
                        {Object.entries(preview.breakdown).flatMap(([category, counts]) => [
                            ...Object.entries(counts.delete).map(([table, n]) => (
                                <li key={`d-${table}`} className="flex items-start justify-between gap-3 px-3 py-2">
                                    <span className="min-w-0">
                                        <span className="text-ink">{labels.table(table)}</span>
                                        <span className="block text-xs text-ink-faint">{category === 'linked' ? t('dataReset.sel.linked') : labels.category(category)}</span>
                                        {preview.samples[table]?.length > 0 && <span className="block truncate text-xs text-ink-muted"><bdi>{preview.samples[table].join(' · ')}</bdi></span>}
                                    </span>
                                    <span className="shrink-0 font-semibold tabular-nums text-danger">−{formatInteger(n)}</span>
                                </li>
                            )),
                            ...Object.entries(counts.unlink).map(([key, n]) => (
                                <li key={`u-${key}`} className="flex items-center justify-between gap-3 px-3 py-2">
                                    <span className="text-ink-muted">{t('dataReset.sel.unlinkRow').replace('{table}', labels.table(key.split('.')[0]))}</span>
                                    <span className="shrink-0 font-semibold tabular-nums text-ink">{formatInteger(n)}</span>
                                </li>
                            )),
                        ])}
                        {preview.delete_total === 0 && <li className="px-3 py-2 text-ink-muted">{t('dataReset.sel.nothing')}</li>}
                    </ul>
                </div>
                <div>
                    <h3 className="text-sm font-semibold text-ink">{t('dataReset.sel.keptTitle')}</h3>
                    <ul className="mt-2 divide-y divide-line rounded-field border border-line text-sm">
                        {Object.entries(preview.protected).filter(([, n]) => n > 0).map(([table, n]) => (
                            <li key={table} className="flex items-center justify-between gap-3 px-3 py-2">
                                <span className="text-ink-muted">{labels.table(table)}</span>
                                <span className="shrink-0 font-semibold tabular-nums text-success">{formatInteger(n)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </div>
    );
}

function RecordPicker({
    table, hasStatus, hasWarehouse, warehouses, dateFrom, dateTo, initial, onCancel, onApply,
}: {
    table: string;
    hasStatus: boolean;
    hasWarehouse: boolean;
    warehouses: { id: number; name: string }[];
    dateFrom: string;
    dateTo: string;
    initial?: RootChoice;
    onCancel: () => void;
    onApply: (choice: RootChoice) => void;
}) {
    const { t } = useI18n();
    const labels = useLabels();
    const [filters, setFilters] = useState<RecordFilters>(initial?.mode === 'filtered' ? initial.filters : emptyFilters);
    const [page, setPage] = useState(1);
    const [rows, setRows] = useState<RecordRow[]>([]);
    const [meta, setMeta] = useState({ total: 0, current_page: 1, last_page: 1 });
    const [statuses, setStatuses] = useState<string[]>([]);
    const [picked, setPicked] = useState<number[]>(initial?.mode === 'ids' ? initial.ids : []);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [confirmAll, setConfirmAll] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setLoadError(false);
        const handle = window.setTimeout(() => {
            axios.get('/settings/data-reset/records', {
                params: { table, page, ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')), date_from: dateFrom || undefined, date_to: dateTo || undefined },
                signal: controller.signal,
            }).then((response) => {
                setRows(response.data.data);
                setMeta(response.data.meta);
                setStatuses(response.data.statuses ?? []);
            }).catch((e) => { if (!axios.isCancel(e)) setLoadError(true); }).finally(() => setLoading(false));
        }, 250);
        return () => { window.clearTimeout(handle); controller.abort(); };
    }, [table, page, filters, dateFrom, dateTo]);

    const setFilter = (key: keyof RecordFilters, value: string) => { setFilters((f) => ({ ...f, [key]: value })); setPage(1); setConfirmAll(false); };
    const toggle = (id: number) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));
    const pageIds = rows.map((r) => r.id);
    const allPageOn = pageIds.length > 0 && pageIds.every((id) => picked.includes(id));

    return (
        <section className="rounded-card border border-primary/40 bg-surface p-4 shadow-soft sm:p-6" aria-label={labels.table(table)}>
            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <h2 className="font-semibold text-ink">{t('dataReset.sel.pickerTitle').replace('{table}', labels.table(table))}</h2>
                <span className="text-xs text-ink-muted">{t('dataReset.sel.pickedCount').replace('{count}', formatInteger(picked.length))}</span>
            </div>
            <div className="mt-4 flex flex-col gap-2 sm:flex-row">
                <div className="flex-1"><SearchInput value={filters.search} onChange={(v) => setFilter('search', v)} searching={loading} placeholder={t('dataReset.sel.searchPlaceholder')} /></div>
                {hasStatus && (
                    <FilterSelect value={filters.status} onChange={(e) => setFilter('status', e.target.value)} aria-label={t('dataReset.sel.status')}>
                        <option value="">{t('dataReset.sel.allStatuses')}</option>
                        {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                    </FilterSelect>
                )}
                {hasWarehouse && (
                    <FilterSelect value={filters.warehouse_id} onChange={(e) => setFilter('warehouse_id', e.target.value)} aria-label={t('dataReset.sel.warehouse')}>
                        <option value="">{t('dataReset.sel.allWarehouses')}</option>
                        {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                    </FilterSelect>
                )}
            </div>

            <div className="mt-3 overflow-x-auto rounded-field border border-line">
                <table className="w-full min-w-[34rem] text-sm">
                    <thead className="bg-raised text-start text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th className="w-10 px-3 py-2"><input type="checkbox" aria-label={t('dataReset.sel.selectPage')} checked={allPageOn} onChange={() => setPicked((p) => (allPageOn ? p.filter((id) => !pageIds.includes(id)) : [...new Set([...p, ...pageIds])]))} /></th>
                            <th className="px-3 py-2 text-start">{t('dataReset.sel.reference')}</th>
                            <th className="px-3 py-2 text-start">{t('dataReset.sel.date')}</th>
                            <th className="px-3 py-2 text-start">{t('dataReset.sel.related')}</th>
                            <th className="px-3 py-2 text-start">{t('dataReset.sel.status')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {rows.map((row) => (
                            <tr key={row.id} className={picked.includes(row.id) ? 'bg-danger-soft/30' : undefined}>
                                <td className="px-3 py-2"><input type="checkbox" aria-label={row.reference} checked={picked.includes(row.id)} onChange={() => toggle(row.id)} /></td>
                                <td className="px-3 py-2 font-medium text-ink"><bdi>{row.reference}</bdi></td>
                                <td className="px-3 py-2 tabular-nums text-ink-muted">{row.date ? formatDate(row.date) : '—'}</td>
                                <td className="px-3 py-2 text-ink-muted">{[row.party, ...row.warehouses].filter(Boolean).join(' · ') || '—'}</td>
                                <td className="px-3 py-2 text-ink-muted">{row.status ?? '—'}</td>
                            </tr>
                        ))}
                        {!loading && !loadError && rows.length === 0 && <tr><td colSpan={5} className="px-3 py-8 text-center text-ink-muted">{t('dataReset.sel.noRecords')}</td></tr>}
                        {loadError && <tr><td colSpan={5} className="px-3 py-8 text-center text-danger">{t('dataReset.sel.loadError')}</td></tr>}
                    </tbody>
                </table>
            </div>
            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-ink-muted">
                <span>{t('dataReset.sel.matching').replace('{count}', formatInteger(meta.total))}</span>
                <span className="flex items-center gap-2">
                    <Button type="button" size="sm" variant="ghost" disabled={page <= 1 || loading} onClick={() => setPage((p) => p - 1)}>‹</Button>
                    <span className="tabular-nums">{meta.current_page} / {meta.last_page}</span>
                    <Button type="button" size="sm" variant="ghost" disabled={page >= meta.last_page || loading} onClick={() => setPage((p) => p + 1)}>›</Button>
                </span>
            </div>

            {meta.total > 0 && (
                <div className="mt-4 rounded-field border border-warning/30 bg-warning-soft px-4 py-3 text-sm text-warning">
                    <label className="flex items-start gap-2">
                        <input type="checkbox" className="mt-1" checked={confirmAll} onChange={(e) => setConfirmAll(e.target.checked)} />
                        <span>{t('dataReset.sel.selectAllFiltered').replace('{count}', formatInteger(meta.total))}</span>
                    </label>
                </div>
            )}

            <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button type="button" variant="secondary" onClick={onCancel}>{t('common.cancel')}</Button>
                {confirmAll ? (
                    <Button type="button" variant="danger" onClick={() => onApply({ mode: 'filtered', filters, total: meta.total })}>
                        {t('dataReset.sel.useFiltered').replace('{count}', formatInteger(meta.total))}
                    </Button>
                ) : (
                    <Button type="button" disabled={picked.length === 0} onClick={() => onApply({ mode: 'ids', ids: picked })}>
                        {t('dataReset.sel.usePicked').replace('{count}', formatInteger(picked.length))}
                    </Button>
                )}
            </div>
        </section>
    );
}
