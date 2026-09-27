import { Button } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
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
        router.post(
            '/organization-backups/restore',
            { token: validatedBackup.token, confirm: true },
            { preserveScroll: true, onFinish: () => setRestoring(false) },
        );
    };

    const postAction = (key: string, url: string) => {
        if (actionProcessing) return;
        setActionProcessing(key);
        router.post(url, {}, { preserveScroll: true, onFinish: () => setActionProcessing(null) });
    };

    const deleteAction = (key: string, url: string) => {
        if (actionProcessing) return;
        setActionProcessing(key);
        router.delete(url, { preserveScroll: true, onFinish: () => setActionProcessing(null) });
    };

    return (
        <SalesLayout>
            <Head title="Sauvegardes & restauration" />
            <div className="mx-auto max-w-5xl space-y-6">
                <PageHeader
                    title="Sauvegardes & restauration"
                    description={`Sauvegardes manuelles et restauration sécurisée de ${organization.name}.`}
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
                            <h2 className="text-lg font-semibold text-ink">Sauvegarde manuelle</h2>
                            <p className="mt-1 max-w-2xl text-sm text-ink-muted">
                                Crée une archive applicative de l’organisation active. Le fichier est téléchargé directement et ne crée pas
                                d’URL publique permanente.
                            </p>
                        </div>
                        <form method="post" action="/organization-backups">
                            <input type="hidden" name="_token" value={(document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''} />
                            <Button type="submit" disabled={!can.create}>
                                Créer une sauvegarde
                            </Button>
                        </form>
                    </div>
                    {!can.create && <p className="mt-3 text-sm text-ink-muted">Vous n’avez pas la permission de créer une sauvegarde.</p>}
                </section>

                <section className="rounded-card border border-line bg-surface p-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-semibold text-ink">Sauvegarde automatique</h2>
                            <p className="mt-1 max-w-3xl text-sm text-ink-muted">
                                Les sauvegardes planifiées sont créées en arrière-plan puis stockées dans un stockage externe privé.
                            </p>
                        </div>
                        <span className={settings.enabled ? 'rounded-full bg-success-soft px-3 py-1 text-xs font-semibold text-success' : 'rounded-full bg-muted px-3 py-1 text-xs font-semibold text-ink-muted'}>
                            {settings.enabled ? 'Activée' : 'Désactivée'}
                        </span>
                    </div>

                    <div className="mt-5 grid gap-3 text-sm md:grid-cols-3">
                        <Info label="Prochaine sauvegarde" value={settings.next_backup_at ? formatDateTime(settings.next_backup_at) : '—'} />
                        <Info label="Dernière réussite" value={settings.last_success_at ? formatDateTime(settings.last_success_at) : '—'} />
                        <Info label="Dernier échec" value={settings.last_failure_at ? formatDateTime(settings.last_failure_at) : '—'} />
                    </div>
                    {settings.last_failure_message && <p className="mt-3 text-sm text-danger">Dernière erreur : {settings.last_failure_message}</p>}

                    <form onSubmit={saveSchedule} className="mt-6 grid gap-4 md:grid-cols-2">
                        <label className="flex items-center gap-2 text-sm font-medium text-ink md:col-span-2">
                            <input
                                type="checkbox"
                                checked={scheduleForm.data.enabled}
                                onChange={(event) => scheduleForm.setData('enabled', event.currentTarget.checked)}
                                className="rounded border-line-strong"
                                disabled={!can.create}
                            />
                            Activer la sauvegarde automatique
                        </label>

                        <label className="block text-sm font-medium text-ink">
                            Fréquence
                            <select
                                value={scheduleForm.data.frequency}
                                onChange={(event) => scheduleForm.setData('frequency', event.currentTarget.value as 'daily' | 'weekly')}
                                className="mt-1 block w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                                disabled={!can.create}
                            >
                                <option value="daily">Quotidienne</option>
                                <option value="weekly">Hebdomadaire</option>
                            </select>
                        </label>

                        <label className="block text-sm font-medium text-ink">
                            Heure
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
                                Jour de la semaine
                                <select
                                    value={scheduleForm.data.day_of_week}
                                    onChange={(event) => scheduleForm.setData('day_of_week', Number(event.currentTarget.value))}
                                    className="mt-1 block w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                                    disabled={!can.create}
                                >
                                    <option value={1}>Lundi</option>
                                    <option value={2}>Mardi</option>
                                    <option value={3}>Mercredi</option>
                                    <option value={4}>Jeudi</option>
                                    <option value={5}>Vendredi</option>
                                    <option value={6}>Samedi</option>
                                    <option value={0}>Dimanche</option>
                                </select>
                            </label>
                        )}

                        <label className="block text-sm font-medium text-ink">
                            Fuseau horaire
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
                            Conservation
                            <input
                                type="number"
                                min={7}
                                max={90}
                                value={scheduleForm.data.retention_count}
                                onChange={(event) => scheduleForm.setData('retention_count', Number(event.currentTarget.value))}
                                className="mt-1 block w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                                disabled={!can.create}
                            />
                            <span className="mt-1 block text-xs text-ink-muted">Nombre de sauvegardes automatiques réussies à conserver.</span>
                        </label>

                        <label className="flex items-center gap-2 text-sm font-medium text-ink md:col-span-2">
                            <input
                                type="checkbox"
                                checked={scheduleForm.data.notify_on_failure}
                                onChange={(event) => scheduleForm.setData('notify_on_failure', event.currentTarget.checked)}
                                className="rounded border-line-strong"
                                disabled={!can.create}
                            />
                            M’avertir par e-mail si une sauvegarde automatique échoue
                        </label>

                        <div className="md:col-span-2">
                            <Button type="submit" disabled={!can.create} loading={scheduleForm.processing} loadingText="Enregistrement…">
                                Enregistrer la planification
                            </Button>
                        </div>
                    </form>
                </section>

                <section className="rounded-card border border-line bg-surface p-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Stockage personnel</p>
                            <h2 className="mt-1 text-lg font-semibold text-ink">Google Drive</h2>
                            <p className="mt-1 max-w-3xl text-sm text-ink-muted">
                                Conservez également une copie de vos sauvegardes dans votre propre espace Google Drive. Cette copie est
                                optionnelle et ne remplace pas la sauvegarde SaaS principale.
                            </p>
                        </div>
                        <span className={googleDrive.connected ? 'rounded-full bg-success-soft px-3 py-1 text-xs font-semibold text-success' : 'rounded-full bg-muted px-3 py-1 text-xs font-semibold text-ink-muted'}>
                            {googleDrive.connected ? '✓ Connecté' : 'Non connecté'}
                        </span>
                    </div>

                    {googleDrive.connected ? (
                        <div className="mt-5 space-y-4">
                            <dl className="grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-4">
                                <Info label="Compte" value={googleDrive.account ?? 'Compte Google connecté'} />
                                <Info label="Dossier" value={googleDrive.folder ?? '10xScale ERP / Sauvegardes'} />
                                <Info label="Synchronisation automatique" value={googleDrive.enabled ? 'Activée' : 'Désactivée'} />
                                <Info label="Dernière synchronisation" value={googleDrive.last_sync_at ? formatDateTime(googleDrive.last_sync_at) : '—'} />
                            </dl>
                            {googleDrive.last_sync_error && <p className="text-sm text-danger">{googleDrive.last_sync_error}</p>}
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" variant="secondary" disabled={!can.create || actionProcessing === 'drive-test'} onClick={() => postAction('drive-test', '/organization-backups/google-drive/test')}>
                                    Test de connexion
                                </Button>
                                <Button type="button" variant="danger" disabled={!can.create || actionProcessing === 'drive-disconnect'} onClick={() => deleteAction('drive-disconnect', '/organization-backups/google-drive')}>
                                    Déconnecter
                                </Button>
                            </div>
                            <p className="text-xs text-ink-muted">
                                La déconnexion arrête les futures synchronisations et efface les jetons locaux. Les fichiers déjà présents dans
                                votre Google Drive ne sont pas supprimés.
                            </p>
                        </div>
                    ) : (
                        <div className="mt-5">
                            <Button type="button" disabled={!can.create} onClick={() => { window.location.href = '/organization-backups/google-drive/connect'; }}>
                                Connecter Google Drive
                            </Button>
                        </div>
                    )}
                </section>

                <section className="rounded-card border border-line bg-surface p-6">
                    <h2 className="text-lg font-semibold text-ink">Restaurer une sauvegarde</h2>
                    <p className="mt-1 max-w-3xl text-sm text-ink-muted">
                        Importez d’abord une archive .erpbackup pour la valider. La restauration reste bloquée tant que la sauvegarde n’a pas
                        été reconnue comme appartenant à cette même organisation.
                    </p>

                    <form onSubmit={validateBackup} className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label className="block flex-1 text-sm font-medium text-ink">
                            Fichier .erpbackup
                            <input
                                type="file"
                                accept=".erpbackup"
                                disabled={!can.restore || upload.processing}
                                onChange={(event) => upload.setData('backup', event.currentTarget.files?.[0] ?? null)}
                                className="mt-1 block w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                            />
                        </label>
                        <Button type="submit" disabled={!can.restore || !upload.data.backup} loading={upload.processing} loadingText="Validation…">
                            Vérifier la sauvegarde
                        </Button>
                    </form>
                    {!can.restore && <p className="mt-3 text-sm text-ink-muted">Vous n’avez pas la permission de restaurer une sauvegarde.</p>}

                    {validatedBackup && (
                        <div className="mt-6 rounded-card border border-line bg-raised p-5">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h3 className="font-semibold text-ink">Sauvegarde validée</h3>
                                <span className="rounded-full bg-muted px-3 py-1 text-xs font-semibold text-ink-muted">
                                    Source : {validatedBackup.source_label}
                                </span>
                            </div>
                            <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                <Info label="Source" value={validatedBackup.source_label} />
                                <Info label="Organisation" value={validatedBackup.summary.organization} />
                                <Info label="Date" value={validatedBackup.summary.created_at ? formatDateTime(validatedBackup.summary.created_at) : '—'} />
                                <Info label="Version format" value={`v${validatedBackup.summary.version}`} />
                                <Info label="Produits" value={validatedBackup.summary.products.toString()} />
                                <Info label="Clients" value={validatedBackup.summary.customers.toString()} />
                                <Info label="Commandes" value={validatedBackup.summary.orders.toString()} />
                                <Info label="Factures" value={validatedBackup.summary.invoices.toString()} />
                                <Info label="Paiements" value={validatedBackup.summary.payments.toString()} />
                                <Info label="Mouvements stock" value={validatedBackup.summary.inventory_movements.toString()} />
                            </dl>

                            {Object.keys(validatedBackup.summary.sensitive_columns ?? {}).length > 0 && (
                                <p className="mt-4 text-sm text-warning">
                                    Certains secrets tenant sont présents uniquement sous leur forme chiffrée existante ; ils ne sont jamais affichés.
                                </p>
                            )}

                            <div className="mt-5 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm text-danger">
                                La restauration remplace les données actuelles de cette organisation par l’état de la sauvegarde validée. Une
                                pré-sauvegarde locale sera créée automatiquement avant l’opération.
                            </div>
                            <div className="mt-4 flex flex-wrap items-center gap-3">
                                <Button type="button" variant="danger" disabled={!can.restore || restoring} onClick={() => setConfirmRestore(true)}>
                                    Restaurer cette sauvegarde
                                </Button>
                                {confirmRestore && (
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="text-danger">Confirmer le remplacement des données actuelles ?</span>
                                        <Button type="button" variant="danger" loading={restoring} loadingText="Restauration…" onClick={submitRestore}>
                                            Oui, restaurer
                                        </Button>
                                        <Button type="button" variant="secondary" onClick={() => setConfirmRestore(false)}>
                                            Annuler
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                </section>

                <section className="rounded-card border border-line bg-surface p-6">
                    <h2 className="text-lg font-semibold text-ink">Historique des sauvegardes</h2>
                    <p className="mt-1 text-sm text-ink-muted">Les chemins de stockage et les identifiants externes ne sont jamais affichés.</p>

                    <div className="mt-5 overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th className="py-3 pr-4">Date</th>
                                    <th className="py-3 pr-4">Type</th>
                                    <th className="py-3 pr-4">Statut</th>
                                    <th className="py-3 pr-4">Taille</th>
                                    <th className="py-3 pr-4">Stockage</th>
                                    <th className="py-3 pr-4">Google Drive</th>
                                    <th className="py-3 pr-4 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {history.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="py-6 text-center text-ink-muted">
                                            Aucune sauvegarde enregistrée.
                                        </td>
                                    </tr>
                                )}
                                {history.map((backup) => (
                                    <tr key={backup.id}>
                                        <td className="py-3 pr-4">{backup.completed_at || backup.scheduled_for || backup.created_at ? formatDateTime(backup.completed_at ?? backup.scheduled_for ?? backup.created_at ?? '') : '—'}</td>
                                        <td className="py-3 pr-4">{backup.type === 'scheduled' ? 'Automatique' : backup.type === 'pre_restore' ? 'Pré-restauration' : 'Manuelle'}</td>
                                        <td className="py-3 pr-4">
                                            <Status status={backup.status} />
                                            {backup.failure_message && <div className="mt-1 text-xs text-danger">{backup.failure_message}</div>}
                                        </td>
                                        <td className="py-3 pr-4">{backup.size_bytes ? formatBytes(backup.size_bytes) : '—'}</td>
                                        <td className="py-3 pr-4">{backup.storage === 'external' ? 'Stockage privé' : '—'}</td>
                                        <td className="py-3 pr-4">
                                            <CloudStatus copy={backup.google_drive} />
                                        </td>
                                        <td className="py-3 pr-4 text-right">
                                            {backup.downloadable ? (
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    <a href={`/organization-backups/${backup.id}/download`} className="text-sm font-semibold text-primary hover:underline">
                                                        Télécharger
                                                    </a>
                                                    {can.restore && (
                                                        <button
                                                            type="button"
                                                            disabled={actionProcessing === `restore-${backup.id}`}
                                                            onClick={() => postAction(`restore-${backup.id}`, `/organization-backups/${backup.id}/validate`)}
                                                            className="text-sm font-semibold text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            Restaurer
                                                        </button>
                                                    )}
                                                    {googleDrive.connected && can.create && !backup.google_drive && (
                                                        <button
                                                            type="button"
                                                            disabled={actionProcessing === `copy-drive-${backup.id}`}
                                                            onClick={() => postAction(`copy-drive-${backup.id}`, `/organization-backups/${backup.id}/copy/google-drive`)}
                                                            className="text-sm font-semibold text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            Copier vers Drive
                                                        </button>
                                                    )}
                                                    {backup.google_drive?.status === 'failed' && can.create && (
                                                        <button
                                                            type="button"
                                                            disabled={actionProcessing === `retry-drive-${backup.google_drive.id}`}
                                                            onClick={() => postAction(`retry-drive-${backup.google_drive?.id}`, `/organization-backups/cloud-copies/${backup.google_drive?.id}/retry`)}
                                                            className="text-sm font-semibold text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            Réessayer Drive
                                                        </button>
                                                    )}
                                                    {backup.google_drive?.status === 'completed' && can.restore && (
                                                        <button
                                                            type="button"
                                                            disabled={actionProcessing === `restore-drive-${backup.google_drive.id}`}
                                                            onClick={() => postAction(`restore-drive-${backup.google_drive?.id}`, `/organization-backups/cloud-copies/${backup.google_drive?.id}/validate`)}
                                                            className="text-sm font-semibold text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            Restaurer depuis Drive
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
    if (!copy) {
        return <span className="text-ink-muted">—</span>;
    }

    if (copy.status === 'completed') {
        return <span className="rounded-full bg-success-soft px-2 py-1 text-xs font-semibold text-success">✓ Synchronisée</span>;
    }
    if (copy.status === 'failed') {
        return (
            <span>
                <span className="rounded-full bg-danger-soft px-2 py-1 text-xs font-semibold text-danger">⚠ Échec</span>
                {copy.failure_message && <span className="mt-1 block text-xs text-danger">{copy.failure_message}</span>}
            </span>
        );
    }
    if (copy.status === 'uploading') {
        return <span className="rounded-full bg-muted px-2 py-1 text-xs font-semibold text-ink-muted">Synchronisation…</span>;
    }

    return <span className="rounded-full bg-muted px-2 py-1 text-xs font-semibold text-ink-muted">En attente</span>;
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
    const labels: Record<Props['history'][number]['status'], string> = {
        queued: 'En attente',
        running: 'En cours',
        completed: 'Réussie',
        failed: 'Échec',
        deleted_by_retention: 'Supprimée',
    };
    const className =
        status === 'completed'
            ? 'bg-success-soft text-success'
            : status === 'failed'
              ? 'bg-danger-soft text-danger'
              : 'bg-muted text-ink-muted';

    return <span className={`rounded-full px-2 py-1 text-xs font-semibold ${className}`}>{labels[status]}</span>;
}

function formatBytes(value: number) {
    if (value < 1024) return `${value} o`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} Ko`;

    return `${(value / 1024 / 1024).toFixed(1)} Mo`;
}
