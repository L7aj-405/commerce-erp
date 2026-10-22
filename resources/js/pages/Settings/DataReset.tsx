import { useFreshAuth } from '@/components/auth/FreshAuthProvider';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import PageHeader from '@/components/ui/PageHeader';
import { Spinner } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/toast';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDateTime, formatInteger } from '@/utils/format';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

type ResetScope =
    | 'sales_and_documents'
    | 'transactional_finance'
    | 'catalog_and_stock'
    | 'customers'
    | 'suppliers_and_purchasing'
    | 'users_and_access'
    | 'full_organization';

type AffectedGroup = {
    scope: ResetScope;
    affected_records: number;
    deleted_records: number;
    updated_records: number;
    operations: number;
};

type ResetBlocker = {
    code: string;
    count: number;
    required_scopes: ResetScope[];
};

type ResetPreview = {
    scope: ResetScope;
    included_scopes: ResetScope[];
    fingerprint: string;
    previewed_at: string;
    executable: boolean;
    affected_records: number;
    affected: AffectedGroup[];
    blockers: ResetBlocker[];
    preserved: string[];
    confirmation_phrase: string;
};

type Props = { organization: { id: number; name: string } };

const scopes: ResetScope[] = [
    'sales_and_documents',
    'transactional_finance',
    'catalog_and_stock',
    'customers',
    'suppliers_and_purchasing',
    'users_and_access',
    'full_organization',
];

const contentKeys: Record<ResetScope, { description: TranslationKey; deleted: TranslationKey; preserved: TranslationKey }> = {
    sales_and_documents: { description: 'dataReset.sales.description', deleted: 'dataReset.sales.deleted', preserved: 'dataReset.sales.preserved' },
    transactional_finance: { description: 'dataReset.finance.description', deleted: 'dataReset.finance.deleted', preserved: 'dataReset.finance.preserved' },
    catalog_and_stock: { description: 'dataReset.catalog.description', deleted: 'dataReset.catalog.deleted', preserved: 'dataReset.catalog.preserved' },
    customers: { description: 'dataReset.customers.description', deleted: 'dataReset.customers.deleted', preserved: 'dataReset.customers.preserved' },
    suppliers_and_purchasing: { description: 'dataReset.suppliers.description', deleted: 'dataReset.suppliers.deleted', preserved: 'dataReset.suppliers.preserved' },
    users_and_access: { description: 'dataReset.access.description', deleted: 'dataReset.access.deleted', preserved: 'dataReset.access.preserved' },
    full_organization: { description: 'dataReset.full.description', deleted: 'dataReset.full.deleted', preserved: 'dataReset.full.preserved' },
};

const scopeKeys: Record<ResetScope, TranslationKey> = {
    sales_and_documents: 'dataReset.scope.sales_and_documents',
    transactional_finance: 'dataReset.scope.transactional_finance',
    catalog_and_stock: 'dataReset.scope.catalog_and_stock',
    customers: 'dataReset.scope.customers',
    suppliers_and_purchasing: 'dataReset.scope.suppliers_and_purchasing',
    users_and_access: 'dataReset.scope.users_and_access',
    full_organization: 'dataReset.scope.full_organization',
};

const blockerKeys: Record<string, TranslationKey> = {
    'sales.shared_stock_transfer': 'dataReset.blocker.sales.shared_stock_transfer',
    'finance.sales_dependencies': 'dataReset.blocker.finance.sales_dependencies',
    'catalog.sales_dependencies': 'dataReset.blocker.catalog.sales_dependencies',
    'catalog.procurement_dependencies': 'dataReset.blocker.catalog.procurement_dependencies',
    'customers.sales_dependencies': 'dataReset.blocker.customers.sales_dependencies',
    'suppliers.finance_dependencies': 'dataReset.blocker.suppliers.finance_dependencies',
    'suppliers.sales_dependencies': 'dataReset.blocker.suppliers.sales_dependencies',
    'suppliers.inventory_dependencies': 'dataReset.blocker.suppliers.inventory_dependencies',
    'suppliers.recurring_configuration': 'dataReset.blocker.suppliers.recurring_configuration',
    'access.owner_custom_role': 'dataReset.blocker.access.owner_custom_role',
};

const preservedKeys: Record<string, TranslationKey> = {
    organization: 'dataReset.preserved.organization',
    primary_owner_security: 'dataReset.preserved.primary_owner_security',
    stores_and_warehouses: 'dataReset.preserved.stores_and_warehouses',
    credentials_and_integrations: 'dataReset.preserved.credentials_and_integrations',
    numbering_and_sequences: 'dataReset.preserved.numbering_and_sequences',
    audit_history: 'dataReset.preserved.audit_history',
};

