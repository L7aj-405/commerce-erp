import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDateTime } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

type Option = { id: number; name: string; email?: string };
type NamedOption = { key: string; label: string };
type PageLink = { url: string | null; label: string; active: boolean };
type AuditValue = null | boolean | number | string | AuditValue[] | { [key: string]: AuditValue };
type AuditEntry = {
    id: number;
    created_at: string;
    actor: Option | null;
    actor_label: string;
    organization: Option | null;
    store: { id: number; name: string; code: string } | null;
    module: NamedOption;
    event: string;
    action_label: string;
    target: { type: string | null; id: number | null; reference: string | null };
    description: string;
    ip_address: string | null;
    old_values: Record<string, AuditValue>;
    new_values: Record<string, AuditValue>;
    detail_url?: string;
};
type Props = {
    logs: { data: AuditEntry[]; links: PageLink[]; total: number };
    detail: AuditEntry | null;
    filters: Record<string, string | number | undefined>;
    organizations: Option[];
    users: Option[];
    modules: NamedOption[];
    events: NamedOption[];
    closeDetailUrl: string;
};

export default function ActivityIndex({ logs, detail, filters, organizations, users, modules, events, closeDetailUrl }: Props) {
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get('/activity', Object.fromEntries(new FormData(event.currentTarget).entries()), {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <ApplicationShell wide>
            <Head title="Activité" />
            <PageHeader title="Centre d’activité" description="Historique sécurisé des actions réalisées dans vos organisations." />

            <form onSubmit={submit} className="mb-5 rounded-card border border-line bg-surface p-4 shadow-soft">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                    <Field label="Recherche" className="sm:col-span-2">
                        <input name="search" defaultValue={String(filters.search ?? '')} placeholder="Utilisateur, référence, action…" className={inputClass} />
                    </Field>
                    {organizations.length > 1 && (
                        <Field label="Organisation">
                            <select name="organization_id" defaultValue={String(filters.organization_id ?? '')} className={inputClass}>
                                {organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}
                            </select>
                        </Field>
                    )}
                    <Field label="Utilisateur">
                        <select name="user_id" defaultValue={String(filters.user_id ?? '')} className={inputClass}>
                            <option value="">Tous</option>
                            {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                        </select>
                    </Field>
                    <Field label="Module">
                        <select name="module" defaultValue={String(filters.module ?? '')} className={inputClass}>
                            <option value="">Tous</option>
                            {modules.map((module) => <option key={module.key} value={module.key}>{module.label}</option>)}
                        </select>
                    </Field>
                    <Field label="Action">
                        <select name="event" defaultValue={String(filters.event ?? '')} className={inputClass}>
                            <option value="">Toutes</option>
                            {events.map((event) => <option key={event.key} value={event.key}>{event.label}</option>)}
                        </select>
                    </Field>
                    <Field label="Du"><input name="date_from" type="date" defaultValue={String(filters.date_from ?? '')} className={inputClass} /></Field>
                    <Field label="Au"><input name="date_to" type="date" defaultValue={String(filters.date_to ?? '')} className={inputClass} /></Field>
                </div>
                <div className="mt-4 flex flex-wrap justify-end gap-2">
                    <Link href="/activity" className="rounded-field border border-line-strong px-4 py-2 text-sm font-medium text-ink-muted hover:bg-raised">Réinitialiser</Link>
                    <button className="rounded-field bg-ink px-4 py-2 text-sm font-semibold text-white hover:bg-ink-muted">Filtrer</button>
                </div>
            </form>

            <div className="mb-3 flex items-center justify-between text-sm text-ink-muted">
                <span>{logs.total} événement{logs.total > 1 ? 's' : ''}</span>
                <span>25 par page</span>
            </div>

            {logs.data.length === 0 ? (
                <EmptyState title="Aucune activité" description="Aucun événement ne correspond aux filtres sélectionnés." />
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface shadow-soft lg:block">
                        <table className="w-full min-w-[1050px] text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-3">Date</th><th className="px-4 py-3">Utilisateur</th>
                                    <th className="px-4 py-3">Module</th><th className="px-4 py-3">Action</th>
                                    <th className="px-4 py-3">Cible</th><th className="px-4 py-3">Organisation / magasin</th>
                                    <th className="px-4 py-3">IP</th><th className="px-4 py-3 text-right">Détails</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {logs.data.map((entry) => <AuditRow key={entry.id} entry={entry} />)}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 lg:hidden">
                        {logs.data.map((entry) => (
                            <li key={entry.id} className="rounded-card border border-line bg-surface p-4 shadow-soft">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <ModuleBadge module={entry.module} />
                                        <p className="mt-2 font-semibold text-ink">{entry.action_label}</p>
                                        <p className="truncate text-sm text-ink-muted">{entry.actor_label}</p>
                                    </div>
                                    <time className="shrink-0 text-xs text-ink-faint">{formatDateTime(entry.created_at)}</time>
                                </div>
                                <p className="mt-3 text-sm text-ink-muted">{targetText(entry)}</p>
                                <Link href={entry.detail_url ?? `/activity?detail=${entry.id}`} preserveScroll className="mt-3 inline-flex text-sm font-semibold text-ink underline-offset-2 hover:underline">Voir les détails</Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={logs.links} />
            {detail && <DetailDrawer entry={detail} closeUrl={closeDetailUrl} />}
        </ApplicationShell>
    );
}

function AuditRow({ entry }: { entry: AuditEntry }) {
    return (
        <tr className="transition-soft hover:bg-raised/70">
            <td className="whitespace-nowrap px-4 py-3 text-ink-muted">{formatDateTime(entry.created_at)}</td>
            <td className="px-4 py-3"><p className="font-medium text-ink">{entry.actor_label}</p><p className="text-xs text-ink-faint">{entry.actor?.email ?? 'Action système'}</p></td>
            <td className="px-4 py-3"><ModuleBadge module={entry.module} /></td>
            <td className="px-4 py-3"><p className="font-medium text-ink">{entry.action_label}</p><p className="mt-0.5 text-xs text-ink-faint">{entry.event}</p></td>
            <td className="px-4 py-3 text-ink-muted">{targetText(entry)}</td>
            <td className="px-4 py-3"><p className="text-ink">{entry.organization?.name ?? '—'}</p><p className="text-xs text-ink-faint">{entry.store ? `${entry.store.name} · ${entry.store.code}` : 'Toute l’organisation'}</p></td>
            <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-ink-muted">{entry.ip_address ?? '—'}</td>
            <td className="px-4 py-3 text-right"><Link href={entry.detail_url ?? `/activity?detail=${entry.id}`} preserveScroll className="rounded-field px-3 py-2 text-sm font-medium text-ink hover:bg-sage">Ouvrir</Link></td>
        </tr>
    );
}

function DetailDrawer({ entry, closeUrl }: { entry: AuditEntry; closeUrl: string }) {
    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-ink/25 backdrop-blur-[1px]">
            <Link href={closeUrl} preserveScroll aria-label="Fermer les détails" className="absolute inset-0" />
            <aside role="dialog" aria-modal="true" aria-labelledby="audit-detail-title" className="relative h-full w-full max-w-2xl overflow-y-auto border-l border-line bg-surface p-5 shadow-pop sm:p-7">
                <div className="flex items-start justify-between gap-4">
                    <div><p className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Événement #{entry.id}</p><h2 id="audit-detail-title" className="mt-1 text-xl font-semibold text-ink">{entry.action_label}</h2></div>
                    <Link href={closeUrl} preserveScroll className="grid size-9 place-items-center rounded-full border border-line text-xl text-ink-muted hover:bg-raised" aria-label="Fermer">×</Link>
                </div>
                <dl className="mt-6 grid gap-4 rounded-card border border-line bg-raised p-4 sm:grid-cols-2">
                    <Info label="Utilisateur" value={`${entry.actor_label}${entry.actor?.email ? ` · ${entry.actor.email}` : ''}`} />
                    <Info label="Date" value={formatDateTime(entry.created_at)} />
                    <Info label="Organisation" value={entry.organization?.name ?? '—'} />
                    <Info label="Magasin" value={entry.store?.name ?? 'Toute l’organisation'} />
                    <Info label="Module" value={entry.module.label} />
                    <Info label="Type interne" value={entry.event} />
                    <Info label="Cible" value={targetText(entry)} />
                    <Info label="Adresse IP" value={entry.ip_address ?? '—'} />
                </dl>
                <ValueSection title="Valeurs précédentes" values={entry.old_values} />
                <ValueSection title="Nouvelles valeurs" values={entry.new_values} />
            </aside>
        </div>
    );
}

function ValueSection({ title, values }: { title: string; values: Record<string, AuditValue> }) {
    const entries = Object.entries(values);
    return (
        <section className="mt-6">
            <h3 className="text-sm font-semibold text-ink">{title}</h3>
            {entries.length === 0 ? <p className="mt-2 text-sm text-ink-faint">Aucune valeur enregistrée.</p> : (
                <dl className="mt-2 divide-y divide-line rounded-card border border-line">
                    {entries.map(([key, value]) => (
                        <div key={key} className="grid gap-1 px-4 py-3 sm:grid-cols-[11rem_1fr] sm:gap-4">
                            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-faint">{humanKey(key)}</dt>
                            <dd className="break-words text-sm text-ink"><Value value={value} /></dd>
                        </div>
                    ))}
                </dl>
            )}
        </section>
    );
}

function Value({ value }: { value: AuditValue }) {
    if (value === null) return <span className="text-ink-faint">—</span>;
    if (typeof value === 'boolean') return <span>{value ? 'Oui' : 'Non'}</span>;
    if (typeof value !== 'object') return <span>{String(value)}</span>;
    return <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded-field bg-raised p-3 text-xs leading-relaxed">{JSON.stringify(value, null, 2)}</pre>;
}

const inputClass = 'w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm';
function Field({ label, children, className = '' }: { label: string; children: ReactNode; className?: string }) { return <label className={`block ${className}`}><span className="mb-1 block text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</span>{children}</label>; }
function Info({ label, value }: { label: string; value: string }) { return <div><dt className="text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</dt><dd className="mt-1 break-words text-sm text-ink">{value}</dd></div>; }
function ModuleBadge({ module }: { module: NamedOption }) { return <span className="inline-flex rounded-full bg-sage px-2.5 py-1 text-xs font-semibold text-ink">{module.label}</span>; }
function targetText(entry: AuditEntry) { return [entry.target.type, entry.target.reference].filter(Boolean).join(' · ') || '—'; }
function humanKey(key: string) { return key.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase()); }
