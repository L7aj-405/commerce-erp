import { Button } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import SalesLayout from '@/layouts/SalesLayout';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

type Props = {
    organization: { id: number; name: string };
    settings: {
        default_validity_days?: number;
        default_terms?: string | null;
        default_notes?: string | null;
        footer_text?: string | null;
        accent_color?: string | null;
    };
    resolved: { default_validity_days: number };
    quotationNumbering: { year: number; next_number: number; max_allocated_number: number };
    defaultAccentColor: string;
    canUpdate: boolean;
};

export default function QuotationSettings({ settings, resolved, quotationNumbering, defaultAccentColor, canUpdate }: Props) {
    const { t } = useI18n();
    const form = useForm({
        default_validity_days: settings.default_validity_days ?? resolved.default_validity_days,
        default_terms: settings.default_terms ?? '',
        default_notes: settings.default_notes ?? '',
        footer_text: settings.footer_text ?? '',
        accent_color: settings.accent_color ?? '',
        quotation_numbering_year: quotationNumbering.year,
        quotation_next_number: quotationNumbering.next_number,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/quotation-settings', { preserveScroll: true });
    };

    return (
        <SalesLayout>
            <Head title={t('quotationSettings.headTitle')} />
            <div className="mx-auto max-w-2xl">
                <PageHeader
                    title={t('quotationSettings.title')}
                    description={t('quotationSettings.description')}
                />
                {!canUpdate && (
                    <p className="mb-3 rounded-field border border-line bg-raised px-3 py-2 text-xs text-ink-muted">
                        {t('quotationSettings.readOnly')}
                    </p>
                )}
                <form onSubmit={submit} className="space-y-5 rounded-card border border-line bg-surface p-6">
                <fieldset disabled={!canUpdate} className="space-y-5">
                    <Field label={t('quotationSettings.validityDays')}>
                        <input
                            type="number"
                            min={1}
                            max={365}
                            value={form.data.default_validity_days}
                            onChange={(e) => form.setData('default_validity_days', Number(e.target.value))}
                            className={input}
                        />
                    </Field>
                    <Field label={t('quotationSettings.defaultTerms')}>
                        <textarea rows={3} value={form.data.default_terms} onChange={(e) => form.setData('default_terms', e.target.value)} className={input} />
                    </Field>
                    <Field label={t('quotationSettings.defaultNotes')}>
                        <textarea rows={2} value={form.data.default_notes} onChange={(e) => form.setData('default_notes', e.target.value)} className={input} />
                    </Field>
                    <Field label={t('quotationSettings.footer')}>
                        <input value={form.data.footer_text} onChange={(e) => form.setData('footer_text', e.target.value)} className={input} placeholder={t('quotationSettings.footerPlaceholder')} />
                    </Field>
                    <Field label={t('quotationSettings.accent')}>
                        <div className="flex items-center gap-2">
                            <input
                                value={form.data.accent_color}
                                onChange={(e) => form.setData('accent_color', e.target.value)}
                                placeholder={defaultAccentColor}
                                className={input}
                            />
                            <span className="h-8 w-8 shrink-0 rounded-field border border-line" style={{ background: form.data.accent_color || defaultAccentColor }} />
                        </div>
                    </Field>
                    <section className="rounded-card border border-line bg-raised p-4">
                        <h2 className="text-sm font-semibold text-ink">{t('quotationSettings.numbering')}</h2>
                        <p className="mt-1 text-xs text-ink-muted">{t('quotationSettings.numberingHelp')}</p>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            <Field label={t('docProfile.year')}>
                                <input
                                    type="number"
                                    min={2000}
                                    max={2100}
                                    value={form.data.quotation_numbering_year}
                                    onChange={(e) => form.setData('quotation_numbering_year', Number(e.target.value))}
                                    className={input}
                                    required
                                />
                            </Field>
                            <Field label={t('docProfile.nextNumber')}>
                                <input
                                    type="number"
                                    min={1}
                                    value={form.data.quotation_next_number}
                                    onChange={(e) => form.setData('quotation_next_number', Number(e.target.value))}
                                    className={input}
                                    required
                                />
                            </Field>
                        </div>
                        <p className="mt-2 text-xs text-ink-muted">
                            {t('quotationSettings.nextHelp')}
                        </p>
                        {quotationNumbering.max_allocated_number > 0 && (
                            <p className="mt-1 text-xs text-ink-faint">
                                {t('quotationSettings.lastAllocated').replace('{year}', String(quotationNumbering.year)).replace('{number}', String(quotationNumbering.max_allocated_number))}
                            </p>
                        )}
                    </section>
                    {Object.values(form.errors).map((err) => err && <p key={err} className="text-sm text-danger">{err}</p>)}
                    <Button type="submit" loading={form.processing} loadingText={t('common.saving')}>
                        {t('common.save')}
                    </Button>
                </fieldset>
                </form>
            </div>
        </SalesLayout>
    );
}

const input = 'w-full rounded-field border border-line-strong px-3 py-2 text-sm';

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-ink-muted">{label}</span>
            {children}
        </label>
    );
}
