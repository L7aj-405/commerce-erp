import { Button } from '@/components/ui/Button';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useMemo, useState } from 'react';

type Identifier = { label: string; value: string };
type Profile = {
    legal_name?: string;
    trade_name?: string;
    address?: string;
    phone?: string;
    fax?: string;
    email?: string;
    tax_identifier?: string;
    registration_number?: string;
    patente_number?: string;
    website?: string;
    bank_name?: string;
    bank_rib?: string;
    footer_text?: string;
    accent_color?: string;
    additional_identifiers?: Identifier[];
};
type Props = {
    organization: { id: number; name: string };
    profile: Profile;
    logoUrl: string | null;
    defaultAccentColor: string;
};

export default function DocumentProfile({ organization, profile, logoUrl, defaultAccentColor }: Props) {
    const [removeLogo, setRemoveLogo] = useState(false);
    const form = useForm<{
        legal_name: string;
        trade_name: string;
        address: string;
        phone: string;
        fax: string;
        email: string;
        tax_identifier: string;
        registration_number: string;
        patente_number: string;
        website: string;
        bank_name: string;
        bank_rib: string;
        footer_text: string;
        accent_color: string;
        additional_identifiers: Identifier[];
        logo: File | null;
        remove_logo: boolean;
        _method: 'put';
    }>({
        legal_name: profile.legal_name ?? organization.name,
        trade_name: profile.trade_name ?? '',
        address: profile.address ?? '',
        phone: profile.phone ?? '',
        fax: profile.fax ?? '',
        email: profile.email ?? '',
        tax_identifier: profile.tax_identifier ?? '',
        registration_number: profile.registration_number ?? '',
        patente_number: profile.patente_number ?? '',
        website: profile.website ?? '',
        bank_name: profile.bank_name ?? '',
        bank_rib: profile.bank_rib ?? '',
        footer_text: profile.footer_text ?? '',
        accent_color: profile.accent_color ?? defaultAccentColor,
        additional_identifiers: profile.additional_identifiers ?? [],
        logo: null,
        remove_logo: false,
        _method: 'put',
    });

    const previewLogo = useMemo(() => {
        if (form.data.logo) return URL.createObjectURL(form.data.logo);
        return removeLogo ? null : logoUrl;
    }, [form.data.logo, removeLogo, logoUrl]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, remove_logo: removeLogo }));
        form.post('/document-profile', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.setData('logo', null);
                setRemoveLogo(false);
            },
        });
    };

    const setIdentifier = (index: number, key: keyof Identifier, value: string) =>
        form.setData(
            'additional_identifiers',
            form.data.additional_identifiers.map((item, i) => (i === index ? { ...item, [key]: value } : item)),
        );

    return (
        <ApplicationShell>
            <Head title="Profil des documents" />
            <main className="mx-auto max-w-3xl">
                <h1 className="text-2xl font-semibold tracking-tight text-ink">Profil des documents · Facture</h1>
                <p className="mt-1 text-sm text-ink-muted">
                    Ces informations alimentent toutes les factures émises. Les factures déjà émises ne changent pas.
                </p>

                <form onSubmit={submit} className="mt-8 space-y-4">
                    <Section title="Identité">
                        <div className="flex items-start gap-4">
                            <div className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-card border border-line bg-raised">
                                {previewLogo ? (
                                    <img src={previewLogo} alt="Logo" className="max-h-full max-w-full object-contain" />
                                ) : (
                                    <span className="text-xs text-ink-faint">Aucun logo</span>
                                )}
                            </div>
                            <div className="space-y-2 text-sm">
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    onChange={(e) => {
                                        form.setData('logo', e.target.files?.[0] ?? null);
                                        setRemoveLogo(false);
                                    }}
                                    className="block text-sm"
                                />
                                <p className="text-xs text-ink-faint">PNG, JPG ou WebP · 1 Mo max.</p>
                                {logoUrl && !form.data.logo && (
                                    <label className="flex items-center gap-2 text-xs text-ink-muted">
                                        <input
                                            type="checkbox"
                                            checked={removeLogo}
                                            onChange={(e) => setRemoveLogo(e.target.checked)}
                                        />
                                        Retirer le logo actuel
                                    </label>
                                )}
                                {form.errors.logo && <p className="text-xs text-danger">{form.errors.logo}</p>}
                            </div>
                        </div>
                        <Grid>
                            <TextField form={form} name="legal_name" label="Raison sociale" required />
                            <TextField form={form} name="trade_name" label="Nom commercial" />
                        </Grid>
                    </Section>

                    <Section title="Coordonnées">
                        <TextField form={form} name="address" label="Adresse" />
                        <Grid>
                            <TextField form={form} name="phone" label="Téléphone" />
                            <TextField form={form} name="fax" label="Fax" />
                        </Grid>
                        <TextField form={form} name="email" label="Email" type="email" />
                    </Section>

                    <Section title="Identifiants légaux">
                        <Grid>
                            <TextField form={form} name="tax_identifier" label="ICE" />
                            <TextField form={form} name="registration_number" label="RC" />
                            <TextField form={form} name="patente_number" label="TP (patente)" />
                            <TextField form={form} name="website" label="Site web" type="url" />
                        </Grid>
                        <fieldset className="mt-2">
                            <legend className="text-xs font-medium text-ink-muted">Identifiants additionnels (IF, etc.)</legend>
                            {form.data.additional_identifiers.map((identifier, index) => (
                                <div key={index} className="mt-2 flex gap-2">
                                    <input
                                        aria-label={`Libellé ${index + 1}`}
                                        value={identifier.label}
                                        onChange={(e) => setIdentifier(index, 'label', e.target.value)}
                                        placeholder="Libellé"
                                        className="w-1/3 rounded-field border border-line-strong px-3 py-2 text-sm"
                                    />
                                    <input
                                        aria-label={`Valeur ${index + 1}`}
                                        value={identifier.value}
                                        onChange={(e) => setIdentifier(index, 'value', e.target.value)}
                                        placeholder="Valeur"
                                        className="flex-1 rounded-field border border-line-strong px-3 py-2 text-sm"
                                    />
                                    <button
                                        type="button"
                                        onClick={() =>
                                            form.setData(
                                                'additional_identifiers',
                                                form.data.additional_identifiers.filter((_, i) => i !== index),
                                            )
                                        }
                                        className="rounded-field border border-line-strong px-3 text-sm"
                                    >
                                        Retirer
                                    </button>
                                </div>
                            ))}
                            {form.data.additional_identifiers.length < 10 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData('additional_identifiers', [
                                            ...form.data.additional_identifiers,
                                            { label: '', value: '' },
                                        ])
                                    }
                                    className="mt-2 text-sm text-ink-muted"
                                >
                                    + Ajouter un identifiant
                                </button>
                            )}
                        </fieldset>
                    </Section>

                    <Section title="Banque">
                        <Grid>
                            <TextField form={form} name="bank_name" label="Banque" />
                            <TextField form={form} name="bank_rib" label="RIB" />
                        </Grid>
                    </Section>

                    <Section title="Mise en page">
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-ink-muted">Couleur principale</span>
                            <div className="flex items-center gap-2">
                                <input
                                    type="color"
                                    value={form.data.accent_color}
                                    onChange={(e) => form.setData('accent_color', e.target.value)}
                                    className="h-10 w-14 rounded-field border border-line-strong"
                                />
                                <input
                                    value={form.data.accent_color}
                                    onChange={(e) => form.setData('accent_color', e.target.value)}
                                    className="w-32 rounded-field border border-line-strong px-3 py-2 text-sm"
                                />
                            </div>
                            {form.errors.accent_color && (
                                <p className="mt-1 text-xs text-danger">{form.errors.accent_color}</p>
                            )}
                        </label>
                        <label className="mt-3 block">
                            <span className="mb-1 block text-xs font-medium text-ink-muted">Mentions de pied de page</span>
                            <textarea
                                value={form.data.footer_text}
                                onChange={(e) => form.setData('footer_text', e.target.value)}
                                className="w-full rounded-field border border-line-strong px-3 py-2 text-sm"
                            />
                        </label>
                    </Section>

                    <Button type="submit" loading={form.processing} loadingText="Enregistrement…">
                        Enregistrer le profil
                    </Button>
                </form>
            </main>
        </ApplicationShell>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-card border border-line bg-surface p-5">
            <h2 className="mb-3 text-sm font-semibold text-ink">{title}</h2>
            <div className="space-y-3">{children}</div>
        </section>
    );
}

function Grid({ children }: { children: ReactNode }) {
    return <div className="grid gap-3 sm:grid-cols-2">{children}</div>;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function TextField({ form, name, label: l, type = 'text', required = false }: { form: any; name: string; label: string; type?: string; required?: boolean }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-ink-muted">{l}</span>
            <input
                type={type}
                required={required}
                value={form.data[name] ?? ''}
                onChange={(e) => form.setData(name, e.target.value)}
                className="w-full rounded-field border border-line-strong px-3 py-2 text-sm"
            />
            {form.errors[name] && <span className="mt-1 block text-xs text-danger">{form.errors[name]}</span>}
        </label>
    );
}
