import { Button, ButtonLink } from '@/components/ui/Button';
import { Checkbox, FormBanner, SelectField, TextAreaField, TextField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type LinkedOption = { id: number; display_name?: string; name?: string };
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
} | null;
type Props = { contact: Contact; linkedOptions: { customers: LinkedOption[]; suppliers: LinkedOption[] } };

const blank = { full_name: '', company_name: '', job_title: '', email: '', phone: '', whatsapp: '', whatsapp_username: '', contact_type: 'client', customer_id: '', supplier_id: '', notes: '', active: true };

export default function ContactForm({ contact, linkedOptions }: Props) {
    const { t } = useI18n();
    const form = useForm({
        full_name: contact?.full_name ?? blank.full_name,
        company_name: contact?.company_name ?? blank.company_name,
        job_title: contact?.job_title ?? blank.job_title,
        email: contact?.email ?? blank.email,
        phone: contact?.phone ?? blank.phone,
        whatsapp: contact?.whatsapp ?? blank.whatsapp,
        whatsapp_username: contact?.whatsapp_username ?? blank.whatsapp_username,
        contact_type: contact?.contact_type ?? blank.contact_type,
        customer_id: contact?.customer?.id?.toString() ?? '',
        supplier_id: contact?.supplier?.id?.toString() ?? '',
        notes: contact?.notes ?? blank.notes,
        active: contact?.active ?? true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (contact) form.patch(`/contacts/${contact.id}`);
        else form.post('/contacts');
    };

    const types = [
        { value: 'client', label: t('contacts.client') },
        { value: 'supplier', label: t('contacts.supplier') },
        { value: 'internal', label: t('contacts.internal') },
        { value: 'transport', label: t('contacts.transport') },
        { value: 'other', label: t('contacts.other') },
    ];
    const backHref = contact ? `/contacts/${contact.id}` : '/contacts';

    return (
        <ApplicationShell width="reading">
            <Head title={contact ? t('contacts.edit') : t('contacts.new')} />
            <PageHeader
                title={contact ? t('contacts.edit') : t('contacts.new')}
                description={t('contacts.formHelp')}
                actions={
                    <ButtonLink href={backHref} variant="secondary">
                        {t('common.back')}
                    </ButtonLink>
                }
            />
            <form onSubmit={submit} className="surface-card p-5">
                <div className="grid gap-4 md:grid-cols-2">
                    <TextField label={t('contacts.fullName')} required value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} error={form.errors.full_name} autoComplete="name" />
                    <SelectField label={t('contacts.type')} value={form.data.contact_type} onChange={(e) => form.setData('contact_type', e.target.value)} error={form.errors.contact_type}>
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </SelectField>
                    <TextField label={t('contacts.organization')} value={form.data.company_name} onChange={(e) => form.setData('company_name', e.target.value)} error={form.errors.company_name} autoComplete="organization" />
                    <TextField label={t('contacts.jobTitle')} value={form.data.job_title} onChange={(e) => form.setData('job_title', e.target.value)} error={form.errors.job_title} autoComplete="organization-title" />
                    <TextField label={t('contacts.email')} type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={form.errors.email} autoComplete="email" />
                    <TextField label={t('contacts.phone')} type="tel" dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} error={form.errors.phone} autoComplete="tel" />
                    <TextField label="WhatsApp" type="tel" dir="ltr" value={form.data.whatsapp} onChange={(e) => form.setData('whatsapp', e.target.value)} error={form.errors.whatsapp} />
                    <TextField
                        label={t('contacts.whatsappUser')}
                        dir="ltr"
                        value={form.data.whatsapp_username}
                        onChange={(e) => form.setData('whatsapp_username', e.target.value)}
                        placeholder="monentreprise"
                        hint={t('contacts.optionalWithoutAt')}
                        error={form.errors.whatsapp_username}
                    />
                    <SelectField label={t('contacts.linkedCustomer')} value={form.data.customer_id} onChange={(e) => form.setData('customer_id', e.target.value)} error={form.errors.customer_id}>
                        <option value="">{t('common.none')}</option>
                        {linkedOptions.customers.map((customer) => (
                            <option key={customer.id} value={customer.id}>
                                {customer.display_name}
                            </option>
                        ))}
                    </SelectField>
                    <SelectField label={t('contacts.linkedSupplier')} value={form.data.supplier_id} onChange={(e) => form.setData('supplier_id', e.target.value)} error={form.errors.supplier_id}>
                        <option value="">{t('common.none')}</option>
                        {linkedOptions.suppliers.map((supplier) => (
                            <option key={supplier.id} value={supplier.id}>
                                {supplier.name}
                            </option>
                        ))}
                    </SelectField>
                    <TextAreaField className="md:col-span-2" label={t('contacts.notes')} rows={4} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} error={form.errors.notes} />
                </div>
                <Checkbox className="mt-4" label={t('contacts.activeContact')} checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} />
                {form.data.email && (
                    <div className="mt-3">
                        <FormBanner tone="info">{t('contacts.duplicateWarning')}</FormBanner>
                    </div>
                )}
                <div className="mt-5 flex flex-wrap justify-end gap-2">
                    <ButtonLink href={backHref} variant="ghost">
                        {t('common.cancel')}
                    </ButtonLink>
                    <Button type="submit" loading={form.processing}>
                        {form.processing ? t('contacts.saving') : t('common.save')}
                    </Button>
                </div>
            </form>
        </ApplicationShell>
    );
}
