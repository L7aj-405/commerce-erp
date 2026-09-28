import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SalesLayout from '@/layouts/SalesLayout';
import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Contact = {
    id: number;
    full_name: string;
    company_name: string | null;
    job_title: string | null;
    email: string | null;
    phone: string | null;
    whatsapp: string | null;
    contact_type: string;
    active: boolean;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    contacts: { data: Contact[]; links: LinkData[] };
    filters: { search?: string; contact_type?: string; active?: string };
    can: { create: boolean; update: boolean; archive: boolean };
};

const fieldClass = 'h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';
const typeLabels: Record<string, string> = { client: 'Client', supplier: 'Fournisseur', internal: 'Interne', other: 'Autre' };

export default function ContactsIndex({ contacts, filters, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/contacts', { ...filters, search: search || undefined, page: undefined }, { preserveState: true, replace: true });
    };

    return (
        <SalesLayout>
            <Head title="Contacts" />
            <PageHeader
                title="Contacts"
                description="Répertoire professionnel de votre organisation pour les clients, fournisseurs, contacts internes et autres destinataires."
                actions={can.create ? <Link href="/contacts/create" className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg transition-soft hover:bg-primary-hover">+ Nouveau contact</Link> : undefined}
            />

            <form onSubmit={submit} className="mb-6 flex flex-wrap gap-3 rounded-card border border-line bg-raised p-4">
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nom, société, email, téléphone…" className={`${fieldClass} w-full min-w-0 flex-1 sm:min-w-72 sm:w-auto`} />
                <select value={filters.contact_type ?? ''} onChange={(e) => router.get('/contacts', { ...filters, contact_type: e.target.value || undefined, page: undefined }, { preserveState: true })} className={`${fieldClass} flex-1 sm:flex-none`}>
                    <option value="">Tous les types</option>
                    <option value="client">Client</option>
                    <option value="supplier">Fournisseur</option>
                    <option value="internal">Interne</option>
                    <option value="other">Autre</option>
                </select>
                <select value={filters.active ?? ''} onChange={(e) => router.get('/contacts', { ...filters, active: e.target.value || undefined, page: undefined }, { preserveState: true })} className={`${fieldClass} flex-1 sm:flex-none`}>
                    <option value="">Tous les statuts</option>
                    <option value="1">Actifs</option>
                    <option value="0">Archivés</option>
                </select>
                <button type="submit" className="inline-flex min-h-10 flex-1 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised sm:flex-none">Rechercher</button>
            </form>

            {contacts.data.length === 0 ? (
                <EmptyState title="Aucun contact trouvé." description="Ajoutez vos destinataires fréquents pour envoyer les documents en quelques clics." actions={can.create ? <Link href="/contacts/create" className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg">+ Nouveau contact</Link> : undefined} />
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="w-full min-w-[850px] text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-2.5">Contact</th>
                                    <th className="px-4 py-2.5">Société</th>
                                    <th className="px-4 py-2.5">Type</th>
                                    <th className="px-4 py-2.5">Email</th>
                                    <th className="px-4 py-2.5">Téléphone</th>
                                    <th className="px-4 py-2.5">Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                {contacts.data.map((contact) => (
                                    <tr key={contact.id} className="border-t border-line">
                                        <td className="px-4 py-2.5">
                                            <Link href={`/contacts/${contact.id}`} className="font-medium text-ink">{contact.full_name}</Link>
                                            {contact.job_title && <p className="text-xs text-ink-muted">{contact.job_title}</p>}
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{contact.company_name ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{typeLabels[contact.contact_type] ?? contact.contact_type}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{contact.email ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{contact.phone ?? contact.whatsapp ?? '—'}</td>
                                        <td className="px-4 py-2.5"><DocBadge tone={contact.active ? 'positive' : 'neutral'}>{contact.active ? 'Actif' : 'Archivé'}</DocBadge></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <ul className="space-y-3 md:hidden">
                        {contacts.data.map((contact) => (
                            <li key={contact.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/contacts/${contact.id}`} className="block truncate text-sm font-semibold text-ink">{contact.full_name}</Link>
                                        <p className="truncate text-[13px] text-ink-muted">{contact.company_name ?? contact.email ?? '—'}</p>
                                    </div>
                                    <DocBadge tone={contact.active ? 'positive' : 'neutral'}>{contact.active ? 'Actif' : 'Archivé'}</DocBadge>
                                </div>
                                <div className="mt-3 text-[13px] text-ink-muted">
                                    <p>{typeLabels[contact.contact_type] ?? contact.contact_type}</p>
                                    <p>{contact.phone ?? contact.whatsapp ?? '—'}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={contacts.links} />
        </SalesLayout>
    );
}
