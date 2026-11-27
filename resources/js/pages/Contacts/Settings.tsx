import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { useConfirm } from '@/components/ui/ConfirmDialog';
import { TextField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import { Badge } from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

type Role = { id: number; name: string; system_key: string | null; is_system: boolean; active: boolean };
type Term = { id: number; name: string; days: number; is_system: boolean };
type Conflict = {
    id: number;
    reason: string;
    resolution: string;
    details: Record<string, unknown> | null;
    source: { type: string; id: number; name: string | null };
    related: { type: string; id: number; name: string | null } | null;
    party_id: number | null;
};
type Props = { roles: Role[]; paymentTerms: Term[]; conflicts: Conflict[] | null; can: { manageRoles: boolean; manageTerms: boolean } };

const Section = ({ title, help, children }: { title: string; help?: string; children: ReactNode }) => (
    <section className="surface-card mb-6 p-5">
        <h2 className="text-base font-semibold text-ink">{title}</h2>
        {help && <p className="mb-4 mt-1 text-[13px] text-ink-muted">{help}</p>}
        {children}
    </section>
);

export default function ContactSettings({ roles, paymentTerms, conflicts, can }: Props) {
    const { t } = useI18n();
    const confirm = useConfirm();
    const errors = usePage().props.errors as Record<string, string> | undefined;
    const [roleName, setRoleName] = useState('');
    const [termDays, setTermDays] = useState('');
    const [termName, setTermName] = useState('');
    const [busy, setBusy] = useState<'role' | 'term' | null>(null);

    const addRole = (event: FormEvent) => {
        event.preventDefault();
        setBusy('role');
        router.post('/contacts/settings/roles', { name: roleName }, { preserveScroll: true, onSuccess: () => setRoleName(''), onFinish: () => setBusy(null) });
    };
    const addTerm = (event: FormEvent) => {
        event.preventDefault();
        setBusy('term');
        router.post('/contacts/settings/payment-terms', { days: termDays, name: termName || null }, { preserveScroll: true, onSuccess: () => { setTermDays(''); setTermName(''); }, onFinish: () => setBusy(null) });
    };
    const deactivate = async (role: Role) => {
        if (await confirm({ title: t('cts.settings.deactivateTitle'), description: t('cts.settings.deactivateHelp'), confirmLabel: t('cts.settings.deactivate'), danger: true })) {
            router.post(`/contacts/settings/roles/${role.id}/deactivate`, {}, { preserveScroll: true });
        }
    };
    const roleName_ = (role: Role) => (role.system_key ? t(`cts.role.${role.system_key}` as never) : role.name);

    return (
        <ApplicationShell>
            <Head title={t('cts.settings.title')} />
            <PageHeader title={t('cts.settings.title')} description={t('cts.settings.description')} />

            <Section title={t('cts.settings.roles')} help={t('cts.settings.rolesHelp')}>
                <ul className="mb-4 divide-y divide-line text-sm">
                    {roles.map((role) => (
                        <li key={role.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span className="flex items-center gap-2">
                                <span className={role.active ? 'text-ink' : 'text-ink-faint line-through'}>{roleName_(role)}</span>
                                <Badge variant={role.is_system ? 'neutral' : 'accent'} dot={false} size="sm">
                                    {t(role.is_system ? 'cts.settings.system' : 'cts.settings.custom')}
                                </Badge>
                                {!role.active && <Badge variant="neutral" dot={false} size="sm">{t('cts.status.inactive')}</Badge>}
                            </span>
                            {can.manageRoles && !role.is_system && role.active && (
                                <Button size="sm" variant="ghost" onClick={() => deactivate(role)}>
                                    {t('cts.settings.deactivate')}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
                {can.manageRoles && (
                    <form onSubmit={addRole} className="flex flex-wrap items-end gap-3" noValidate>
                        <TextField className="w-full max-w-xs" label={t('cts.newRole')} value={roleName} maxLength={80} onChange={(e) => setRoleName(e.target.value)} error={errors?.name} />
                        <Button type="submit" loading={busy === 'role'} loadingText={t('cts.saving')} disabled={!roleName.trim()}>
                            {t('cts.settings.addRole')}
                        </Button>
                    </form>
                )}
                <p className="mt-3 text-[13px] text-ink-muted">{t('cts.settings.noPrivilege')}</p>
            </Section>

            <Section title={t('cts.settings.terms')} help={t('cts.settings.termsHelp')}>
                <ul className="mb-4 divide-y divide-line text-sm">
                    {paymentTerms.map((term) => (
                        <li key={term.id} className="flex items-center justify-between gap-2 py-2">
                            <span className="text-ink">{term.days === 0 ? t('cts.termCash') : term.name}</span>
                            <span className="text-[13px] text-ink-muted">
                                {term.days} {t('cts.days')}
                                {term.is_system ? ` · ${t('cts.settings.system')}` : ''}
                            </span>
                        </li>
                    ))}
                </ul>
                {can.manageTerms && (
                    <form onSubmit={addTerm} className="flex flex-wrap items-end gap-3" noValidate>
                        <TextField className="w-40" label={t('cts.settings.delayDays')} type="number" min={0} max={365} value={termDays} onChange={(e) => setTermDays(e.target.value)} error={errors?.days} />
                        <TextField className="w-full max-w-xs" label={t('cts.settings.termName')} hint={t('cts.settings.termNameHelp')} value={termName} maxLength={80} onChange={(e) => setTermName(e.target.value)} error={errors?.name} />
                        <Button type="submit" loading={busy === 'term'} loadingText={t('cts.saving')} disabled={termDays === ''}>
                            {t('cts.settings.addTerm')}
                        </Button>
                    </form>
                )}
                <p className="mt-3 text-[13px] text-ink-muted">{t('cts.settings.termsNote')}</p>
            </Section>

            {conflicts !== null && (
                <Section title={t('cts.settings.conflicts')} help={t('cts.settings.conflictsHelp')}>
                    <Alert tone="info" className="mb-3">{t('cts.settings.conflictsReadOnly')}</Alert>
                    {conflicts.length === 0 ? (
                        <p className="text-sm text-ink-muted">{t('cts.settings.noConflicts')}</p>
                    ) : (
                        <ul className="divide-y divide-line text-sm">
                            {conflicts.map((conflict) => (
                                <li key={conflict.id} className="py-3">
                                    <p className="font-medium text-ink">{t(`cts.conflict.${conflict.reason}` as never)}</p>
                                    <p className="text-[13px] text-ink-muted">
                                        {t(`cts.conflictType.${conflict.source.type}` as never)} #{conflict.source.id} {conflict.source.name ? `« ${conflict.source.name} »` : ''}
                                        {conflict.related && ` ↔ ${t(`cts.conflictType.${conflict.related.type}` as never)} #${conflict.related.id}${conflict.related.name ? ` « ${conflict.related.name} »` : ''}`}
                                    </p>
                                    <p className="text-[13px] text-ink-faint">
                                        {t('cts.settings.resolution')} : {t(`cts.resolution.${conflict.resolution}` as never)}
                                        {conflict.party_id && (
                                            <>
                                                {' · '}
                                                <Link href={`/contacts/parties/${conflict.party_id}`} className="text-accent underline">
                                                    {t('cts.open')}
                                                </Link>
                                            </>
                                        )}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            )}
        </ApplicationShell>
    );
}
