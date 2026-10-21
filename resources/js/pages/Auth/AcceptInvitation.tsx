import AuthLayout from "@/components/auth/AuthLayout";
import { Button } from "@/components/ui/Button";
import { FormBanner, PasswordField, TextField } from "@/components/ui/form";
import { useI18n } from "@/i18n";
import { Head, Link, router, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";

type Props = {
    status: "invalid" | "pending";
    token?: string;
    invitation?: {
        organization: string;
        role: string;
        email: string;
        expires_at: string;
    };
    userExists?: boolean;
    authenticatedEmail?: string | null;
};

export default function AcceptInvitation({
    status,
    token,
    invitation,
    userExists,
    authenticatedEmail,
}: Props) {
    const { t } = useI18n();
    if (status === "invalid" || !invitation || !token) {
        return (
            <>
                <Head title={t("auth.invalidInvitation")} />
                <AuthLayout
                    title={t("auth.invalidInvitation")}
                    subtitle={t("auth.invalidInvitationSubtitle")}
                >
                    <FormBanner tone="danger">
                        {t("auth.invalidInvitationHelp")}
                    </FormBanner>
                    <p className="mt-4 text-sm text-ink-muted">
                        <Link
                            href="/login"
                            className="font-medium text-ink underline-offset-4 hover:underline"
                        >
                            {t("auth.backToLogin")}
                        </Link>
                    </p>
                </AuthLayout>
            </>
        );
    }

    const loggedInAsSomeoneElse =
        !!authenticatedEmail && authenticatedEmail !== invitation.email;

    return (
        <>
            <Head title={t("auth.acceptInvitation")} />
            <AuthLayout
                title={`${t("auth.join")} ${invitation.organization}`}
                subtitle={`${t("auth.invitedAs")} « ${invitation.role} » (${invitation.email}).`}
            >
                {loggedInAsSomeoneElse ? (
                    <ExistingUserMismatch
                        email={invitation.email}
                        authenticatedEmail={authenticatedEmail!}
                    />
                ) : userExists ? (
                    <ExistingUserAccept
                        email={invitation.email}
                        authenticated={!!authenticatedEmail}
                        token={token}
                    />
                ) : (
                    <NewUserAccept email={invitation.email} token={token} />
                )}
            </AuthLayout>
        </>
    );
}

function ExistingUserMismatch({
    email,
    authenticatedEmail,
}: {
    email: string;
    authenticatedEmail: string;
}) {
    const { t } = useI18n();
    return (
        <div className="space-y-4">
            <FormBanner tone="danger">
                {t("auth.invitationMismatch")
                    .replace("{email}", email)
                    .replace("{authenticated}", authenticatedEmail)}
            </FormBanner>
            <Button
                type="button"
                variant="secondary"
                block
                onClick={() => router.post("/logout")}
            >
                {t("auth.signOut")}
            </Button>
            <p className="text-center text-[13px] text-ink-faint">
                {t("auth.invitationReconnect").replace("{email}", email)}
            </p>
        </div>
    );
}

function ExistingUserAccept({
    email,
    authenticated,
    token,
}: {
    email: string;
    authenticated: boolean;
    token: string;
}) {
    const { t } = useI18n();
    const form = useForm({});
    const errors = form.errors as Record<string, string>;
    const accept = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/invitations/${token}`);
    };

    if (!authenticated) {
        return (
            <div className="space-y-4">
                <p className="text-sm text-ink-muted">
                    {t("auth.existingAccount")} ({email})
                </p>
                <Link
                    href="/login"
                    className="inline-flex w-full items-center justify-center rounded-field bg-primary px-4 py-2.5 text-sm font-medium text-primary-fg hover:bg-primary-hover"
                >
                    {t("auth.signIn")}
                </Link>
            </div>
        );
    }

    return (
        <form onSubmit={accept} className="space-y-4">
            {errors.email && (
                <FormBanner tone="danger">{errors.email}</FormBanner>
            )}
            <Button type="submit" block size="lg" disabled={form.processing}>
                {form.processing
                    ? t("auth.accepting")
                    : t("auth.acceptInvitation")}
            </Button>
        </form>
    );
}

function NewUserAccept({ email, token }: { email: string; token: string }) {
    const { t } = useI18n();
    const form = useForm({ name: "", password: "", password_confirmation: "" });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/invitations/${token}`, {
            onFinish: () => form.reset("password", "password_confirmation"),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4" noValidate>
            <TextField label={t("auth.email")} value={email} disabled />
            <TextField
                label={t("auth.fullName")}
                autoComplete="name"
                autoFocus
                required
                placeholder={t("auth.fullNamePlaceholder")}
                value={form.data.name}
                onChange={(e) => form.setData("name", e.target.value)}
                error={form.errors.name}
            />
            <PasswordField
                label={t("auth.password")}
                autoComplete="new-password"
                required
                placeholder={t("auth.passwordMin10")}
                value={form.data.password}
                onChange={(e) => form.setData("password", e.target.value)}
                error={form.errors.password}
                hint={t("auth.passwordHint")}
            />
            <PasswordField
                label={t("auth.passwordConfirmation")}
                autoComplete="new-password"
                required
                placeholder={t("auth.repeatPassword")}
                value={form.data.password_confirmation}
                onChange={(e) =>
                    form.setData("password_confirmation", e.target.value)
                }
                error={form.errors.password_confirmation}
            />
            <Button type="submit" block size="lg" disabled={form.processing}>
                {form.processing ? t("auth.creating") : t("auth.createAndJoin")}
            </Button>
        </form>
    );
}
