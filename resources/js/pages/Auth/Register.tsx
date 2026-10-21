import AuthLayout from '@/components/auth/AuthLayout';
import { Button, DownloadLink } from '@/components/ui/Button';
import { PasswordField, TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    legalLinks: {
        privacy: string;
        terms: string;
    };
};

export default function Register({}: Props) {
    const { t } = useI18n();
    const { props } = usePage<{ errors?: Record<string, string> }>();
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
    }

    return (
        <>
            <Head title={t('auth.createAccount')} />

            <AuthLayout
                title={t('auth.createAccount')}
                subtitle={t('auth.registerSubtitle')}
                aside={t('auth.registerAside')}
                footer={
                    <>
                        {t('auth.alreadyAccount')}{' '}
                        <Link href="/login" className="font-medium text-ink underline-offset-4 hover:underline">
                            {t('auth.signIn')}
                        </Link>
                    </>
                }
            >
                <form onSubmit={submit} className="space-y-4" noValidate>
                    {props.errors?.google && (
                        <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{props.errors.google}</p>
                    )}

                    <TextField
                        label={t('auth.fullName')}
                        name="name"
                        autoComplete="name"
                        autoFocus
                        required
                        placeholder={t('auth.fullNamePlaceholder')}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                    />

                    <TextField
                        label={t('auth.email')}
                        type="email"
                        name="email"
                        autoComplete="email"
                        required
                        placeholder={t('auth.emailPlaceholder')}
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                    />

                    <PasswordField
                        label={t('auth.password')}
                        name="password"
                        autoComplete="new-password"
                        required
                        placeholder={t('auth.passwordMin10')}
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        error={form.errors.password}
                        hint={t('auth.passwordHint')}
                    />

                    <PasswordField
                        label={t('auth.passwordConfirmation')}
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        placeholder={t('auth.repeatPassword')}
                        value={form.data.password_confirmation}
                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                        error={form.errors.password_confirmation}
                    />

                    <Button type="submit" block size="lg" disabled={form.processing}>
                        {form.processing ? t('auth.creating') : t('auth.createMyAccount')}
                    </Button>

                    <div className="flex items-center gap-3 py-1 text-xs uppercase tracking-wide text-ink-muted">
                        <span className="h-px flex-1 bg-line" />
                        {t('auth.or')}
                        <span className="h-px flex-1 bg-line" />
                    </div>

                    <DownloadLink href="/auth/google/redirect" variant="secondary" block size="lg">
                        <span className="font-semibold">G</span>
                        {t('auth.continueGoogle')}
                    </DownloadLink>
                </form>
            </AuthLayout>
        </>
    );
}
