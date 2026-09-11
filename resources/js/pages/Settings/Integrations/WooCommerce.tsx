import { Button } from '@/components/ui/Button';
import { Checkbox, FormBanner, PasswordField, TextField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
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

const STATUS_LABELS: Record<string, string> = {
    running: 'En cours',
    completed: 'Terminée',
    completed_with_errors: 'Terminée avec erreurs',
    failed: 'Échec',
};

const priceModeValue = (v: boolean | null) => (v === null ? '' : v ? 'ttc' : 'ht');

export default function WooCommerceSettings({ integration, runs, warehouses, stores, can }: Props) {
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

    const latestRun = runs[0] ?? null;
    const [liveRun, setLiveRun] = useState<SyncRun | null>(latestRun);
    const [syncedCount, setSyncedCount] = useState(integration?.synced_product_count ?? 0);
    const pollRef = useRef<number | null>(null);

    useEffect(() => {
        setLiveRun(latestRun);
    }, [latestRun]);

    // Poll the run status while a synchronization is running.
    useEffect(() => {
        if (!integration || liveRun?.status !== 'running') {
            if (pollRef.current) window.clearInterval(pollRef.current);
            return;
        }
        pollRef.current = window.setInterval(async () => {
            try {
                const response = await fetch(`/integrations/woocommerce/${integration.id}/status`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) return;
                const payload = (await response.json()) as { run: SyncRun | null; synced_product_count: number };
                setSyncedCount(payload.synced_product_count);
                setLiveRun(payload.run);
                if (payload.run && payload.run.status !== 'running') {
                    router.reload({ only: ['runs', 'integration'] });
                }
            } catch {
                /* transient — keep polling */
            }
        }, 2500);
        return () => {
            if (pollRef.current) window.clearInterval(pollRef.current);
        };
    }, [integration, liveRun?.status]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { form.setData('consumer_secret', ''); setReplaceSecret(false); } };
        if (creating) {
            form.post('/integrations/woocommerce', options);
            return;
        }
        // Only send a secret when the operator explicitly chose to replace it.
        form.transform((data) => (replaceSecret ? data : { ...data, consumer_secret: '' }));
        form.patch(`/integrations/woocommerce/${integration.id}`, options);
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
            setTestResult({ ok: false, message: 'La connexion à WooCommerce a échoué.' });
        } finally {
            setTesting(false);
        }
    };

    const startSync = () => {
        if (!integration || dispatching) return;
        setDispatching(true);
        setConfirmSync(false);
        // Fire-and-forget: the job runs on the queue. Once dispatched, the job-status
        // panel (with its poll) takes over — no long-lived button spinner.
        router.post(`/integrations/woocommerce/${integration.id}/sync`, { mode: 'full' }, {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['runs', 'integration'] }),
            onFinish: () => setDispatching(false),
        });
    };

    const warehouseName = useMemo(
        () => warehouses.find((w) => w.id === form.data.default_warehouse_id)?.name ?? 'non sélectionné',
        [warehouses, form.data.default_warehouse_id],
    );

    return (
        <ApplicationShell>
            <Head title="Intégration WooCommerce" />
            <PageHeader
                title="WooCommerce"
                description="Synchronisez le catalogue WooCommerce vers l’ERP. Les commandes seront ajoutées dans une phase ultérieure."
            />

            <div className="grid max-w-5xl gap-6 lg:grid-cols-[1.4fr_1fr]">
                {/* Connection + configuration */}
                <form onSubmit={submit} className="space-y-4 rounded-card border border-line bg-surface p-5 shadow-card">
                    <h2 className="text-sm font-semibold text-ink">Connexion</h2>

                    <TextField label="Nom de l’intégration" required value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)} error={errors.name} placeholder="Boutique AV Professional" />
                    <TextField label="URL de la boutique" required type="url" value={form.data.store_url}
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
                                        Remplacer
                                    </button>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="border-t border-line pt-4">
                        <h2 className="text-sm font-semibold text-ink">Configuration</h2>

                        <label className="mt-3 block text-[13px] font-medium text-ink">Prix WooCommerce saisis en</label>
                        <select
                            value={priceModeValue(form.data.prices_include_tax)}
                            onChange={(e) => form.setData('prices_include_tax', e.target.value === '' ? null : e.target.value === 'ttc')}
                            className="mt-1 w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                        >
                            <option value="">Détecter automatiquement depuis la boutique</option>
                            <option value="ttc">TTC (toutes taxes comprises)</option>
                            <option value="ht">HT (hors taxes)</option>
                        </select>
                        <p className="mt-1 text-[12px] text-ink-muted">
                            « Détecter » interroge les réglages WooCommerce. La signification retenue est indiquée dans le compte rendu de synchronisation.
                        </p>

                        <label className="mt-3 block text-[13px] font-medium text-ink">
                            Magasin par défaut (résolution de la taxe)
                            <select
                                value={form.data.default_store_id ?? ''}
                                onChange={(e) => form.setData('default_store_id', e.target.value ? Number(e.target.value) : null)}
                                className="mt-1 w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                            >
                                <option value="">Premier magasin actif de l’organisation</option>
                                {stores.map((s) => <option key={s.id} value={s.id}>{s.name}{s.code ? ` · ${s.code}` : ''}</option>)}
                            </select>
                            {errors.default_store_id && <span className="mt-1 block text-[13px] text-danger">{errors.default_store_id}</span>}
                        </label>

                        <div className="mt-3">
                            <Checkbox
                                label="Synchroniser le stock WooCommerce"
                                checked={form.data.sync_stock}
                                onChange={(e) => form.setData('sync_stock', e.target.checked)}
                            />
                            <p className="mt-1 text-[12px] text-ink-muted">
                                Réconcilié via le journal d’inventaire (ajustements), jamais écrit directement.
                            </p>
                        </div>

                        {form.data.sync_stock && (
                            <label className="mt-2 block text-[13px] font-medium text-ink">
                                Entrepôt cible
                                <select value={form.data.default_warehouse_id ?? ''} onChange={(e) => form.setData('default_warehouse_id', e.target.value ? Number(e.target.value) : null)}
                                    className="mt-1 w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                                    <option value="">Sélectionner un entrepôt</option>
                                    {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name} · {w.code}</option>)}
                                </select>
                                {errors.default_warehouse_id && <span className="mt-1 block text-[13px] text-danger">{errors.default_warehouse_id}</span>}
                            </label>
                        )}

                        <details className="mt-3">
                            <summary className="cursor-pointer text-[13px] font-medium text-ink-muted">Options avancées (marque, référence)</summary>
                            <div className="mt-2 space-y-2">
                                <label className="block text-[13px] text-ink">Source de la marque
                                    <select value={form.data.brand_source} onChange={(e) => form.setData('brand_source', e.target.value)}
                                        className="mt-1 w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                                        <option value="">Détection automatique</option>
                                        <option value="taxonomy">Taxonomie</option>
                                        <option value="attribute">Attribut produit</option>
                                        <option value="meta">Clé de métadonnée</option>
                                    </select>
                                </label>
                                {form.data.brand_source === 'taxonomy' && <TextField label="Nom de la taxonomie" value={form.data.brand_taxonomy} onChange={(e) => form.setData('brand_taxonomy', e.target.value)} placeholder="brands" />}
                                {form.data.brand_source === 'attribute' && <TextField label="Nom de l’attribut" value={form.data.brand_attribute_name} onChange={(e) => form.setData('brand_attribute_name', e.target.value)} placeholder="Marque" />}
                                {form.data.brand_source === 'meta' && <TextField label="Clé de métadonnée marque" value={form.data.brand_meta_key} onChange={(e) => form.setData('brand_meta_key', e.target.value)} placeholder="_brand" />}
                                <TextField label="Clé de métadonnée pour la Référence (optionnel)" value={form.data.reference_meta_key} onChange={(e) => form.setData('reference_meta_key', e.target.value)} placeholder="_reference_interne" />
                            </div>
                        </details>
                    </div>

                    {Object.keys(form.errors).length > 0 && <FormBanner>Corrigez les champs indiqués.</FormBanner>}

                    {can.manage && (
                        <Button type="submit" loading={form.processing} loadingText="Enregistrement…">
                            {creating ? 'Enregistrer l’intégration' : 'Enregistrer les modifications'}
                        </Button>
                    )}
                </form>

                {/* Status + actions */}
                <div className="space-y-4">
                    <div className="rounded-card border border-line bg-surface p-5 shadow-card">
                        <h2 className="text-sm font-semibold text-ink">État</h2>
                        {integration ? (
                            <dl className="mt-3 space-y-2 text-[13px]">
                                <div className="flex justify-between"><dt className="text-ink-muted">Connexion</dt>
                                    <dd className={integration.last_connection_ok ? 'text-success' : integration.last_connection_ok === false ? 'text-danger' : 'text-ink-muted'}>
                                        {integration.last_connection_ok ? '● Connectée' : integration.last_connection_ok === false ? '● Échec' : 'Non testée'}
                                    </dd>
                                </div>
                                <div className="flex justify-between"><dt className="text-ink-muted">Produits synchronisés</dt><dd className="font-medium text-ink">{syncedCount}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">Dernière synchronisation</dt>
                                    <dd className="text-ink">{integration.last_product_sync_completed_at ? formatDateTime(integration.last_product_sync_completed_at) : 'Jamais'}</dd>
                                </div>
                            </dl>
                        ) : (
                            <p className="mt-2 text-[13px] text-ink-muted">Enregistrez la connexion pour commencer.</p>
                        )}

                        {integration && (
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button type="button" variant="secondary" size="sm" loading={testing} loadingText="Test…" onClick={runTest}>
                                    Tester la connexion
                                </Button>
                                {can.sync && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        loading={dispatching}
                                        loadingText="Démarrage…"
                                        disabled={liveRun?.status === 'running'}
                                        onClick={() => setConfirmSync(true)}
                                    >
                                        {liveRun?.status === 'running' ? 'Synchronisation en cours…' : 'Synchroniser les produits'}
                                    </Button>
                                )}
                            </div>
                        )}
                        {testResult && (
                            <p className={`mt-3 rounded-field px-3 py-2 text-[13px] ${testResult.ok ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'}`}>
                                {testResult.message}
                            </p>
                        )}
                    </div>

                    {liveRun && (
                        <div className="rounded-card border border-line bg-surface p-5 shadow-card">
                            <div className="flex items-center justify-between">
                                <h2 className="text-sm font-semibold text-ink">Synchronisation</h2>
                                <span className={`rounded-full px-2 py-0.5 text-[12px] font-semibold ${
                                    liveRun.status === 'completed' ? 'bg-success-soft text-success'
                                        : liveRun.status === 'failed' ? 'bg-danger-soft text-danger'
                                            : liveRun.status === 'completed_with_errors' ? 'bg-warning-soft text-warning'
                                                : 'bg-sage text-ink-muted'
                                }`}>
                                    {STATUS_LABELS[liveRun.status] ?? liveRun.status}
                                </span>
                            </div>
                            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-[13px]">
                                <div className="flex justify-between"><dt className="text-ink-muted">Lus</dt><dd className="tabular-nums">{liveRun.products_read}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">Créés</dt><dd className="tabular-nums">{liveRun.products_created}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">Mis à jour</dt><dd className="tabular-nums">{liveRun.products_updated}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">Variantes</dt><dd className="tabular-nums">{liveRun.variants_synced}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">Catégories</dt><dd className="tabular-nums">{liveRun.categories_synced}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">Ajust. stock</dt><dd className="tabular-nums">{liveRun.stock_adjustments}</dd></div>
                                <div className="flex justify-between"><dt className="text-ink-muted">En erreur</dt><dd className={`tabular-nums ${liveRun.products_failed > 0 ? 'text-danger' : ''}`}>{liveRun.products_failed}</dd></div>
                            </dl>
                            {liveRun.message && <p className="mt-3 text-[12px] text-ink-muted">{liveRun.message}</p>}
                            {(liveRun.errors?.length ?? 0) > 0 && (
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
                    <h2 className="mb-3 text-sm font-semibold text-ink">Historique</h2>
                    <div className="overflow-x-auto rounded-card border border-line">
                        <table className="min-w-full divide-y divide-line text-[13px]">
                            <thead className="bg-raised text-left text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr><th className="px-3 py-2">Démarrée</th><th className="px-3 py-2">Statut</th><th className="px-3 py-2 text-right">Lus</th><th className="px-3 py-2 text-right">Créés</th><th className="px-3 py-2 text-right">MàJ</th><th className="px-3 py-2 text-right">Erreurs</th></tr>
                            </thead>
                            <tbody className="divide-y divide-line bg-surface">
                                {runs.map((run) => (
                                    <tr key={run.id}>
                                        <td className="px-3 py-2">{run.started_at ? formatDateTime(run.started_at) : '—'}</td>
                                        <td className="px-3 py-2">{STATUS_LABELS[run.status] ?? run.status}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{run.products_read}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{run.products_created}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{run.products_updated}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{run.products_failed}</td>
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
                        <h3 className="text-base font-semibold text-ink">Synchroniser les produits</h3>
                        <p className="mt-2 text-[13px] text-ink-muted">
                            Le catalogue WooCommerce sera analysé et importé dans l’ERP. L’opération est réexécutable sans créer de doublons.
                        </p>
                        <p className="mt-2 rounded-field bg-raised px-3 py-2 text-[13px] text-ink">
                            Stock : {integration.sync_stock ? <>synchronisé vers « {warehouseName} » via des ajustements d’inventaire.</> : 'non modifié (catalogue uniquement).'}
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button type="button" variant="secondary" disabled={dispatching} onClick={() => setConfirmSync(false)}>Annuler</Button>
                            <Button type="button" loading={dispatching} loadingText="Démarrage…" onClick={startSync}>Lancer la synchronisation</Button>
                        </div>
                    </div>
                </div>
            )}
        </ApplicationShell>
    );
}
