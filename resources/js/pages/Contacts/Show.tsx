import { Button, ButtonLink } from '@/components/ui/Button';
import { useConfirm } from '@/components/ui/ConfirmDialog';
import PageHeader from '@/components/ui/PageHeader';
import { Badge } from '@/components/ui/StatusBadge';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import { Head, Link, router } from '@inertiajs/react';
import type { WhatsAppDestination } from '@/utils/whatsapp';

type Contact = {
    id: number;
    full_name: string;
    company_name: string | null;
    job_title: string | null;
    email: string | null;
    phone: string | null;
    whatsapp: string | null;
    whatsapp_username: string | null;
    contact_type: string;
    notes: string | null;
    active: boolean;
    customer: { id: number; name: string } | null;
    supplier: { id: number; name: string } | null;
};
type Props = { contact: Contact; whatsappDestination: WhatsAppDestination; duplicates: Contact[]; can: { update: boolean; archive: boolean } };
export default function ContactShow({ contact, whatsappDestination, duplicates, can }: Props) {
    const { t } = useI18n();
    const confirm = useConfirm();
    const typeLabels: Record<string, string> = { client: t('contacts.client'), supplier: t('contacts.supplier'), internal: t('contacts.internal'), transport: t('contacts.transport'), other: t('contacts.other') };
    const archive = async () => {
        if (await confirm({ title: t('contacts.archiveQuestion'), danger: true })) router.delete(`/contacts/${contact.id}`);
    };

    return (
        <ApplicationShell>
            <Head title={contact.full_name} />
            <PageHeader
                title={contact.full_name}
                description={[contact.company_name, contact.job_title].filter(Boolean).join(' · ') || t('contacts.organizationContact')}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <ButtonLink href="/contacts" variant="secondary">
                            {t('contacts.title')}
                        </ButtonLink>
                        {can.update && <ButtonLink href={`/contacts/${contact.id}/edit`}>{t('contacts.editAction')}</ButtonLink>}
                    </div>
                }
            />
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                <section className="surface-card p-5">
                    <div className="mb-4 flex flex-wrap items-center gap-2">
                        <Badge variant={contact.active ? 'success' : 'neutral'}>{contact.active ? t('contacts.active') : t('contacts.archived')}</Badge>
                        <Badge variant="neutral">{typeLabels[contact.contact_type] ?? contact.contact_type}</Badge>
                    </div>
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <Info label={t('contacts.email')} value={contact.email} href={contact.email ? `mailto:${contact.email}` : undefined} ltr />
                        <Info label={t('contacts.phone')} value={contact.phone} href={contact.phone ? `tel:${contact.phone}` : undefined} ltr />
                        <Info label="WhatsApp" value={contact.whatsapp} ltr />
                        <Info label={t('contacts.whatsappUser')} value={contact.whatsapp_username ? `@${contact.whatsapp_username}` : null} ltr />
                        <Info label={t('contacts.company')} value={contact.company_name} />
                        <Info label={t('contacts.linkedCustomer')} value={contact.customer?.name} />
                        <Info label={t('contacts.linkedSupplier')} value={contact.supplier?.name} />
                    </dl>
                    {contact.notes && <div className="mt-5 whitespace-pre-wrap rounded-field bg-raised p-4 text-sm text-ink-muted">{contact.notes}</div>}
                    {whatsappDestination.available && whatsappDestination.link && (
                        <a
                            href={whatsappDestination.link}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="mt-5 inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised"
                        >
                            {t('contacts.openWhatsapp')}
                        </a>
                    )}
                    {!whatsappDestination.available && <p className="mt-5 text-sm text-ink-muted">{t('contacts.noWhatsapp')}</p>}
                </section>
                <aside className="space-y-4">
                    {duplicates.length > 0 && (
                        <div className="rounded-card border border-warning/30 bg-warning-soft p-4 text-sm text-warning">
                            <p className="font-medium">{t('contacts.duplicateEmail')}</p>
                            <ul className="mt-2 space-y-1">
                                {duplicates.map((item) => (
                                    <li key={item.id}>
                                        <Link href={`/contacts/${item.id}`} className="underline">
                                            {item.full_name}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {can.archive && contact.active && (
                        <Button variant="danger" className="w-full" onClick={archive}>
                            {t('contacts.archive')}
                        </Button>
                    )}
                </aside>
            </div>
        </ApplicationShell>
    );
}

function Info({ label, value, href, ltr = false }: { label: string; value?: string | null; href?: string; ltr?: boolean }) {
    const text = value ? <bdi dir={ltr ? 'ltr' : undefined}>{value}</bdi> : '—';
    return (
        <div className="min-w-0">
            <dt className="text-[13px] text-ink-muted">{label}</dt>
            <dd className="mt-1 break-words text-sm font-medium text-ink">
                {href && value ? (
                    <a href={href} className="underline-offset-4 hover:underline">
                        {text}
                    </a>
                ) : (
                    text
                )}
            </dd>
        </div>
    );
}
