import { Button } from '@/components/ui/Button';
import { useFreshAuth } from '@/components/auth/FreshAuthProvider';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import SalesLayout from '@/layouts/SalesLayout';
import { formatDateTime } from '@/utils/format';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Summary = {
    organization: string;
    created_at: string | null;
    version: number;
    products: number;
    customers: number;
    orders: number;
    invoices: number;
    payments: number;
    inventory_movements: number;
    sensitive_columns: Record<string, string[]>;
};

type ValidatedBackup = {
    token: string;
    source: 'private' | 'google_drive';
    source_label: string;
    summary: Summary;
    manifest: {
        created_at: string | null;
        version: number;
        source_organization_id: number;
        source_organization_name: string;
    };
} | null;

type Props = {
    organization: { id: number; name: string };
    validatedBackup: ValidatedBackup;
    settings: {
        enabled: boolean;
        frequency: 'daily' | 'weekly';
        time_of_day: string;
        day_of_week: number | null;
        timezone: string;
        retention_count: number;
        notify_on_failure: boolean;
        next_backup_at: string | null;
        last_success_at: string | null;
        last_failure_at: string | null;
        last_failure_message: string | null;
    };
    history: Array<{
        id: number;
        uuid: string;
        type: 'manual' | 'scheduled' | 'pre_restore';
        status: 'queued' | 'running' | 'completed' | 'failed' | 'deleted_by_retention';
        created_at: string | null;
        scheduled_for: string | null;
        completed_at: string | null;
        size_bytes: number | null;
        storage: 'external' | null;
        failure_message: string | null;
        downloadable: boolean;
        google_drive: {
            id: number;
            status: 'queued' | 'uploading' | 'completed' | 'failed' | 'deleted';
            failure_message: string | null;
            completed_at: string | null;
        } | null;
    }>;
    googleDrive: {
        connected: boolean;
        enabled: boolean;
        account: string | null;
        folder: string | null;
        last_sync_at: string | null;
        last_sync_status: string | null;
        last_sync_error: string | null;
    };
    can: { create: boolean; restore: boolean };
};

