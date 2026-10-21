import { Button, ButtonLink } from "@/components/ui/Button";
import PageHeader from "@/components/ui/PageHeader";
import { useI18n } from "@/i18n";
import ApplicationShell from "@/layouts/ApplicationShell";
import { Head, useForm } from "@inertiajs/react";
import type { FormEvent, ReactNode } from "react";
import { useMemo, useState } from "react";

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
    additional_identifiers?: Identifier[];
};
type Props = {
    organization: { id: number; name: string };
    profile: Profile;
    invoiceNumbering: {
        year: number;
        next_number: number;
        max_allocated_number: number;
    };
    logoUrl: string | null;
    canUpdate: boolean;
};

export default function DocumentProfile({
    organization,
    profile,
    invoiceNumbering,
    logoUrl,
    canUpdate,
}: Props) {
    const { t } = useI18n();
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
        invoice_numbering_year: number;
        invoice_next_number: number;
        additional_identifiers: Identifier[];
        logo: File | null;
        remove_logo: boolean;
        _method: "put";
    }>({
        legal_name: profile.legal_name ?? organization.name,
        trade_name: profile.trade_name ?? "",
        address: profile.address ?? "",
        phone: profile.phone ?? "",
        fax: profile.fax ?? "",
        email: profile.email ?? "",
        tax_identifier: profile.tax_identifier ?? "",
        registration_number: profile.registration_number ?? "",
        patente_number: profile.patente_number ?? "",
        website: profile.website ?? "",
        bank_name: profile.bank_name ?? "",
        bank_rib: profile.bank_rib ?? "",
        footer_text: profile.footer_text ?? "",
        invoice_numbering_year: invoiceNumbering.year,
        invoice_next_number: invoiceNumbering.next_number,
        additional_identifiers: profile.additional_identifiers ?? [],
        logo: null,
        remove_logo: false,
        _method: "put",
    });

    const previewLogo = useMemo(() => {
        if (form.data.logo) return URL.createObjectURL(form.data.logo);
        return removeLogo ? null : logoUrl;
    }, [form.data.logo, removeLogo, logoUrl]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, remove_logo: removeLogo }));
        form.post("/document-profile", {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.setData("logo", null);
                setRemoveLogo(false);
            },
        });
    };

    const setIdentifier = (
        index: number,
        key: keyof Identifier,
        value: string,
    ) =>
        form.setData(
            "additional_identifiers",
            form.data.additional_identifiers.map((item, i) =>
                i === index ? { ...item, [key]: value } : item,
            ),
        );

    return (
        <ApplicationShell>
            <Head title={t("docProfile.title")} />
            <div className="mx-auto max-w-5xl">
                <PageHeader
                    title={t("docProfile.title")}
                    description={t("docProfile.description")}
                    actions={
                        <ButtonLink
                            href="/document-profile/studio"
                            variant="secondary"
                        >
                            {t("docProfile.openStudio")}
                        </ButtonLink>
                    }
                />

                {!canUpdate && (
                    <p className="mb-6 rounded-field border border-line bg-raised px-3 py-2 text-xs text-ink-muted">
                        {t("docProfile.readOnly")}
                    </p>
                )}

                <form onSubmit={submit} className="space-y-5">
                    <fieldset disabled={!canUpdate} className="space-y-5">
                        <Section
                            title={t("docProfile.general")}
                            description={t("docProfile.generalHelp")}
                        >
                            <Grid>
                                <TextField
                                    form={form}
                                    name="trade_name"
                                    label={t("docProfile.tradeName")}
                                />
                                <TextField
                                    form={form}
                                    name="legal_name"
                                    label={t("docProfile.legalName")}
                                    required
                                />
                            </Grid>
                        </Section>

                        <Section
                            title={t("docProfile.contactDetails")}
                            description={t("docProfile.contactHelp")}
                        >
                            <TextField
                                form={form}
                                name="address"
                                label={t("docProfile.address")}
                            />
                            <Grid>
                                <TextField
                                    form={form}
                                    name="phone"
                                    label={t("docProfile.phone")}
                                />
                                <TextField form={form} name="fax" label="Fax" />
                                <TextField
                                    form={form}
                                    name="email"
                                    label="Email"
                                    type="email"
                                />
                                <TextField
                                    form={form}
                                    name="website"
                                    label={t("docProfile.website")}
                                />
                            </Grid>
                        </Section>

                        <Section
                            title={t("docProfile.legal")}
                            description={t("docProfile.legalHelp")}
                        >
                            <Grid>
                                <TextField
                                    form={form}
                                    name="tax_identifier"
                                    label="ICE"
                                />
                                <TextField
                                    form={form}
                                    name="registration_number"
                                    label="RC"
                                />
                                <TextField
                                    form={form}
                                    name="patente_number"
                                    label={t("docProfile.patente")}
                                />
                            </Grid>
                            <fieldset className="mt-2">
                                <legend className="text-xs font-medium text-ink-muted">
                                    {t("docProfile.additionalIds")}
                                </legend>
                                {form.data.additional_identifiers.map(
                                    (identifier, index) => (
                                        <div
                                            key={index}
                                            className="mt-2 grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_auto]"
                                        >
                                            <input
                                                aria-label={t(
                                                    "docProfile.labelNumber",
                                                ).replace(
                                                    "{number}",
                                                    String(index + 1),
                                                )}
                                                value={identifier.label}
                                                onChange={(e) =>
                                                    setIdentifier(
                                                        index,
                                                        "label",
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder={t(
                                                    "docProfile.label",
                                                )}
                                                className="rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                                            />
                                            <input
                                                aria-label={t(
                                                    "docProfile.valueNumber",
                                                ).replace(
                                                    "{number}",
                                                    String(index + 1),
                                                )}
                                                value={identifier.value}
                                                onChange={(e) =>
                                                    setIdentifier(
                                                        index,
                                                        "value",
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder={t(
                                                    "docProfile.value",
                                                )}
                                                className="rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                                            />
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    form.setData(
                                                        "additional_identifiers",
                                                        form.data.additional_identifiers.filter(
                                                            (_, i) =>
                                                                i !== index,
                                                        ),
                                                    )
                                                }
                                                className="rounded-field border border-line-strong px-3 py-2 text-sm text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                                            >
                                                {t("docProfile.remove")}
                                            </button>
                                        </div>
                                    ),
                                )}
                                {form.data.additional_identifiers.length <
                                    10 && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            form.setData(
                                                "additional_identifiers",
                                                [
                                                    ...form.data
                                                        .additional_identifiers,
                                                    { label: "", value: "" },
                                                ],
                                            )
                                        }
                                        className="mt-3 rounded-field px-3 py-2 text-sm font-medium text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                                    >
                                        {t("docProfile.addIdentifier")}
                                    </button>
                                )}
                            </fieldset>
                        </Section>

                        <Section
                            title={t("docProfile.documentIdentity")}
                            description={t("docProfile.documentIdentityHelp")}
                        >
                            <div className="flex items-start gap-4">
                                <div className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-card border border-line bg-raised">
                                    {previewLogo ? (
                                        <img
                                            src={previewLogo}
                                            alt="Logo"
                                            className="max-h-full max-w-full object-contain"
                                        />
                                    ) : (
                                        <span className="text-xs text-ink-faint">
                                            {t("docProfile.noLogo")}
                                        </span>
                                    )}
                                </div>
                                <div className="space-y-2 text-sm">
                                    <input
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        onChange={(e) => {
                                            form.setData(
                                                "logo",
                                                e.target.files?.[0] ?? null,
                                            );
                                            setRemoveLogo(false);
                                        }}
                                        className="block text-sm"
                                    />
                                    <p className="text-xs text-ink-faint">
                                        {t("docProfile.logoFormats")}
                                    </p>
                                    {logoUrl && !form.data.logo && (
                                        <label className="flex items-center gap-2 text-xs text-ink-muted">
                                            <input
                                                type="checkbox"
                                                checked={removeLogo}
                                                onChange={(e) =>
                                                    setRemoveLogo(
                                                        e.target.checked,
                                                    )
                                                }
                                            />
                                            {t("docProfile.removeLogo")}
                                        </label>
                                    )}
                                    {form.errors.logo && (
                                        <p className="text-xs text-danger">
                                            {form.errors.logo}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <Grid>
                                <TextField
                                    form={form}
                                    name="bank_name"
                                    label={t("docProfile.bank")}
                                />
                                <TextField
                                    form={form}
                                    name="bank_rib"
                                    label="RIB"
                                />
                            </Grid>
                            <div className="rounded-field border border-line bg-raised px-3 py-3 text-sm text-ink-muted">
                                {t("docProfile.studioHelp")}{" "}
                                <ButtonLink
                                    href="/document-profile/studio"
                                    variant="ghost"
                                    size="sm"
                                >
                                    {t("pdfStudio.title")}
                                </ButtonLink>
                            </div>
                            <label className="mt-3 block">
                                <span className="mb-1 block text-xs font-medium text-ink-muted">
                                    {t("docProfile.footer")}
                                </span>
                                <textarea
                                    value={form.data.footer_text}
                                    onChange={(e) =>
                                        form.setData(
                                            "footer_text",
                                            e.target.value,
                                        )
                                    }
                                    className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                                />
                            </label>
                        </Section>

                        <Section
                            title={t("docProfile.invoiceNumbering")}
                            description={t("docProfile.invoiceNumberingHelp")}
                        >
                            <Grid>
                                <TextField
                                    form={form}
                                    name="invoice_numbering_year"
                                    label={t("docProfile.year")}
                                    type="number"
                                    required
                                />
                                <TextField
                                    form={form}
                                    name="invoice_next_number"
                                    label={t("docProfile.nextNumber")}
                                    type="number"
                                    required
                                />
                            </Grid>
                            <p className="text-xs text-ink-muted">
                                {t("docProfile.invoiceNextHelp")}
                            </p>
                            {invoiceNumbering.max_allocated_number > 0 && (
                                <p className="text-xs text-ink-faint">
                                    {t("docProfile.lastAllocatedInvoice")
                                        .replace(
                                            "{year}",
                                            String(invoiceNumbering.year),
                                        )
                                        .replace(
                                            "{number}",
                                            String(
                                                invoiceNumbering.max_allocated_number,
                                            ),
                                        )}
                                </p>
                            )}
                        </Section>

                        <div className="sticky bottom-4 z-10 flex justify-end rounded-card border border-line bg-surface/95 p-3 shadow-pop backdrop-blur">
                            <Button
                                type="submit"
                                loading={form.processing}
                                loadingText={t("common.saving")}
                            >
                                {t("common.save")}
                            </Button>
                        </div>
                    </fieldset>
                </form>
            </div>
        </ApplicationShell>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-card border border-line bg-surface p-5 shadow-soft">
            <div className="mb-4">
                <h2 className="text-sm font-semibold text-ink">{title}</h2>
                {description && (
                    <p className="mt-1 text-sm text-ink-muted">{description}</p>
                )}
            </div>
            <div className="space-y-3">{children}</div>
        </section>
    );
}

function Grid({ children }: { children: ReactNode }) {
    return <div className="grid gap-3 sm:grid-cols-2">{children}</div>;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function TextField({
    form,
    name,
    label: l,
    type = "text",
    required = false,
}: {
    form: any;
    name: string;
    label: string;
    type?: string;
    required?: boolean;
}) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-ink-muted">
                {l}
            </span>
            <input
                type={type}
                required={required}
                value={form.data[name] ?? ""}
                onChange={(e) => form.setData(name, e.target.value)}
                className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
            />
            {form.errors[name] && (
                <span className="mt-1 block text-xs text-danger">
                    {form.errors[name]}
                </span>
            )}
        </label>
    );
}
