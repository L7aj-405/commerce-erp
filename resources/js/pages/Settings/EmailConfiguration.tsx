import { Button } from '@/components/ui/Button';
import { useFreshAuth } from '@/components/auth/FreshAuthProvider';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

type Setting = {
    id: number;
    sender_name: string;
    sender_email: string;
    smtp_host: string;
    smtp_port: number;
    smtp_username: string;
    smtp_encryption: 'tls' | 'ssl' | 'none';
    reply_to_email: string | null;
    reply_to_name: string | null;
    is_enabled: boolean;
    last_test_ok: boolean | null;
    last_test_message: string | null;
    last_tested_at: string | null;
    has_password: boolean;
};

type Status = 'not_configured' | 'configured' | 'verified';

type Props = {
    organization: { id: number; name: string };
    setting: Setting | null;
    status: Status;
    canUpdate: boolean;
};

const STATUS_STYLE: Record<Status, string> = {
    not_configured: 'bg-raised text-ink-muted',
    configured: 'bg-warning-soft text-warning',
    verified: 'bg-success-soft text-success',
};

export default function EmailConfiguration({ organization, setting, status, canUpdate }: Props) {
    const { t } = useI18n();
    const { submit: submitProtected } = useFreshAuth();
    const [saving, setSaving] = useState(false);
    const statusLabels: Record<Status, string> = { not_configured: t('emailSettings.notConfigured'), configured: t('emailSettings.configured'), verified: t('emailSettings.verified') };
    const form = useForm({
        sender_name: setting?.sender_name ?? organization.name,
        sender_email: setting?.sender_email ?? '',
        smtp_host: setting?.smtp_host ?? '',
        smtp_port: setting?.smtp_port ?? 587,
        smtp_username: setting?.smtp_username ?? '',
        smtp_password: '',
        smtp_encryption: setting?.smtp_encryption ?? ('tls' as 'tls' | 'ssl' | 'none'),
        reply_to_email: setting?.reply_to_email ?? '',
        reply_to_name: setting?.reply_to_name ?? '',
        is_enabled: setting?.is_enabled ?? true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.clearErrors();
        void submitProtected({
            method: 'put', url: '/email-settings', data: form.data, preserveScroll: true,
            onStart: () => setSaving(true),
            onSuccess: () => form.setData('smtp_password', ''),
            onError: (errors) => form.setError(errors),
            onFinish: () => setSaving(false),
        });
    };

    const [testEmail, setTestEmail] = useState('');
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState<{ ok: boolean; message: string } | null>(null);

    const runTest = async () => {
        if (!setting || testing || !testEmail) return;
        setTesting(true);
        setTestResult(null);
        try {
            const response = await fetch('/email-settings/test', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ email: testEmail }),
            });
            setTestResult((await response.json()) as { ok: boolean; message: string });
        } catch {
            setTestResult({ ok: false, message: t('emailSettings.sendFailure') });
        } finally {
            setTesting(false);
        }
    };

    return (
        <ApplicationShell>
            <Head title={t('emailSettings.title')} />
            <main className="mx-auto max-w-3xl">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-ink">{t('emailSettings.title')}</h1>
                        <p className="mt-1 text-sm text-ink-muted">{t('emailSettings.help')}</p>
                    </div>
                    <span className={`inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${STATUS_STYLE[status]}`}>
                        {statusLabels[status]}
                    </span>
                </div>

                {!canUpdate && (
                    <p className="mt-4 rounded-field border border-line bg-raised px-3 py-2 text-xs text-ink-muted">
                        {t('emailSettings.readOnly')}
                    </p>
                )}

                <form onSubmit={submit} className="mt-8 space-y-4">
                    <fieldset disabled={!canUpdate} className="space-y-4">
                        <Section title={t('emailSettings.senderIdentity')}>
                            <Grid>
                                <TextField form={form} name="sender_name" label={t('emailSettings.senderName')} required />
                                <TextField form={form} name="sender_email" label={t('emailSettings.senderAddress')} type="email" required />
                            </Grid>
                            <p className="text-xs text-ink-faint">
                                {t('emailSettings.senderHelp')}
                            </p>
                        </Section>

                        <Section title={t('emailSettings.server')}>
                            <Grid>
                                <TextField form={form} name="smtp_host" label={t('emailSettings.host')} required placeholder="smtp.example.com" />
                                <TextField form={form} name="smtp_port" label={t('emailSettings.port')} type="number" required />
                            </Grid>
                            <Grid>
                                <TextField form={form} name="smtp_username" label={t('emailSettings.username')} required />
                                <label className="block">
                                    <span className="mb-1 block text-xs font-medium text-ink-muted">
                                        {t('emailSettings.password')} {setting?.has_password && <span className="text-ink-faint">({t('emailSettings.keepPassword')})</span>}
                                    </span>
                                    <input
                                        type="password"
                                        autoComplete="new-password"
                                        value={form.data.smtp_password}
                                        onChange={(e) => form.setData('smtp_password', e.target.value)}
                                        placeholder={setting?.has_password ? '••••••••' : ''}
                                        className="w-full rounded-field border border-line-strong px-3 py-2 text-sm"
                                    />
                                    {form.errors.smtp_password && <span className="mt-1 block text-xs text-danger">{form.errors.smtp_password}</span>}
                                </label>
                            </Grid>
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-ink-muted">{t('emailSettings.encryption')}</span>
                                <select
                                    value={form.data.smtp_encryption}
                                    onChange={(e) => form.setData('smtp_encryption', e.target.value as 'tls' | 'ssl' | 'none')}
                                    className="control-select sm:w-56"
                                >
                                    <option value="tls">TLS</option>
                                    <option value="ssl">SSL</option>
                                    <option value="none">{t('emailSettings.none')}</option>
                                </select>
                            </label>
                        </Section>

                        <Section title={t('emailSettings.replyOptional')}>
                            <Grid>
                                <TextField form={form} name="reply_to_email" label={t('emailSettings.replyEmail')} type="email" />
                                <TextField form={form} name="reply_to_name" label={t('emailSettings.replyName')} />
                            </Grid>
                        </Section>

                        <label className="flex items-center gap-2 text-sm text-ink">
                            <input
                                type="checkbox"
                                checked={form.data.is_enabled}
                                onChange={(e) => form.setData('is_enabled', e.target.checked)}
                            />
                            {t('emailSettings.active')}
                        </label>

                        <Button type="submit" loading={saving} loadingText={t('common.saving')}>
                            {t('emailSettings.save')}
                        </Button>
                    </fieldset>
                </form>

                <section className="mt-8 rounded-card border border-line bg-surface p-5">
                    <h2 className="text-sm font-semibold text-ink">{t('emailSettings.testTitle')}</h2>
                    <p className="mt-1 text-xs text-ink-muted">{t('emailSettings.testHelp')}</p>
                    {!setting && (
                        <p className="mt-3 text-xs text-ink-faint">{t('emailSettings.saveBeforeTest')}</p>
                    )}
                    <div className="mt-3 flex flex-wrap items-end gap-2">
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-ink-muted">{t('emailSettings.destination')}</span>
                            <input
                                type="email"
                                value={testEmail}
                                onChange={(e) => setTestEmail(e.target.value)}
                                placeholder="vous@exemple.ma"
                                disabled={!canUpdate || !setting}
                                className="w-64 rounded-field border border-line-strong px-3 py-2 text-sm"
                            />
                        </label>
                        <Button
                            type="button"
                            variant="secondary"
                            loading={testing}
                            loadingText={t('profile.sending')}
                            disabled={!canUpdate || !setting || !testEmail}
                            onClick={runTest}
                        >
                            {t('emailSettings.sendTest')}
                        </Button>
                    </div>
                    {testResult && (
                        <p className={`mt-3 rounded-field px-3 py-2 text-[13px] ${testResult.ok ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'}`}>
                            {testResult.message}
                        </p>
                    )}
                    {!testResult && setting?.last_test_message && (
                        <p className="mt-3 text-xs text-ink-faint">{t('emailSettings.lastTest')} : {setting.last_test_message}</p>
                    )}
                </section>
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
function TextField({ form, name, label: l, type = 'text', required = false, placeholder }: { form: any; name: string; label: string; type?: string; required?: boolean; placeholder?: string }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-ink-muted">{l}</span>
            <input
                type={type}
                required={required}
                value={form.data[name] ?? ''}
                placeholder={placeholder}
                onChange={(e) => form.setData(name, type === 'number' ? Number(e.target.value) : e.target.value)}
                className="w-full rounded-field border border-line-strong px-3 py-2 text-sm"
            />
            {form.errors[name] && <span className="mt-1 block text-xs text-danger">{form.errors[name]}</span>}
        </label>
    );
}