export default function OrganizationBackups({ organization, validatedBackup, settings, history, googleDrive, can }: Props) {
    const { t } = useI18n();
    const { submit: submitProtected } = useFreshAuth();
    const { props } = usePage<{ errors?: Record<string, string>; flash?: { status?: string; success?: string } }>();
    const [confirmRestore, setConfirmRestore] = useState(false);
    const [actionProcessing, setActionProcessing] = useState<string | null>(null);
    const upload = useForm<{ backup: File | null }>({ backup: null });
    const scheduleForm = useForm({
        enabled: settings.enabled,
        frequency: settings.frequency,
        time_of_day: settings.time_of_day,
        day_of_week: settings.day_of_week ?? 1,
        timezone: settings.timezone,
        retention_count: settings.retention_count,
        notify_on_failure: settings.notify_on_failure,
    });
    const [restoring, setRestoring] = useState(false);

    const validateBackup = (event: FormEvent) => {
        event.preventDefault();
        upload.post('/organization-backups/validate', { forceFormData: true, preserveScroll: true });
    };

    const saveSchedule = (event: FormEvent) => {
        event.preventDefault();
        scheduleForm.put('/organization-backups/settings', { preserveScroll: true });
    };

    const submitRestore = () => {
        if (!validatedBackup || restoring) return;
        setRestoring(true);
        void submitProtected({
            method: 'post', url: '/organization-backups/restore',
            data: { token: validatedBackup.token, confirm: true }, preserveScroll: true,
            onFinish: () => setRestoring(false),
        });
    };

    const postAction = (key: string, url: string) => {
        if (actionProcessing) return;
        setActionProcessing(key);
        router.post(url, {}, { preserveScroll: true, onFinish: () => setActionProcessing(null) });
    };

    const deleteAction = (key: string, url: string) => {
        if (actionProcessing) return;
        setActionProcessing(key);
        if (url === '/organization-backups/google-drive') {
            void submitProtected({ method: 'delete', url, preserveScroll: true, onFinish: () => setActionProcessing(null) });
            return;
        }
        router.delete(url, { preserveScroll: true, onFinish: () => setActionProcessing(null) });
    };

    return (
        <SalesLayout>
            <Head title={t('backups.title')} />
            <div className="mx-auto max-w-5xl space-y-6">
                <PageHeader
                    title={t('backups.title')}
                    description={t('backups.description').replace('{organization}', organization.name)}
                />

                {(props.flash?.status || props.flash?.success) && (
                    <div className="rounded-card border border-success/30 bg-success-soft px-4 py-3 text-sm text-success">
                        {props.flash.status ?? props.flash.success}
                    </div>
                )}
                {(props.errors?.backup || props.errors?.restore || props.errors?.cloud) && (
                    <div className="rounded-card border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger">
                        {props.errors.backup ?? props.errors.restore ?? props.errors.cloud}
                    </div>
                )}

                <section className="rounded-card border border-line bg-surface p-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-semibold text-ink">{t('backups.manual')}</h2>
                            <p className="mt-1 max-w-2xl text-sm text-ink-muted">
                                {t('backups.manualHelp')}
                            </p>
                        </div>
                        <form method="post" action="/organization-backups">
                            <input type="hidden" name="_token" value={(document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''} />
                            <Button type="submit" disabled={!can.create}>
                                {t('backups.create')}
                            </Button>
                        </form>
                    </div>
                    {!can.create && <p className="mt-3 text-sm text-ink-muted">{t('backups.noCreatePermission')}</p>}
                </section>

                <section className="rounded-card border border-line bg-surface p-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-semibold text-ink">{t('backups.automatic')}</h2>
                            <p className="mt-1 max-w-3xl text-sm text-ink-muted">
                                {t('backups.automaticHelp')}
                            </p>
                        </div>
                        <span className={settings.enabled ? 'rounded-full bg-success-soft px-3 py-1 text-xs font-semibold text-success' : 'rounded-full bg-muted px-3 py-1 text-xs font-semibold text-ink-muted'}>
                            {settings.enabled ? t('backups.enabled') : t('backups.disabled')}
                        </span>
                    </div>

                    <div className="mt-5 grid gap-3 text-sm md:grid-cols-3">
                        <Info label={t('backups.next')} value={settings.next_backup_at ? formatDateTime(settings.next_backup_at) : '—'} />
                        <Info label={t('backups.lastSuccess')} value={settings.last_success_at ? formatDateTime(settings.last_success_at) : '—'} />
                        <Info label={t('backups.lastFailure')} value={settings.last_failure_at ? formatDateTime(settings.last_failure_at) : '—'} />
                    </div>
                    {settings.last_failure_message && <p className="mt-3 text-sm text-danger">{t('backups.lastError').replace('{error}', settings.last_failure_message)}</p>}

                    <form onSubmit={saveSchedule} className="mt-6 grid gap-4 md:grid-cols-2">
                        <label className="flex items-center gap-2 text-sm font-medium text-ink md:col-span-2">
                            <input
                                type="checkbox"
                                checked={scheduleForm.data.enabled}
                                onChange={(event) => scheduleForm.setData('enabled', event.currentTarget.checked)}
                                className="rounded border-line-strong"
                                disabled={!can.create}
                            />
                            {t('backups.enableAutomatic')}
                        </label>

                        <label className="block text-sm font-medium text-ink">
                            {t('backups.frequency')}
                            <select
                                value={scheduleForm.data.frequency}
                                onChange={(event) => scheduleForm.setData('frequency', event.currentTarget.value as 'daily' | 'weekly')}
                                className="mt-1 block w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                                disabled={!can.create}
                            >
                                <option value="daily">{t('backups.daily')}</option>
                                <option value="weekly">{t('backups.weekly')}</option>
                            </select>
                        </label>

                        <label className="block text-sm font-medium text-ink">
                            {t('backups.time')}
                            <input
                                type="time"
                                value={scheduleForm.data.time_of_day}
                                onChange={(event) => scheduleForm.setData('time_of_day', event.currentTarget.value)}
                                className="mt-1 block w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                                disabled={!can.create}
                            />
                        </label>

                        {scheduleForm.data.frequency === 'weekly' && (
                            <label className="block text-sm font-medium text-ink">
                                {t('backups.weekday')}
                                <select
                                    value={scheduleForm.data.day_of_week}
                                    onChange={(event) => scheduleForm.setData('day_of_week', Number(event.currentTarget.value))}
                                    className="mt-1 block w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                                    disabled={!can.create}
                                >
                                    <option value={1}>{t('backups.monday')}</option>
                                    <option value={2}>{t('backups.tuesday')}</option>
                                    <option value={3}>{t('backups.wednesday')}</option>
                                    <option value={4}>{t('backups.thursday')}</option>
                                    <option value={5}>{t('backups.friday')}</option>
                                    <option value={6}>{t('backups.saturday')}</option>
                                    <option value={0}>{t('backups.sunday')}</option>
                                </select>
                            </label>
                        )}

                        <label className="block text-sm font-medium text-ink">
                            {t('backups.timezone')}
                            <input
                                type="text"
                                value={scheduleForm.data.timezone}
                                onChange={(event) => scheduleForm.setData('timezone', event.currentTarget.value)}
                                className="mt-1 block w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                                disabled={!can.create}
                                placeholder="Africa/Casablanca"
                            />
                        </label>

                        <label className="block text-sm font-medium text-ink">
                            {t('backups.retention')}
                            <input
                                type="number"
                                min={7}
                                max={90}
                                value={scheduleForm.data.retention_count}
                                onChange={(event) => scheduleForm.setData('retention_count', Number(event.currentTarget.value))}
                                className="mt-1 block w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                                disabled={!can.create}
                            />
                            <span className="mt-1 block text-xs text-ink-muted">{t('backups.retentionHelp')}</span>
                        </label>

                        <label className="flex items-center gap-2 text-sm font-medium text-ink md:col-span-2">
                            <input
                                type="checkbox"
                                checked={scheduleForm.data.notify_on_failure}
                                onChange={(event) => scheduleForm.setData('notify_on_failure', event.currentTarget.checked)}
                                className="rounded border-line-strong"
                                disabled={!can.create}
                            />
                            {t('backups.notifyFailure')}
                        </label>

                        <div className="md:col-span-2">
                            <Button type="submit" disabled={!can.create} loading={scheduleForm.processing} loadingText={t('common.saving')}>
                                {t('backups.saveSchedule')}
                            </Button>
                        </div>
                    </form>
                </section>

                <section className="rounded-card border border-line bg-surface p-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">{t('backups.personalStorage')}</p>
                            <h2 className="mt-1 text-lg font-semibold text-ink">Google Drive</h2>
                            <p className="mt-1 max-w-3xl text-sm text-ink-muted">
                                {t('backups.driveHelp')}
                            </p>
                        </div>
                        <span className={googleDrive.connected ? 'rounded-full bg-success-soft px-3 py-1 text-xs font-semibold text-success' : 'rounded-full bg-muted px-3 py-1 text-xs font-semibold text-ink-muted'}>
                            {googleDrive.connected ? t('backups.connected') : t('backups.notConnected')}
                        </span>
                    </div>

                    {googleDrive.connected ? (
                        <div className="mt-5 space-y-4">
                            <dl className="grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-4">
                                <Info label={t('backups.account')} value={googleDrive.account ?? t('backups.googleAccount')} />
                                <Info label={t('backups.folder')} value={googleDrive.folder ?? '10xScale ERP / Sauvegardes'} />
                                <Info label={t('backups.automaticSync')} value={googleDrive.enabled ? t('backups.enabled') : t('backups.disabled')} />
                                <Info label={t('backups.lastSync')} value={googleDrive.last_sync_at ? formatDateTime(googleDrive.last_sync_at) : '—'} />
                            </dl>
                            {googleDrive.last_sync_error && <p className="text-sm text-danger">{googleDrive.last_sync_error}</p>}
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" variant="secondary" disabled={!can.create || actionProcessing === 'drive-test'} onClick={() => postAction('drive-test', '/organization-backups/google-drive/test')}>
                                    {t('backups.connectionTest')}
                                </Button>
                                <Button type="button" variant="danger" disabled={!can.create || actionProcessing === 'drive-disconnect'} onClick={() => deleteAction('drive-disconnect', '/organization-backups/google-drive')}>
                                    {t('backups.disconnect')}
                                </Button>
                            </div>
                            <p className="text-xs text-ink-muted">
                                {t('backups.disconnectHelp')}
                            </p>
                        </div>
                    ) : (
                        <div className="mt-5">
                            <Button type="button" disabled={!can.create} onClick={() => { window.location.href = '/organization-backups/google-drive/connect'; }}>
                                {t('backups.connectDrive')}
                            </Button>
                        </div>
                    )}
                </section>

                <section className="rounded-card border border-line bg-surface p-6">
                    <h2 className="text-lg font-semibold text-ink">{t('backups.restoreTitle')}</h2>
                    <p className="mt-1 max-w-3xl text-sm text-ink-muted">
                        {t('backups.restoreHelp')}
                    </p>

                    <form onSubmit={validateBackup} className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label className="block flex-1 text-sm font-medium text-ink">
                            {t('backups.file')}
                            <input
                                type="file"
                                accept=".erpbackup"
                                disabled={!can.restore || upload.processing}
                                onChange={(event) => upload.setData('backup', event.currentTarget.files?.[0] ?? null)}
                                className="mt-1 block w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                            />
                        </label>
                        <Button type="submit" disabled={!can.restore || !upload.data.backup} loading={upload.processing} loadingText={t('backups.validating')}>
                            {t('backups.verify')}
                        </Button>
                    </form>
                    {!can.restore && <p className="mt-3 text-sm text-ink-muted">{t('backups.noRestorePermission')}</p>}

                    {validatedBackup && (
                        <div className="mt-6 rounded-card border border-line bg-raised p-5">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h3 className="font-semibold text-ink">{t('backups.validated')}</h3>
                                <span className="rounded-full bg-muted px-3 py-1 text-xs font-semibold text-ink-muted">
                                    {t('backups.source')} : {validatedBackup.source_label}
                                </span>
                            </div>
                            <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                <Info label={t('backups.source')} value={validatedBackup.source_label} />
                                <Info label={t('common.organization')} value={validatedBackup.summary.organization} />
                                <Info label={t('common.date')} value={validatedBackup.summary.created_at ? formatDateTime(validatedBackup.summary.created_at) : '—'} />
                                <Info label={t('backups.formatVersion')} value={`v${validatedBackup.summary.version}`} />
                                <Info label={t('products.title')} value={validatedBackup.summary.products.toString()} />
                                <Info label={t('customers.title')} value={validatedBackup.summary.customers.toString()} />
                                <Info label={t('sales.orders.title')} value={validatedBackup.summary.orders.toString()} />
                                <Info label={t('invoices.title')} value={validatedBackup.summary.invoices.toString()} />
                                <Info label={t('payments.title')} value={validatedBackup.summary.payments.toString()} />
                                <Info label={t('backups.movements')} value={validatedBackup.summary.inventory_movements.toString()} />
                            </dl>

                            {Object.keys(validatedBackup.summary.sensitive_columns ?? {}).length > 0 && (
                                <p className="mt-4 text-sm text-warning">
                                    {t('backups.secretsEncrypted')}
                                </p>
                            )}

                            <div className="mt-5 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm text-danger">
                                {t('backups.restoreWarning')}
                            </div>
                            <div className="mt-4 flex flex-wrap items-center gap-3">
                                <Button type="button" variant="danger" disabled={!can.restore || restoring} onClick={() => setConfirmRestore(true)}>
                                    {t('backups.restoreThis')}
                                </Button>
                                {confirmRestore && (
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="text-danger">{t('backups.confirmReplace')}</span>
                                        <Button type="button" variant="danger" loading={restoring} loadingText={t('backups.restoring')} onClick={submitRestore}>
                                            {t('backups.yesRestore')}
                                        </Button>
                                        <Button type="button" variant="secondary" onClick={() => setConfirmRestore(false)}>
                                            {t('common.cancel')}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                </section>

                <section className="rounded-card border border-line bg-surface p-6">
                    <h2 className="text-lg font-semibold text-ink">{t('backups.history')}</h2>
                    <p className="mt-1 text-sm text-ink-muted">{t('backups.historyHelp')}</p>

                    <div className="mt-5 overflow-x-auto">
                        <table className="min-w-full text-start text-sm">
                            <thead className="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th className="py-3 pe-4">{t('common.date')}</th>
                                    <th className="py-3 pe-4">{t('backups.type')}</th>
                                    <th className="py-3 pe-4">{t('common.status')}</th>
                                    <th className="py-3 pe-4">{t('backups.size')}</th>
                                    <th className="py-3 pe-4">{t('backups.storage')}</th>
                                    <th className="py-3 pr-4">Google Drive</th>
                                    <th className="py-3 pe-4 text-end">{t('backups.action')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {history.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="py-6 text-center text-ink-muted">
                                            {t('backups.empty')}
                                        </td>
                                    </tr>
                                )}
                                {history.map((backup) => (
                                    <tr key={backup.id}>
                                        <td className="py-3 pe-4">{backup.completed_at || backup.scheduled_for || backup.created_at ? formatDateTime(backup.completed_at ?? backup.scheduled_for ?? backup.created_at ?? '') : '—'}</td>
                                        <td className="py-3 pe-4">{backup.type === 'scheduled' ? t('backups.scheduled') : backup.type === 'pre_restore' ? t('backups.preRestore') : t('backups.manualType')}</td>
                                        <td className="py-3 pr-4">
                                            <Status status={backup.status} />
                                            {backup.failure_message && <div className="mt-1 text-xs text-danger">{backup.failure_message}</div>}
                                        </td>
                                        <td className="py-3 pe-4">{backup.size_bytes ? formatBytes(backup.size_bytes, t) : '—'}</td>
                                        <td className="py-3 pe-4">{backup.storage === 'external' ? t('backups.privateStorage') : '—'}</td>
                                        <td className="py-3 pr-4">
                                            <CloudStatus copy={backup.google_drive} />
                                        </td>
                                        <td className="py-3 pe-4 text-end">
                                            {backup.downloadable ? (
                                                <div className="flex flex-wrap justify-end gap-2 rtl:justify-start">
                                                    <a href={`/organization-backups/${backup.id}/download`} className="text-sm font-semibold text-primary hover:underline">
                                                        {t('backups.download')}
                                                    </a>
                                                    {can.restore && (
                                                        <button
                                                            type="button"
                                                            disabled={actionProcessing === `restore-${backup.id}`}
                                                            onClick={() => postAction(`restore-${backup.id}`, `/organization-backups/${backup.id}/validate`)}
                                                            className="text-sm font-semibold text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            {t('backups.restore')}
                                                        </button>
                                                    )}
                                                    {googleDrive.connected && can.create && !backup.google_drive && (
                                                        <button
                                                            type="button"
                                                            disabled={actionProcessing === `copy-drive-${backup.id}`}
                                                            onClick={() => postAction(`copy-drive-${backup.id}`, `/organization-backups/${backup.id}/copy/google-drive`)}
                                                            className="text-sm font-semibold text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            {t('backups.copyDrive')}
                                                        </button>
                                                    )}
                                                    {backup.google_drive?.status === 'failed' && can.create && (
                                                        <button
                                                            type="button"
                                                            disabled={actionProcessing === `retry-drive-${backup.google_drive.id}`}
                                                            onClick={() => postAction(`retry-drive-${backup.google_drive?.id}`, `/organization-backups/cloud-copies/${backup.google_drive?.id}/retry`)}
                                                            className="text-sm font-semibold text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            {t('backups.retryDrive')}
                                                        </button>
                                                    )}
                                                    {backup.google_drive?.status === 'completed' && can.restore && (
                                                        <button
                                                            type="button"
                                                            disabled={actionProcessing === `restore-drive-${backup.google_drive.id}`}
                                                            onClick={() => postAction(`restore-drive-${backup.google_drive?.id}`, `/organization-backups/cloud-copies/${backup.google_drive?.id}/validate`)}
                                                            className="text-sm font-semibold text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            {t('backups.restoreDrive')}
                                                        </button>
                                                    )}
                                                </div>
                                            ) : (
                                                <span className="text-ink-muted">—</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </SalesLayout>
    );
}

function CloudStatus({ copy }: { copy: Props['history'][number]['google_drive'] }) {
    const { t } = useI18n();
    if (!copy) {
        return <span className="text-ink-muted">—</span>;
    }

    if (copy.status === 'completed') {
        return <span className="rounded-full bg-success-soft px-2 py-1 text-xs font-semibold text-success">{t('backups.synced')}</span>;
    }
    if (copy.status === 'failed') {
        return (
            <span>
                <span className="rounded-full bg-danger-soft px-2 py-1 text-xs font-semibold text-danger">{t('backups.failed')}</span>
                {copy.failure_message && <span className="mt-1 block text-xs text-danger">{copy.failure_message}</span>}
            </span>
        );
    }
    if (copy.status === 'uploading') {
        return <span className="rounded-full bg-muted px-2 py-1 text-xs font-semibold text-ink-muted">{t('backups.syncing')}</span>;
    }

    return <span className="rounded-full bg-muted px-2 py-1 text-xs font-semibold text-ink-muted">{t('backups.pending')}</span>;
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-ink-muted">{label}</dt>
            <dd className="font-medium text-ink">{value}</dd>
        </div>
    );
}

function Status({ status }: { status: Props['history'][number]['status'] }) {
    const { t } = useI18n();
    const labels: Record<Props['history'][number]['status'], string> = {
        queued: t('backups.pending'),
        running: t('backups.running'),
        completed: t('backups.successful'),
        failed: t('backups.failureStatus'),
        deleted_by_retention: t('backups.deleted'),
    };
    const className =
        status === 'completed'
            ? 'bg-success-soft text-success'
            : status === 'failed'
              ? 'bg-danger-soft text-danger'
              : 'bg-muted text-ink-muted';

    return <span className={`rounded-full px-2 py-1 text-xs font-semibold ${className}`}>{labels[status]}</span>;
}

function formatBytes(value: number, t: ReturnType<typeof useI18n>['t']) {
    if (value < 1024) return t('backups.bytes').replace('{value}', String(value));
    if (value < 1024 * 1024) return t('backups.kilobytes').replace('{value}', (value / 1024).toFixed(1));

    return t('backups.megabytes').replace('{value}', (value / 1024 / 1024).toFixed(1));
}