export default function DataReset({ organization }: Props) {
    const { t } = useI18n();
    const { request } = useFreshAuth();
    const toast = useToast();
    const [selectedScope, setSelectedScope] = useState<ResetScope | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    const [preview, setPreview] = useState<ResetPreview | null>(null);
    const [previewing, setPreviewing] = useState<ResetScope | null>(null);
    const [previewError, setPreviewError] = useState('');
    const [organizationName, setOrganizationName] = useState('');
    const [confirmationPhrase, setConfirmationPhrase] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [executing, setExecuting] = useState(false);
    const [completed, setCompleted] = useState<{ scope: ResetScope; affected: number } | null>(null);

    const loadPreview = async (scope: ResetScope) => {
        if (previewing || executing) return;
        setPreviewing(scope);
        setSelectedScope(scope);
        setModalOpen(true);
        setPreview(null);
        setCompleted(null);
        setPreviewError('');
        setErrors({});
        try {
            const response = await request<{ data: ResetPreview }>({
                method: 'post',
                url: '/settings/data-reset/preview',
                data: { scope },
            });
            setPreview(response.data.data);
        } catch {
            setPreviewError(t('dataReset.previewError'));
        } finally {
            setPreviewing(null);
        }
    };

    const closeModal = () => {
        if (executing) return;
        const shouldRefresh = completed !== null;
        setModalOpen(false);
        setSelectedScope(null);
        setPreview(null);
        setPreviewError('');
        setOrganizationName('');
        setConfirmationPhrase('');
        setErrors({});
        setCompleted(null);
        if (shouldRefresh) router.reload();
    };

    const execute = async () => {
        if (!preview || executing) return;
        const nextErrors: Record<string, string> = {};
        if (organizationName !== organization.name) nextErrors.organization_name = t('dataReset.confirmMismatch');
        if (confirmationPhrase !== preview.confirmation_phrase) nextErrors.confirmation_phrase = t('dataReset.confirmMismatch');
        if (Object.keys(nextErrors).length > 0) {
            setErrors(nextErrors);
            return;
        }

        setExecuting(true);
        setErrors({});
        try {
            const response = await request<{ data: { scope: ResetScope; affected_records: number; completed: boolean } }>({
                method: 'post',
                url: '/settings/data-reset/execute',
                data: {
                    scope: preview.scope,
                    fingerprint: preview.fingerprint,
                    confirmation_phrase: confirmationPhrase,
                    organization_name: organizationName,
                },
            });
            setCompleted({ scope: response.data.data.scope, affected: response.data.data.affected_records });
            toast.success(t('dataReset.success'));
        } catch (error) {
            if (axios.isCancel(error)) return;
            if (axios.isAxiosError(error)) {
                const payload = error.response?.data as { code?: string; errors?: Record<string, string[] | string> } | undefined;
                if (error.response?.status === 422 && payload?.errors) {
                    setErrors(Object.fromEntries(Object.entries(payload.errors).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value])));
                    return;
                }
                if (payload?.code === 'STALE_RESET_PREVIEW') {
                    setPreview(null);
                    setPreviewError(t('dataReset.stale'));
                    toast.warning(t('dataReset.stale'));
                    return;
                }
                if (payload?.code === 'RESET_BLOCKED') {
                    setPreview(null);
                    setPreviewError(t('dataReset.stale'));
                    toast.warning(t('dataReset.stale'));
                    return;
                }
            }
            toast.error(t('dataReset.executeError'));
        } finally {
            setExecuting(false);
        }
    };

    return (
        <ApplicationShell wide>
            <Head title={t('dataReset.title')} />
            <PageHeader title={t('dataReset.title')} description={t('dataReset.description')} />

            <div className="mb-6 flex gap-3 rounded-card border border-warning/25 bg-warning-soft px-4 py-3 text-sm leading-6 text-warning">
                <DangerIcon />
                <p>{t('dataReset.warning')}</p>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {scopes.map((scope) => {
                    const full = scope === 'full_organization';
                    const keys = contentKeys[scope];
                    const active = modalOpen && selectedScope === scope;
                    return (
                        <article
                            key={scope}
                            className={`rounded-card border bg-surface p-5 shadow-soft ${full ? 'border-danger/40 lg:col-span-2' : active ? 'border-primary/50' : 'border-line'}`}
                        >
                            <div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-3">
                                        <span className={`flex size-10 shrink-0 items-center justify-center rounded-full ${full ? 'bg-danger-soft text-danger' : 'bg-sage text-primary'}`}>
                                            {full ? <DangerIcon /> : <ResetIcon />}
                                        </span>
                                        <div>
                                            <h2 className={`font-semibold ${full ? 'text-danger' : 'text-ink'}`}>{t(scopeKeys[scope])}</h2>
                                            <p className="mt-1 text-sm leading-6 text-ink-muted">{t(keys.description)}</p>
                                        </div>
                                    </div>
                                    {full && <p className="mt-4 rounded-field bg-danger-soft px-3 py-2 text-sm text-danger">{t('dataReset.fullDanger')}</p>}
                                    <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                                        <InfoBlock label={t('dataReset.deleted')} value={t(keys.deleted)} danger />
                                        <InfoBlock label={t('dataReset.preserved')} value={t(keys.preserved)} />
                                    </dl>
                                </div>
                                <Button
                                    type="button"
                                    variant={full ? 'danger' : 'secondary'}
                                    loading={previewing === scope}
                                    loadingText={t('dataReset.previewing')}
                                    disabled={previewing !== null || executing}
                                    onClick={() => void loadPreview(scope)}
                                    className="shrink-0"
                                >
                                    {t('dataReset.preview')}
                                </Button>
                            </div>
                        </article>
                    );
                })}
            </div>

            <Dialog
                open={modalOpen && selectedScope !== null}
                onClose={closeModal}
                title={selectedScope ? `${t('dataReset.previewTitle')} · ${t(scopeKeys[selectedScope])}` : t('dataReset.previewTitle')}
                description={selectedScope ? t(contentKeys[selectedScope].description) : undefined}
                dismissible={!executing}
                danger={selectedScope === 'full_organization'}
                footer={selectedScope ? (
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" disabled={executing} onClick={closeModal}>{t('common.close')}</Button>
                        {previewError && <Button type="button" variant="secondary" disabled={executing || previewing !== null} onClick={() => void loadPreview(selectedScope)}>{t('dataReset.retryPreview')}</Button>}
                        {preview?.executable && !completed && (
                            <Button type="button" variant="danger" loading={executing} loadingText={t('dataReset.executing')} onClick={() => void execute()}>
                                {t('dataReset.execute')}
                            </Button>
                        )}
                    </div>
                ) : undefined}
            >
                {previewing !== null && (
                    <div className="flex min-h-64 flex-col items-center justify-center gap-3 p-8 text-center text-ink-muted" role="status">
                        <Spinner size="lg" />
                        <p className="text-sm">{t('dataReset.previewing')}</p>
                    </div>
                )}
                {!previewing && previewError && (
                    <div className="m-5 rounded-field border border-danger/25 bg-danger-soft p-5 text-sm text-danger" role="alert">
                        {previewError}
                    </div>
                )}
                {!previewing && completed && (
                    <div className="flex min-h-64 flex-col items-center justify-center p-6 text-center" role="status">
                        <span className="flex size-12 items-center justify-center rounded-full bg-success-soft text-success"><CheckIcon /></span>
                        <h3 className="mt-4 text-lg font-semibold text-success">{t('dataReset.success')}</h3>
                        <p className="mt-2 text-sm text-ink-muted">
                            {t(scopeKeys[completed.scope])} · {t('dataReset.successAffected').replace('{count}', formatInteger(completed.affected))}
                        </p>
                    </div>
                )}
                {!previewing && preview && !completed && (
                    <div className="p-4 sm:p-6">
                        {preview.scope === 'full_organization' && (
                            <p className="mb-5 rounded-field border border-danger/25 bg-danger-soft px-4 py-3 text-sm font-medium text-danger">{t('dataReset.fullDanger')}</p>
                        )}
                        <PreviewPanel preview={preview} />
                        {preview.executable && (
                            <div className="mt-6 border-t border-line pt-6">
                                <h3 className="font-semibold text-ink">{t('dataReset.confirmTitle')}</h3>
                                <p className="mt-1 text-sm text-ink-muted">{t('dataReset.confirmDescription')}</p>
                                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label className="block text-sm font-medium text-ink">
                                        {t('dataReset.organizationName')}
                                        <span className="mt-1 block text-xs font-normal text-ink-muted">{t('dataReset.organizationNameHelp').replace('{name}', organization.name)}</span>
                                        <input data-autofocus value={organizationName} onChange={(event) => setOrganizationName(event.target.value)} autoComplete="off" disabled={executing} className="control-input mt-2 w-full" aria-invalid={Boolean(errors.organization_name)} />
                                        {errors.organization_name && <span className="mt-1 block text-xs text-danger">{errors.organization_name}</span>}
                                    </label>
                                    <label className="block text-sm font-medium text-ink">
                                        {t('dataReset.phrase')}
                                        <span className="mt-1 block text-xs font-normal text-ink-muted">{t('dataReset.phraseHelp').replace('{phrase}', preview.confirmation_phrase)}</span>
                                        <input value={confirmationPhrase} onChange={(event) => setConfirmationPhrase(event.target.value)} autoComplete="off" disabled={executing} dir="ltr" className="control-input mt-2 w-full font-mono" aria-invalid={Boolean(errors.confirmation_phrase)} />
                                        {errors.confirmation_phrase && <span className="mt-1 block text-xs text-danger">{errors.confirmation_phrase}</span>}
                                    </label>
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </Dialog>
        </ApplicationShell>
    );
}

function PreviewPanel({ preview }: { preview: ResetPreview }) {
    const { t } = useI18n();
    return (
        <section>
            <div className="flex flex-col gap-3 border-b border-line pb-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold text-ink">{t('dataReset.previewTitle')} · {t(scopeKeys[preview.scope])}</h2>
                    <p className="mt-1 text-sm text-ink-muted">{t('dataReset.previewedAt')} <bdi>{formatDateTime(preview.previewed_at)}</bdi></p>
                </div>
                <span className={`inline-flex w-fit rounded-full px-3 py-1 text-xs font-semibold ${preview.executable ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'}`}>
                    {preview.executable ? t('dataReset.safeToReset') : t('dataReset.blockers')}
                </span>
            </div>

            <div className="mt-5 grid gap-3 sm:grid-cols-3">
                <Metric label={t('dataReset.affected')} value={preview.affected_records} />
                <Metric label={t('dataReset.deleted')} value={preview.affected.reduce((sum, item) => sum + item.deleted_records, 0)} danger />
                <Metric label={t('dataReset.updated')} value={preview.affected.reduce((sum, item) => sum + item.updated_records, 0)} />
            </div>

            <div className="mt-6 grid gap-6 xl:grid-cols-2">
                <div>
                    <h3 className="text-sm font-semibold text-ink">{t('dataReset.affected')}</h3>
                    <ul className="mt-3 divide-y divide-line rounded-field border border-line">
                        {preview.affected.filter((item) => item.affected_records > 0).map((item) => (
                            <li key={item.scope} className="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                                <span className="text-ink-muted">{t(scopeKeys[item.scope])}</span>
                                <span className="font-semibold tabular-nums text-ink">{formatInteger(item.affected_records)}</span>
                            </li>
                        ))}
                        {preview.affected.every((item) => item.affected_records === 0) && <li className="px-4 py-3 text-sm text-ink-muted">0</li>}
                    </ul>
                </div>
                <div>
                    <h3 className="text-sm font-semibold text-ink">{t('dataReset.preserved')}</h3>
                    <ul className="mt-3 space-y-2">
                        {preview.preserved.map((item) => (
                            <li key={item} className="flex items-start gap-2 text-sm text-ink-muted">
                                <CheckIcon />
                                <span>{t(preservedKeys[item] ?? 'dataReset.preserved.organization')}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            {preview.blockers.length > 0 ? (
                <div className="mt-6 rounded-field border border-danger/25 bg-danger-soft p-4">
                    <h3 className="font-semibold text-danger">{t('dataReset.blockers')}</h3>
                    <p className="mt-1 text-sm text-danger">{t('dataReset.blockedHelp')}</p>
                    <ul className="mt-3 space-y-3">
                        {preview.blockers.map((blocker) => (
                            <li key={blocker.code} className="rounded-field bg-surface/70 p-3 text-sm text-ink">
                                <div className="flex items-start justify-between gap-3">
                                    <span>{t(blockerKeys[blocker.code] ?? 'dataReset.blocker.unknown')}</span>
                                    <span className="shrink-0 font-semibold tabular-nums text-danger">{formatInteger(blocker.count)}</span>
                                </div>
                                {blocker.required_scopes.length > 0 && (
                                    <p className="mt-2 text-xs text-ink-muted">
                                        {t('dataReset.requiredScopes')} : {blocker.required_scopes.map((scope) => t(scopeKeys[scope])).join(' · ')}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </section>
    );
}

function InfoBlock({ label, value, danger = false }: { label: string; value: string; danger?: boolean }) {
    return <div className="rounded-field bg-raised p-3"><dt className={`text-xs font-semibold uppercase tracking-wide ${danger ? 'text-danger' : 'text-ink-faint'}`}>{label}</dt><dd className="mt-1 text-sm leading-5 text-ink-muted">{value}</dd></div>;
}

function Metric({ label, value, danger = false }: { label: string; value: number; danger?: boolean }) {
    return <div className="rounded-field bg-raised p-4"><p className="text-xs font-semibold uppercase tracking-wide text-ink-faint">{label}</p><p className={`mt-2 text-2xl font-semibold tabular-nums ${danger ? 'text-danger' : 'text-ink'}`}>{formatInteger(value)}</p></div>;
}

function ResetIcon() { return <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M4 4v6h6M20 20v-6h-6"/><path d="M5.5 15a8 8 0 0 0 13-3M18.5 9a8 8 0 0 0-13 3"/></svg>; }
function DangerIcon() { return <svg className="mt-0.5 shrink-0" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 9v5M12 18h.01"/></svg>; }
function CheckIcon() { return <svg className="mt-0.5 shrink-0 text-success" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="m5 13 4 4L19 7"/></svg>; }
