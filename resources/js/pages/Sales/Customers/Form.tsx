import { Button } from '@/components/ui/Button';
import { useI18n } from '@/i18n';
import SalesLayout from '@/layouts/SalesLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Customer = {
    id: number;
    type: string;
    display_name: string;
    company_name: string | null;
    email: string | null;
    phone: string | null;
    whatsapp_username: string | null;
    tax_identifier: string | null;
    billing_address: string | null;
    notes: string | null;
    status: string;
};

const labelClass = 'block text-[13px] font-medium text-ink';
const fieldClass = 'control-input mt-1.5';
const selectClass = 'control-select mt-1.5';

export default function CustomerForm({ customer }: { customer: Customer | null }) {
    const { t } = useI18n();
    const form = useForm({
        type: customer?.type ?? 'individual',
        display_name: customer?.display_name ?? '',
        company_name: customer?.company_name ?? '',
        email: customer?.email ?? '',
        phone: customer?.phone ?? '',
        whatsapp_username: customer?.whatsapp_username ?? '',
        tax_identifier: customer?.tax_identifier ?? '',
        billing_address: customer?.billing_address ?? '',
        notes: customer?.notes ?? '',
        status: customer?.status ?? 'active',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        customer ? form.patch(`/sales/customers/${customer.id}`) : form.post('/sales/customers');
    };

    return (
        <SalesLayout>
            <Head title={customer ? `${t('customers.edit')} · ${customer.display_name}` : t('customers.new')} />
            <form onSubmit={submit} className="mx-auto max-w-3xl">
                <div className="mb-6">
                    {customer && (
                        <Link href={`/sales/customers/${customer.id}`} className="text-sm text-ink-muted">
                            <span className="directional-icon inline-block">←</span> {customer.display_name}
                        </Link>
                    )}
                    <h1 className="mt-1 text-2xl font-semibold tracking-tight text-ink">{customer ? t('customers.edit') : t('customers.new')}</h1>
                </div>

                <div className="grid gap-4 rounded-card border border-line bg-surface p-5 md:grid-cols-2">
                    <label className={labelClass}>
                        {t('customers.customerType')}
                        <select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} className={selectClass}>
                            <option value="individual">{t('customers.individual')}</option>
                            <option value="company">{t('customers.business')}</option>
                        </select>
                    </label>
                    <label className={labelClass}>
                        {t('common.status')}
                        <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className={selectClass}>
                            <option value="active">{t('status.active')}</option>
                            <option value="inactive">{t('status.inactive')}</option>
                        </select>
                    </label>
                    <label className={labelClass}>
                        {t('customers.fullName')}
                        <input required value={form.data.display_name} onChange={(e) => form.setData('display_name', e.target.value)} className={fieldClass} />
                        {form.errors.display_name && <p className="mt-1 text-[13px] text-danger">{form.errors.display_name}</p>}
                    </label>
                    <label className={labelClass}>
                        {t('customers.company')}
                        <input value={form.data.company_name} onChange={(e) => form.setData('company_name', e.target.value)} className={fieldClass} />
                    </label>
                    <label className={labelClass}>
                        {t('customers.email')}
                        <input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={fieldClass} />
                    </label>
                    <label className={labelClass}>
                        {t('customers.phone')}
                        <input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className={fieldClass} />
                    </label>
                    <label className={labelClass}>
                        {t('customers.whatsapp')}
                        <input value={form.data.whatsapp_username} onChange={(e) => form.setData('whatsapp_username', e.target.value)} placeholder="monentreprise" className={fieldClass} />
                        <span className="mt-1 block text-xs font-normal text-ink-faint">{t('customers.whatsappHelp')}</span>
                    </label>
                    <label className={labelClass}>
                        {t('customers.taxId')}
                        <input value={form.data.tax_identifier} onChange={(e) => form.setData('tax_identifier', e.target.value)} className={fieldClass} />
                    </label>
                    <label className={labelClass}>
                        {t('customers.billingAddress')}
                        <textarea rows={2} value={form.data.billing_address} onChange={(e) => form.setData('billing_address', e.target.value)} className={`${fieldClass} h-auto py-2`} />
                    </label>
                    <label className={`${labelClass} md:col-span-2`}>
                        {t('customers.notes')}
                        <textarea rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className={`${fieldClass} h-auto py-2`} />
                    </label>
                </div>

                {Object.values(form.errors).length > 0 && (
                    <div className="mt-3 space-y-1">
                        {Object.values(form.errors).map((error, index) => (
                            <p key={index} className="text-[13px] text-danger">{error}</p>
                        ))}
                    </div>
                )}

                <div className="mt-5 flex gap-2">
                    <Button type="submit" loading={form.processing} loadingText={t('customers.saving')}>
                        {t('common.save')}
                    </Button>
                    {customer && (
                        <Link href={`/sales/customers/${customer.id}`} className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised">
                            {t('common.cancel')}
                        </Link>
                    )}
                </div>
            </form>
        </SalesLayout>
    );
}
