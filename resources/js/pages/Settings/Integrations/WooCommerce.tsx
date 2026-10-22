import { Button } from '@/components/ui/Button';
import { useFreshAuth } from '@/components/auth/FreshAuthProvider';
import { Checkbox, FormBanner, PasswordField, TextField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import { InlineLoader } from '@/components/ui/Spinner';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/utils/format';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';

type Option = { id: number; name: string; code?: string; rate?: string; is_default?: boolean };

type SyncRun = {
    id: number;
    mode: string;
    status: 'running' | 'completed' | 'completed_with_errors' | 'failed';
    started_at: string | null;
    completed_at: string | null;
    products_read: number;
    products_created: number;
    products_updated: number;
    products_skipped: number;
    products_failed: number;
    variants_synced: number;
    categories_synced: number;
    stock_adjustments: number;
    message: string | null;
    errors: Array<{ remote_id: string | null; message: string }> | null;
};

type Integration = {
    id: number;
    name: string;
    store_url: string;
    consumer_key: string;
    default_warehouse_id: number | null;
    default_store_id: number | null;
    sync_stock: boolean;
    prices_include_tax: boolean | null;
    brand_source: string | null;
    brand_taxonomy: string | null;
    brand_attribute_name: string | null;
    brand_meta_key: string | null;
    reference_meta_key: string | null;
    last_connection_ok: boolean | null;
    last_connection_check_at: string | null;
    last_product_sync_completed_at: string | null;
    synced_product_count: number;
    has_secret: boolean;
};

type Props = {
    integration: Integration | null;
    runs: SyncRun[];
    warehouses: Option[];
    stores: Option[];
    taxRates: Option[];
    can: { manage: boolean; sync: boolean; syncStock: boolean };
};

const priceModeValue = (v: boolean | null) => (v === null ? '' : v ? 'ttc' : 'ht');

export default function WooCommerceSettings({ integration, runs, warehouses, stores, can }: Props) {
    const { t } = useI18n();
    const { submit: submitProtected } = useFreshAuth();
    const statusLabels: Record<string, string> = { running: t('woo.statusRunning'), completed: t('woo.statusCompleted'), completed_with_errors: t('woo.statusCompletedErrors'), failed: t('woo.statusFailed') };
    const creating = integration === null;
    const form = useForm({
        name: integration?.name ?? '',
        store_url: integration?.store_url ?? 'https://',
        consumer_key: integration?.consumer_key ?? '',
        consumer_secret: '',
        default_warehouse_id: integration?.default_warehouse_id ?? null,
        default_store_id: integration?.default_store_id ?? null,
        sync_stock: integration?.sync_stock ?? false,
        prices_include_tax: integration?.prices_include_tax ?? null,
        brand_source: integration?.brand_source ?? '',
        brand_taxonomy: integration?.brand_taxonomy ?? '',
        brand_attribute_name: integration?.brand_attribute_name ?? '',
        brand_meta_key: integration?.brand_meta_key ?? '',
        reference_meta_key: integration?.reference_meta_key ?? '',
    });
    const errors = form.errors as Record<string, string>;

    const [replaceSecret, setReplaceSecret] = useState(creating);
    const [testResult, setTestResult] = useState<{ ok: boolean; message: string } | null>(null);
    const [dispatching, setDispatching] = useState(false);
    const [testing, setTesting] = useState(false);
    const [confirmSync, setConfirmSync] = useState(false);
    const [awaitingRun, setAwaitingRun] = useState(false);
    const [pollError, setPollError] = useState('');
    const [saving, setSaving] = useState(false);

    const latestRun = runs[0] ?? null;
    const [liveRun, setLiveRun] = useState<SyncRun | null>(latestRun);
    const [syncedCount, setSyncedCount] = useState(integration?.synced_product_count ?? 0);
    const pollRef = useRef<number | null>(null);
    const syncBaselineRunIdRef = useRef<number | null>(latestRun?.id ?? null);

    useEffect(() => {
        setLiveRun(latestRun);
    }, [latestRun]);

    // Poll after dispatch as well as while a run is active: the queue may not
    // have created its run row yet when the POST response returns.
    useEffect(() => {
        const shouldPoll = awaitingRun || liveRun?.status === 'running';
        if (!integration || !shouldPoll) {
            if (pollRef.current !== null) window.clearTimeout(pollRef.current);
            return;
        }

        let cancelled = false;
        const poll = async () => {
            try {
                const response = await fetch(`/integrations/woocommerce/${integration.id}/status`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error('status');
                const payload = (await response.json()) as { run: SyncRun | null; synced_product_count: number; last_product_sync_completed_at: string | null };
                if (cancelled) return;
                setPollError('');
                setSyncedCount(payload.synced_product_count);
                const isNewRun = payload.run !== null && payload.run.id !== syncBaselineRunIdRef.current;
                const acceptedRun = isNewRun || !awaitingRun;
                if (acceptedRun) setLiveRun(payload.run);

                if (isNewRun) setAwaitingRun(false);
                if (acceptedRun && payload.run && payload.run.status !== 'running') {
                    router.reload({ only: ['runs', 'integration'] });
                    return;
                }
            } catch {
                if (!cancelled) setPollError(t('woo.pollFailed'));
            }

            if (!cancelled) pollRef.current = window.setTimeout(poll, 2500);
        };

        pollRef.current = window.setTimeout(poll, 2500);
        return () => {
            cancelled = true;
            if (pollRef.current !== null) window.clearTimeout(pollRef.current);
        };
    }, [integration, liveRun?.status, awaitingRun, t]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.clearErrors();
        const data = creating || replaceSecret ? form.data : { ...form.data, consumer_secret: '' };
        void submitProtected({
            method: creating ? 'post' : 'patch',
            url: creating ? '/integrations/woocommerce' : `/integrations/woocommerce/${integration!.id}`,
            data,
            preserveScroll: true,
            onStart: () => setSaving(true),
            onSuccess: () => { form.setData('consumer_secret', ''); setReplaceSecret(false); },
            onError: (errors) => form.setError(errors),
            onFinish: () => setSaving(false),
        });
    };

    const runTest = async () => {
        if (!integration || testing) return;
        setTesting(true);
        setTestResult(null);
        try {
            const response = await fetch(`/integrations/woocommerce/${integration.id}/test`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
                },
                credentials: 'same-origin',
            });
            setTestResult((await response.json()) as { ok: boolean; message: string });
        } catch {
            setTestResult({ ok: false, message: t('woo.connectionFailed') });
        } finally {
            setTesting(false);
        }
    };

    const startSync = () => {
        if (!integration || dispatching) return;
        setDispatching(true);
        setPollError('');
        syncBaselineRunIdRef.current = liveRun?.id ?? null;
        setConfirmSync(false);
        router.post(`/integrations/woocommerce/${integration.id}/sync`, { mode: 'full' }, {
            preserveScroll: true,
            onSuccess: () => setAwaitingRun(true),
            onFinish: () => setDispatching(false),
        });
    };

    const warehouseName = useMemo(
        () => warehouses.find((w) => w.id === form.data.default_warehouse_id)?.name ?? t('woo.notSelected'),
        [warehouses, form.data.default_warehouse_id, t],
    );

    return (
        <ApplicationShell>
            <Head title={t('woo.title')} />
            <PageHeader
                title="WooCommerce"
                description={t('woo.description')}
            />

            <div className="grid max-w-5xl gap-6 lg:grid-cols-[1.4fr_1fr]">
                {/* Connection + configuration */}
                <form onSubmit={submit} className="space-y-4 rounded-card border border-line bg-surface p-5 shadow-card">
                    <h2 className="text-sm font-semibold text-ink">{t('woo.connection')}</h2>

                    <TextField label={t('woo.integrationName')} required value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)} error={errors.name} placeholder="Boutique AV Professional" />
                    <TextField label={t('woo.storeUrl')} required type="url" value={form.data.store_url}
                        onChange={(e) => form.setData('store_url', e.target.value)} error={errors.store_url} placeholder="https://exemple.com" />
                    <TextField label="Consumer Key" required value={form.data.consumer_key}
                        onChange={(e) => form.setData('consumer_key', e.target.value)} error={errors.consumer_key} placeholder="ck_..." />

                    {creating || replaceSecret ? (
                        <PasswordField label="Consumer Secret" required={creating} value={form.data.consumer_secret}
                            onChange={(e) => form.setData('consumer_secret', e.target.value)} error={errors.consumer_secret} placeholder="cs_..." />
                    ) : (
                        <div>
                            <label className="mb-1.5 block text-[13px] font-medium text-ink">Consumer Secret</label>
                            <div className="flex items-center gap-3">
                                <span className="rounded-field border border-line-strong bg-raised px-3 py-2 text-sm tracking-widest text-ink-muted">••••••••••••</span>
                                {can.manage && (
                                    <button type="button" onClick={() => setReplaceSecret(true)} className="text-[13px] font-medium text-ink-muted underline">
                                        {t('woo.replace')}
                                    </button>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="border-t border-line pt-4">
                        <h2 className="text-sm font-semibold text-ink">{t('woo.configuration')}</h2>

                        <label className="mt-3 block text-[13px] font-medium text-ink">{t('woo.priceEntryMode')}</label>
                        <select
                            value={priceModeValue(form.data.prices_include_tax)}
                            onChange={(e) => form.setData('prices_include_tax', e.target.value === '' ? null : e.target.value === 'ttc')}
                            className="mt-1 w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                        >
                            <option value="">{t('woo.detectAutomatically')}</option>
                            <option value="ttc">{t('woo.inclTax')}</option>
                            <option value="ht">{t('woo.exclTax')}</option>
                        </select>
                        <p className="mt-1 text-[12px] text-ink-muted">
                            {t('woo.detectHelp')}
                        </p>

                        <label className="mt-3 block text-[13px] font-medium text-ink">
                            {t('woo.defaultStoreTax')}
                            <select
                                value={form.data.default_store_id ?? ''}
                                onChange={(e) => form.setData('default_store_id', e.target.value ? Number(e.target.value) : null)}
                                className="mt-1 w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                            >
                                <option value="">{t('woo.firstActiveStore')}</option>
                                {stores.map((s) => <option key={s.id} value={s.id}>{s.name}{s.code ? ` · ${s.code}` : ''}</option>)}
                            </select>
                            {errors.default_store_id && <span className="mt-1 block text-[13px] text-danger">{errors.default_store_id}</span>}
                        </label>

                        <div className="mt-3">
                            <Checkbox
                                label={t('woo.syncStock')}
                                checked={form.data.sync_stock}
                                onChange={(e) => form.setData('sync_stock', e.target.checked)}
                            />
                            <p className="mt-1 text-[12px] text-ink-muted">
                                {t('woo.stockJournalHelp')}
                            </p>
                        </div>

                        {form.data.sync_stock && (
                            <label className="mt-2 block text-[13px] font-medium text-ink">
                                {t('woo.targetWarehouse')}
                                <select value={form.data.default_warehouse_id ?? ''} onChange={(e) => form.setData('default_warehouse_id', e.target.value ? Number(e.target.value) : null)}
                                    className="mt-1 w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                                    <option value="">{t('woo.selectWarehouse')}</option>
                                    {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name} · {w.code}</option>)}
                                </select>
                                {errors.default_warehouse_id && <span className="mt-1 block text-[13px] text-danger">{errors.default_warehouse_id}</span>}
                            </label>
                        )}

                        <details className="mt-3">
                            <summary className="cursor-pointer text-[13px] font-medium text-ink-muted">{t('woo.advancedOptions')}</summary>
                            <div className="mt-2 space-y-2">
                                <label className="block text-[13px] text-ink">{t('woo.brandSource')}
                                    <select value={form.data.brand_source} onChange={(e) => form.setData('brand_source', e.target.value)}
                                        className="mt-1 w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                                        <option value="">{t('woo.autoDetection')}</option>
                                        <option value="taxonomy">{t('woo.taxonomy')}</option>
                                        <option value="attribute">{t('woo.productAttribute')}</option>
                                        <option value="meta">{t('woo.metaKey')}</option>
                                    </select>
                                </label>
                                {form.data.brand_source === 'taxonomy' && <TextField label={t('woo.taxonomyName')} value={form.data.brand_taxonomy} onChange={(e) => form.setData('brand_taxonomy', e.target.value)} placeholder="brands" />}
                                {form.data.brand_source === 'attribute' && <TextField label={t('woo.attributeName')} value={form.data.brand_attribute_name} onChange={(e) => form.setData('brand_attribute_name', e.target.value)} placeholder={t('products.brand')} />}
                                {form.data.brand_source === 'meta' && <TextField label={t('woo.brandMetaKey')} value={form.data.brand_meta_key} onChange={(e) => form.setData('brand_meta_key', e.target.value)} placeholder="_brand" />}
                                <TextField label={t('woo.referenceMetaKey')} value={form.data.reference_meta_key} onChange={(e) => form.setData('reference_meta_key', e.target.value)} placeholder="_reference_interne" />
                            </div>
                        </details>
                    </div>

                    {Object.keys(form.errors).length > 0 && <FormBanner>{t('woo.correctFields')}</FormBanner>}

                    {can.manage && (
                        <Button type="submit" loading={saving} loadingText={t('common.saving')}>
                            {creating ? t('woo.saveIntegration') : t('woo.saveChanges')}
                        </Button>
                    )}
                </form>

                {/* Status + actions */}
                <div className="space-y-4">
                    <div className="rounded-card border border-line bg-surface p-5 shadow-card">
                        <h2 className="text-sm font-semibold text-ink">{t('woo.state')}</h2>
                        {integration ? (
                            <dl className="mt-3 space-y-2 text-[13px]">
                                <div className="flex justify-between"><dt className="text-ink-muted">{t('woo.connection')}</dt>
                                    <dd className={integration.last_connection_ok ? 'text-success' : integration.last_connection_ok === false ? 'text-danger' : 'text-ink-muted'}>
                                        {integration.last_connection_ok ? t('woo.connected') : integration.last_connection_ok === false ? t('woo.failed') : t('woo.notTested')}
                                    </dd>
                                </div>
                                <div className="flex justify-between"><dt className="text-ink-muted">{t('woo.syncedProducts')}</dt><dd className="font-medium text-ink">{syncedCount}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">{t('woo.lastSync')}</dt>
                                    <dd className="text-ink">{integration.last_product_sync_completed_at ? formatDateTime(integration.last_product_sync_completed_at) : t('woo.never')}</dd>
                                </div>
                            </dl>
                        ) : (
                            <p className="mt-2 text-[13px] text-ink-muted">{t('woo.saveToStart')}</p>
                        )}

                        {integration && (
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button type="button" variant="secondary" size="sm" loading={testing} loadingText={t('woo.testLoading')} onClick={runTest}>
                                    {t('woo.testConnection')}
                                </Button>
                                {can.sync && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        loading={dispatching || awaitingRun || liveRun?.status === 'running'}
                                        loadingText={dispatching ? t('woo.starting') : t('woo.syncRunning')}
                                        disabled={awaitingRun || liveRun?.status === 'running'}
                                        onClick={() => setConfirmSync(true)}
                                    >
                                        {awaitingRun || liveRun?.status === 'running' ? t('woo.syncRunning') : t('woo.syncProducts')}
                                    </Button>
                                )}
                            </div>
                        )}
                        {testResult && (
                            <p className={`mt-3 rounded-field px-3 py-2 text-[13px] ${testResult.ok ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'}`}>
                                {testResult.message}
                            </p>
                        )}
                        {pollError && <p className="mt-3 rounded-field bg-danger-soft px-3 py-2 text-[13px] text-danger">{pollError}</p>}
                    </div>

                    {(awaitingRun || liveRun) && (
                        <div className="rounded-card border border-line bg-surface p-5 shadow-card">
                            <div className="flex items-center justify-between">
                                <h2 className="text-sm font-semibold text-ink">{t('woo.sync')}</h2>
                                <span className={`rounded-full px-2 py-0.5 text-[12px] font-semibold ${
                                    liveRun?.status === 'completed' ? 'bg-success-soft text-success'
                                        : liveRun?.status === 'failed' ? 'bg-danger-soft text-danger'
                                            : liveRun?.status === 'completed_with_errors' ? 'bg-warning-soft text-warning'
                                                : 'bg-sage text-ink-muted'
                                }`}>
                                    {awaitingRun ? t('woo.starting') : statusLabels[liveRun!.status] ?? liveRun!.status}
                                </span>
                            </div>
                            {awaitingRun && <InlineLoader label={t('woo.syncStarting')} className="mt-3 text-[13px]" />}
                            {liveRun && !awaitingRun && <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-[13px]">
                                <div className="flex justify-between"><dt className="text-ink-muted">{t('woo.read')}</dt><dd className="tabular-nums">{liveRun.products_read}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">{t('woo.created')}</dt><dd className="tabular-nums">{liveRun.products_created}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">{t('woo.updated')}</dt><dd className="tabular-nums">{liveRun.products_updated}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">{t('woo.variants')}</dt><dd className="tabular-nums">{liveRun.variants_synced}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">{t('woo.categories')}</dt><dd className="tabular-nums">{liveRun.categories_synced}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">{t('woo.stockAdjustments')}</dt><dd className="tabular-nums">{liveRun.stock_adjustments}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">{t('woo.inError')}</dt><dd className={`tabular-nums ${liveRun.products_failed > 0 ? 'text-danger' : ''}`}>{liveRun.products_failed}</dd></div>
                            </dl>}
                            {liveRun && !awaitingRun && liveRun.message && <p className="mt-3 whitespace-pre-line text-[12px] text-ink-muted">{liveRun.message}</p>}
                            {liveRun && !awaitingRun && (liveRun.errors?.length ?? 0) > 0 && (
                                <ul className="mt-3 max-h-40 space-y-1 overflow-y-auto rounded-field bg-raised p-2 text-[12px] text-ink-muted">
                                    {liveRun.errors!.slice(0, 50).map((error, index) => (
                                        <li key={index}>{error.remote_id ? `Woo #${error.remote_id} — ` : ''}{error.message}</li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {runs.length > 1 && (
                <section className="mt-8 max-w-5xl">
                    <h2 className="mb-3 text-sm font-semibold text-ink">{t('woo.history')}</h2>
                    <div className="overflow-x-auto rounded-card border border-line">
                        <table className="min-w-full divide-y divide-line text-[13px]">
                            <thead className="bg-raised text-start text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr><th className="px-3 py-2">{t('woo.started')}</th><th className="px-3 py-2">{t('common.status')}</th><th className="px-3 py-2 text-end">{t('woo.read')}</th><th className="px-3 py-2 text-end">{t('woo.created')}</th><th className="px-3 py-2 text-end">{t('woo.updated')}</th><th className="px-3 py-2 text-end">{t('woo.errors')}</th></tr>
                            </thead>
                            <tbody className="divide-y divide-line bg-surface">
                                {runs.map((run) => (
                                    <tr key={run.id}>
                                        <td className="px-3 py-2">{run.started_at ? formatDateTime(run.started_at) : '—'}</td>
                                        <td className="px-3 py-2">{statusLabels[run.status] ?? run.status}</td>
                                        <td className="px-3 py-2 text-end tabular-nums">{run.products_read}</td>
                                        <td className="px-3 py-2 text-end tabular-nums">{run.products_created}</td>
                                        <td className="px-3 py-2 text-end tabular-nums">{run.products_updated}</td>
                                        <td className="px-3 py-2 text-end tabular-nums">{run.products_failed}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            {confirmSync && integration && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-ink/25 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-md rounded-panel border border-line bg-surface p-6 shadow-pop">
                        <h3 className="text-base font-semibold text-ink">{t('woo.syncProducts')}</h3>
                        <p className="mt-2 text-[13px] text-ink-muted">
                            {t('woo.syncModalHelp')}
                        </p>
                        <p className="mt-2 rounded-field bg-raised px-3 py-2 text-[13px] text-ink">
                            {t('products.stock')} : {integration.sync_stock ? t('woo.stockSyncedTo').replace('{warehouse}', warehouseName) : t('woo.stockUnchanged')}
                        </p>
                        <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Button type="button" variant="secondary" disabled={dispatching} onClick={() => setConfirmSync(false)} className="w-full sm:w-auto">{t('common.cancel')}</Button>
                            <Button type="button" loading={dispatching} loadingText={t('woo.starting')} onClick={startSync} className="w-full sm:w-auto">{t('woo.launchSync')}</Button>
                        </div>
                    </div>
                </div>
            )}
        </ApplicationShell>
    );
}
