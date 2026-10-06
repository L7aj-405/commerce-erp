import PageHeader from '@/components/ui/PageHeader';
import { Spinner } from '@/components/ui/Spinner';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDateTime } from '@/utils/format';
import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';

type Status = 'operational' | 'degraded' | 'critical' | 'unknown';
type Check = { status: Status; message: string; data?: Record<string, unknown> };
type Snapshot = {
    status: Status; checked_at: string; checks: Record<string, Check>;
    failed_jobs: Array<{ uuid: string; connection: string; queue: string; failed_at: string }>;
    security_events: Array<{ id: number; event: string; actor: { name: string; email: string } | null; created_at: string }>;
    errors: { available: boolean; reason: string };
};
type Props = { snapshot: Snapshot; pollSeconds: number; canManage: boolean };

const labels: Record<string, string> = { application: 'Application', database: 'Base de données', queue: 'File d’attente', scheduler: 'Scheduler', backups: 'Sauvegardes', woocommerce: 'WooCommerce', storage: 'Stockage', smtp: 'Email / SMTP' };
const statusLabels: Record<Status, string> = { operational: 'Opérationnel', degraded: 'Dégradé', critical: 'Critique', unknown: 'Inconnu' };
const statusStyles: Record<Status, string> = { operational: 'bg-success-soft text-success', degraded: 'bg-warning-soft text-warning', critical: 'bg-danger-soft text-danger', unknown: 'bg-raised text-ink-muted' };

