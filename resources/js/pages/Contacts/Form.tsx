import ContactForm from '@/components/contacts/ContactForm';
import type { Catalogue, ContactCan, PartyForEdit } from '@/components/contacts/types';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link } from '@inertiajs/react';

type Props = {
    party: PartyForEdit | null;
    presetRoles: string[];
    catalogue: Catalogue;
    can: ContactCan;
};

/** Full-page creation / edition. The entry point (Nouveau client, Nouveau fournisseur, ...) only changes the preset roles. */
export default function ContactFormPage({ party, presetRoles, catalogue, can }: Props) {
    const { t, direction } = useI18n();
    const back = party ? `/contacts/parties/${party.id}` : '/contacts';
    const title = party ? t('cts.edit') : presetRoles.includes('client') ? t('cts.newClient') : presetRoles.includes('supplier') ? t('cts.newSupplier') : presetRoles.includes('internal') ? t('cts.newInternal') : t('cts.new');

    return (
        <ApplicationShell>
            <Head title={title} />
            <div className="mx-auto max-w-3xl">
                <Link href={back} className="text-sm text-ink-muted">
                    {direction === 'rtl' ? '→' : '←'} {party ? party.display_name : t('cts.title')}
                </Link>
                <PageHeader title={title} description={party ? t('cts.editHelp') : t('cts.formHelp')} />
                <div className="surface-card p-5 sm:p-6">
                    <ContactForm mode="complete" layout="page" catalogue={catalogue} can={can} party={party} presetRoles={presetRoles} onCancel={() => window.history.back()} />
                </div>
            </div>
        </ApplicationShell>
    );
}
