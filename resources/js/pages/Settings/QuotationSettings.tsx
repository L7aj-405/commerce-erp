import { Button } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
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
    defaultAccentColor: string;
};

export default function QuotationSettings({ settings, resolved, defaultAccentColor }: Props) {
    const form = useForm({
        default_validity_days: settings.default_validity_days ?? resolved.default_validity_days,
        default_terms: settings.default_terms ?? '',
        default_notes: settings.default_notes ?? '',
        footer_text: settings.footer_text ?? '',
        accent_color: settings.accent_color ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/quotation-settings', { preserveScroll: true });
    };

    return (
        <SalesLayout>
            <Head title="Paramètres · Devis" />
            <div className="mx-auto max-w-2xl">
                <PageHeader
                    title="Documents · Devis"
                    description="Options stables pour les devis. L’identité de l’entreprise (logo, ICE, RC, IF, TP, RIB…) provient du profil de documents partagé. Modifier ces réglages n’altère aucun devis déjà émis."
                />
                <form onSubmit={submit} className="space-y-5 rounded-card border border-line bg-surface p-6">
                    <Field label="Durée de validité par défaut (jours)">
                        <input
                            type="number"
                            min={1}
                            max={365}
                            value={form.data.default_validity_days}
                            onChange={(e) => form.setData('default_validity_days', Number(e.target.value))}
                            className={input}
                        />
                    </Field>
                    <Field label="Conditions par défaut">
                        <textarea rows={3} value={form.data.default_terms} onChange={(e) => form.setData('default_terms', e.target.value)} className={input} />
                    </Field>
                    <Field label="Notes par défaut">
                        <textarea rows={2} value={form.data.default_notes} onChange={(e) => form.setData('default_notes', e.target.value)} className={input} />
                    </Field>
                    <Field label="Mention de pied de page (devis)">
                        <input value={form.data.footer_text} onChange={(e) => form.setData('footer_text', e.target.value)} className={input} placeholder="Laisser vide pour reprendre le pied de page des factures" />
                    </Field>
                    <Field label="Couleur d’accent (devis)">
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
                    {Object.values(form.errors).map((err) => err && <p key={err} className="text-sm text-danger">{err}</p>)}
                    <Button type="submit" loading={form.processing} loadingText="Enregistrement…">
                        Enregistrer
                    </Button>
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