export default function SystemHealthIndex({ snapshot: initial, pollSeconds }: Props) {
    const [snapshot, setSnapshot] = useState(initial);
    const [refreshing, setRefreshing] = useState(false);
    const [refreshError, setRefreshError] = useState('');
    const refresh = useCallback(async () => {
        if (refreshing) return;
        setRefreshing(true);
        try {
            const response = await fetch('/system-health', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('health');
            setSnapshot(await response.json() as Snapshot);
            setRefreshError('');
        } catch { setRefreshError('Impossible d’actualiser l’état du système.'); }
        finally { setRefreshing(false); }
    }, [refreshing]);

    useEffect(() => {
        const timer = window.setInterval(() => void refresh(), pollSeconds * 1000);
        return () => window.clearInterval(timer);
    }, [pollSeconds, refresh]);

    return <ApplicationShell wide>
        <Head title="Opérations système" />
        <PageHeader title="Opérations système" description="Santé de l’application et services opérationnels de votre organisation." />
        <section className="mb-6 flex flex-col gap-4 rounded-card border border-line bg-surface p-5 shadow-soft sm:flex-row sm:items-center sm:justify-between">
            <div><p className="text-xs font-semibold uppercase tracking-wide text-ink-faint">État général</p><div className="mt-2 flex items-center gap-3"><StatusBadge status={snapshot.status} large /><span className="text-sm text-ink-muted">Actualisé {formatDateTime(snapshot.checked_at)}</span></div></div>
            <button onClick={() => void refresh()} disabled={refreshing} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-field border border-line-strong px-4 text-sm font-semibold text-ink disabled:opacity-50">{refreshing && <Spinner size="sm" />}Actualiser</button>
        </section>
        {refreshError && <p className="mb-4 rounded-field bg-danger-soft px-4 py-3 text-sm text-danger">{refreshError}</p>}

        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            {Object.entries(snapshot.checks).map(([key, check]) => <HealthCard key={key} title={labels[key] ?? key} check={check} />)}
        </div>

        <div className="mt-6 grid gap-6 xl:grid-cols-2">
            <Section title="Jobs en échec" subtitle="Métadonnées globales uniquement — aucun payload sérialisé n’est exposé.">
                {snapshot.failed_jobs.length === 0 ? <Empty text="Aucun job en échec." /> : <ul className="divide-y divide-line">{snapshot.failed_jobs.map(job => <li key={job.uuid} className="grid grid-cols-[1fr_auto] gap-3 py-3 text-sm"><div><p className="font-medium text-ink">{job.queue}</p><p className="text-xs text-ink-faint">{job.connection}</p></div><time className="text-ink-muted">{formatDateTime(job.failed_at)}</time></li>)}</ul>}
            </Section>
            <Section title="Événements de sécurité" subtitle="Événements d’authentification de l’organisation active.">
                {snapshot.security_events.length === 0 ? <Empty text="Aucun événement récent." /> : <ul className="divide-y divide-line">{snapshot.security_events.map(event => <li key={event.id} className="grid grid-cols-[1fr_auto] gap-3 py-3 text-sm"><div><p className="font-medium text-ink">{event.event.replaceAll('_', ' ')}</p><p className="text-xs text-ink-faint">{event.actor?.name ?? 'Système'}</p></div><time className="text-ink-muted">{formatDateTime(event.created_at)}</time></li>)}</ul>}
            </Section>
            <Section title="Erreurs applicatives" subtitle="Disponibilité des métriques HTTP et journaux structurés."><Empty text={snapshot.errors.reason} /></Section>
            <Section title="Limites de sécurité" subtitle="Actions volontairement indisponibles dans cette version."><Empty text="Les payloads des jobs, traces complètes, secrets de configuration et opérations retry/suppression ne sont pas exposés faute d’attribution tenant fiable." /></Section>
        </div>
    </ApplicationShell>;
}

function HealthCard({ title, check }: { title: string; check: Check }) {
    const entries = healthEntries(check.data ?? {}).slice(0, 8);
    return <article className="rounded-card border border-line bg-surface p-4 shadow-soft"><div className="flex items-start justify-between gap-3"><h2 className="font-semibold text-ink">{title}</h2><StatusBadge status={check.status} /></div><p className="mt-2 min-h-10 text-sm text-ink-muted">{check.message}</p>{entries.length > 0 && <dl className="mt-3 space-y-1 border-t border-line pt-3">{entries.map(([key, value]) => <div key={key} className="flex justify-between gap-3 text-xs"><dt className="text-ink-faint">{key.replaceAll('_', ' ')}</dt><dd className="max-w-[55%] truncate text-right font-medium text-ink">{formatValue(key, value)}</dd></div>)}</dl>}</article>;
}
function StatusBadge({ status, large = false }: { status: Status; large?: boolean }) { return <span className={`inline-flex rounded-full font-semibold ${large ? 'px-3 py-1.5 text-sm' : 'px-2.5 py-1 text-xs'} ${statusStyles[status]}`}>{statusLabels[status]}</span>; }
function Section({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) { return <section className="rounded-card border border-line bg-surface p-5 shadow-soft"><h2 className="font-semibold text-ink">{title}</h2><p className="mt-1 text-sm text-ink-muted">{subtitle}</p><div className="mt-3">{children}</div></section>; }
function Empty({ text }: { text: string }) { return <p className="rounded-field bg-raised px-4 py-3 text-sm text-ink-muted">{text}</p>; }
function formatValue(key: string, value: unknown) { if (typeof value === 'boolean') return value ? 'Oui' : 'Non'; if (key.endsWith('_at') && typeof value === 'string') return formatDateTime(value); if (key.endsWith('_bytes') && typeof value === 'number') return `${(value / 1024 / 1024 / 1024).toFixed(1)} Go`; if (key.endsWith('_percent')) return `${value} %`; return String(value); }
function healthEntries(data: Record<string, unknown>): Array<[string, unknown]> {
    return Object.entries(data).flatMap(([key, value]) => {
        if (value === null) return [];
        if (typeof value !== 'object' || Array.isArray(value)) return [[key, value] as [string, unknown]];
        return Object.entries(value as Record<string, unknown>)
            .filter(([, nested]) => nested !== null && typeof nested !== 'object')
            .map(([nestedKey, nested]) => [`${key}_${nestedKey}`, nested] as [string, unknown]);
    });
}
