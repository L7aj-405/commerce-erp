import DocBadge from '@/components/ui/DocBadge';
import PageHeader from '@/components/ui/PageHeader';
import SalesLayout from '@/layouts/SalesLayout';
import { Head, Link, router } from '@inertiajs/react';

type Contact = {
    id: number;
    full_name: string;
    company_name: string | null;
    job_title: string | null;
    email: string | null;
    phone: string | null;
    whatsapp: string | null;
    contact_type: string;
    notes: string | null;
    active: boolean;
    customer: { id: number; name: string } | null;
    supplier: { id: number; name: string } | null;
};
type Props = { contact: Contact; duplicates: Contact[]; can: { update: boolean; archive: boolean } };
const typeLabels: Record<string, string> = { client: 'Client', supplier: 'Fournisseur', internal: 'Interne', other: 'Autre' };

export default function ContactShow({ contact, duplicates, can }: Props) {
    const archive = () => {
        if (window.confirm(`Archiver ${contact.full_name} ?`)) router.delete(`/contacts/${contact.id}`);
    };

    return (
        <SalesLayout>
            <Head title={contact.full_name} />
            <PageHeader
                title={contact.full_name}
                description={[contact.company_name, contact.job_title].filter(Boolean).join(' · ') || 'Contact de l’organisation'}
                actions={<div className="flex gap-2"><Link href="/contacts" className="inline-flex min-h-10 items-center rounded-field border border-line-strong px-4 text-sm text-ink hover:bg-sage">Contacts</Link>{can.update && <Link href={`/contacts/${contact.id}/edit`} className="inline-flex min-h-10 items-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg">Modifier</Link>}</div>}
            />
            <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
                <section className="rounded-card border border-line bg-surface p-5">
                    <div className="mb-4 flex items-center gap-2"><DocBadge tone={contact.active ? 'positive' : 'neutral'}>{contact.active ? 'Actif' : 'Archivé'}</DocBadge><DocBadge>{typeLabels[contact.contact_type] ?? contact.contact_type}</DocBadge></div>
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <Info label="Email" value={contact.email} href={contact.email ? `mailto:${contact.email}` : undefined} />
                        <Info label="Téléphone" value={contact.phone} href={contact.phone ? `tel:${contact.phone}` : undefined} />
                        <Info label="WhatsApp" value={contact.whatsapp} />
                        <Info label="Société" value={contact.company_name} />
                        <Info label="Client lié" value={contact.customer?.name} />
                        <Info label="Fournisseur lié" value={contact.supplier?.name} />
                    </dl>
                    {contact.notes && <div className="mt-5 rounded-field bg-raised p-4 text-sm text-ink-muted whitespace-pre-wrap">{contact.notes}</div>}
                </section>
                <aside className="space-y-4">
                    {duplicates.length > 0 && (
                        <div className="rounded-card border border-warning/30 bg-warning-soft p-4 text-sm text-warning">
                            <p className="font-medium">Email déjà présent</p>
                            <ul className="mt-2 space-y-1">
                                {duplicates.map((item) => <li key={item.id}><Link href={`/contacts/${item.id}`} className="underline">{item.full_name}</Link></li>)}
                            </ul>
                        </div>
                    )}
                    {can.archive && contact.active && <button type="button" onClick={archive} className="w-full rounded-field border border-danger/30 px-4 py-2 text-sm font-medium text-danger hover:bg-danger-soft">Archiver ce contact</button>}
                </aside>
            </div>
        </SalesLayout>
    );
}

function Info({ label, value, href }: { label: string; value?: string | null; href?: string }) {
    return <div><dt className="text-xs font-medium uppercase tracking-wide text-ink-muted">{label}</dt><dd className="mt-1 text-sm font-medium text-ink">{href && value ? <a href={href} className="underline-offset-4 hover:underline">{value}</a> : value || '—'}</dd></div>;
}
