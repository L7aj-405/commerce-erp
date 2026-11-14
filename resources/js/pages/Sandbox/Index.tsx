import { Button, ButtonLink } from '@/components/ui/Button';
import { SandboxBadge } from '@/components/SandboxBadge';
import { useI18n } from '@/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    enabled: boolean;
    canManage: boolean;
    canSwitch: boolean;
    canReset: boolean;
    current: { name: string; status: string; generation: number; stockInitializedAt: string | null } | null;
    stale: boolean;
    modules: { label: string; href: string; hint: string }[];
    unavailable: string[];
};

/**
 * Test-mode entry page. Deliberately standalone (no ApplicationShell): it is reachable while the
 * session is in an isolated sandbox context, so it must not depend on the production navigation.
 * Same routes and POSTs as before; presentation only.
 */
export default function SandboxPage({ enabled, canManage, canSwitch, canReset, current, stale, modules, unavailable }: Props) {
    const { t } = useI18n();
    const { sandbox, flash } = usePage<{ sandbox: { active: boolean; restricted: boolean; id: number | null }; flash?: { success?: string | null } }>().props;
    const [busy, setBusy] = useState(false);
    const submit = (path: string, data = {}) => {
        if (busy) return;
        setBusy(true);
        router.post(path, data, { onFinish: () => setBusy(false) });
    };
    const resetSection = canReset && (
        <section aria-labelledby="reset" className="surface-card p-4">
            <h2 id="reset" className="text-base font-semibold text-ink">
                {t('sandboxPage.resetTitle')}
            </h2>
            <p className="mt-1 text-sm text-ink-muted">{t('sandboxPage.resetHelp')}</p>
            <ButtonLink href="/test-mode/reset" variant="danger" size="sm" className="mt-3">
                {t('sandboxPage.resetLink')}
            </ButtonLink>
        </section>
    );

    return (
        <main className="app-canvas min-h-dvh bg-canvas text-ink">
            <Head title={t('sandboxPage.title')} />
            {sandbox.restricted && (
                <div role="status" className="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-3 border-b border-warning/40 bg-warning-soft px-4 py-3 text-center text-sm font-semibold text-warning">
                    <span>{t('sandboxPage.restrictedBanner')}</span>
                    <Button size="sm" variant="secondary" loading={busy} onClick={() => submit('/test-mode/leave')}>
                        {t('sandboxPage.leave')}
                    </Button>
                </div>
            )}
            <header className="border-b border-line bg-surface/80 px-4 py-3 backdrop-blur sm:px-6">
                <p className="text-sm font-semibold text-ink">
                    10xScale ERP · <span className="text-ink-muted">{t('sandboxPage.title')}</span>
                </p>
            </header>
            <section className="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6">
                {flash?.success && (
                    <p role="status" className="rounded-card border border-success/40 bg-success-soft p-4 text-sm font-semibold text-ink">
                        {flash.success}
                    </p>
                )}
                {sandbox.active && current ? (
                    <>
                        <div>
                            <SandboxBadge kind="test" label={t('sandboxPage.badge')} />
                            <h1 className="mt-3 text-2xl font-semibold text-ink">{t('sandboxPage.currentTitle').replace('{name}', current.name)}</h1>
                            <p className="mt-1 text-sm text-ink-muted">
                                {t('sandboxPage.currentHelp')
                                    .replace('{id}', String(sandbox.id ?? '—'))
                                    .replace('{status}', current.status)
                                    .replace('{generation}', String(current.generation))}
                            </p>
                            <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                <div className="surface-card p-3">
                                    <dt className="text-ink-muted">{t('sandboxPage.generation')}</dt>
                                    <dd className="font-semibold tabular-nums text-ink">{current.generation}</dd>
                                </div>
                                <div className="surface-card p-3">
                                    <dt className="text-ink-muted">{t('sandboxPage.stock')}</dt>
                                    <dd className="font-semibold text-ink">
                                        {current.stockInitializedAt ? (
                                            <>
                                                {t('sandboxPage.stockInitialized')}{' '}
                                                <time dateTime={current.stockInitializedAt}>
                                                    {new Date(current.stockInitializedAt).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })}
                                                </time>
                                            </>
                                        ) : (
                                            t('sandboxPage.stockNotInitialized')
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <section aria-labelledby="available">
                            <h2 id="available" className="mb-3 text-base font-semibold text-ink">
                                {t('sandboxPage.available')}
                            </h2>
                            <ul className="grid gap-3 sm:grid-cols-2">
                                {modules.map((module) => (
                                    <li key={module.href}>
                                        <a href={module.href} className="surface-card block p-4 transition-soft hover:bg-raised focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                                            <span className="font-semibold text-ink">{module.label}</span>
                                            <span className="mt-0.5 block text-sm text-ink-muted">{module.hint}</span>
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </section>
                        {unavailable.length > 0 && (
                            <section aria-labelledby="unavailable">
                                <h2 id="unavailable" className="mb-2 text-base font-semibold text-ink">
                                    {t('sandboxPage.unavailable')}
                                </h2>
                                <ul className="list-disc space-y-1 ps-5 text-sm text-ink-muted">
                                    {unavailable.map((item) => (
                                        <li key={item}>{item}</li>
                                    ))}
                                </ul>
                            </section>
                        )}
                        {resetSection}
                        <Button variant="secondary" loading={busy} onClick={() => submit('/test-mode/leave')}>
                            {t('sandboxPage.leave')}
                        </Button>
                    </>
                ) : (
                    <div className="surface-card space-y-4 p-6">
                        <h1 className="text-2xl font-semibold text-ink">{t('sandboxPage.title')}</h1>
                        {sandbox.restricted ? (
                            stale ? (
                                <div role="alert" className="space-y-3 rounded-card border border-warning/40 bg-warning-soft p-4">
                                    <p className="text-sm font-semibold text-ink">{t('sandboxPage.stale')}</p>
                                    <p className="text-sm text-ink-muted">{t('sandboxPage.staleHelp')}</p>
                                    <div className="flex flex-wrap gap-2">
                                        {enabled && canSwitch && (
                                            <Button loading={busy} onClick={() => submit('/test-mode/enter')}>
                                                {t('sandboxPage.reenter')}
                                            </Button>
                                        )}
                                        <Button variant="secondary" loading={busy} onClick={() => submit('/test-mode/leave')}>
                                            {t('sandboxPage.leave')}
                                        </Button>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-sm text-ink-muted">{t('sandboxPage.contextGone')}</p>
                            )
                        ) : (
                            <>
                                <p className="text-sm text-ink-muted">{enabled ? t('sandboxPage.productionAllowed') : t('sandboxPage.productionDisabled')}</p>
                                <div className="flex flex-wrap gap-2">
                                    {enabled && canSwitch && (
                                        <Button loading={busy} onClick={() => submit('/test-mode/enter')}>
                                            {t('sandboxPage.enter')}
                                        </Button>
                                    )}
                                    {canManage && (
                                        <Button variant={enabled ? 'danger' : 'secondary'} loading={busy} onClick={() => submit('/test-mode/policy', { enabled: !enabled })}>
                                            {enabled ? t('sandboxPage.disablePolicy') : t('sandboxPage.enablePolicy')}
                                        </Button>
                                    )}
                                </div>
                                {resetSection}
                                <a className="inline-flex text-sm font-medium text-ink underline-offset-4 hover:underline" href="/platform">
                                    {t('sandboxPage.backToProduction')}
                                </a>
                            </>
                        )}
                    </div>
                )}
            </section>
        </main>
    );
}
