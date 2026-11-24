import { Button, ButtonLink } from '@/components/ui/Button';
import { SelectField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

type Props = { organization: { id: number; name: string }; enforcement: 'off' | 'warn' | 'block'; deductAdvances: boolean };

/** Protected destination of the global customer-credit policy (finance.customers.terms.manage, enforced server-side). */
export default function CustomerCreditPolicy({ organization, enforcement, deductAdvances }: Props) {
    const { t } = useI18n();
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors ?? {};
    const [mode, setMode] = useState(enforcement);
    const [deduct, setDeduct] = useState(deductAdvances);
    const save = (e: FormEvent) => {
        e.preventDefault();
        router.post('/finance/clients/credit/politique', { enforcement: mode, deduct_advances: deduct }, { preserveScroll: true });
    };
    return (
        <ApplicationShell>
            <Head title={t('p3.credit.settingsTitle')} />
            <PageHeader
                title={t('p3.credit.settingsTitle')}
                description={`${organization.name} — ${t('p3.credit.settingsDesc')}`}
                actions={<ButtonLink href="/finance/clients/credit" variant="outline" size="sm">{t('p3.credit.backToRisk')}</ButtonLink>}
            />
            <form onSubmit={save} className="surface-card max-w-2xl space-y-4 p-5" aria-label={t('fc4.policy.title')}>
                <SelectField label={t('fc4.policy.title')} value={mode} onChange={(e) => setMode(e.target.value as Props['enforcement'])} error={errors.enforcement}>
                    <option value="off">{t('fc4.policy.off')}</option>
                    <option value="warn">{t('fc4.policy.warn')}</option>
                    <option value="block">{t('fc4.policy.block')}</option>
                </SelectField>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={deduct} onChange={(e) => setDeduct(e.target.checked)} />
                    {t('fc6.credit.deductAdvances')}
                </label>
                <p className="text-[13px] text-ink-muted">{t('fc6.credit.deductHelp')}</p>
                <p className="text-[13px] text-ink-muted">{t('fc4.policy.help')}</p>
                <Button type="submit">{t('common.save')}</Button>
            </form>
        </ApplicationShell>
    );
}
