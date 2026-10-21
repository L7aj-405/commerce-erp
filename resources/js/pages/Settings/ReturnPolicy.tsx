import { Button } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import SalesLayout from '@/layouts/SalesLayout';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Policy = { enabled?: boolean; window_value?: number; window_unit?: 'hours'|'days'; allow_partial?: boolean; allow_full?: boolean; manager_override_allowed?: boolean; require_reason?: boolean; default_disposition?: 'restock'|'damaged'; source?: string };
type Props = { store: {id:number;name:string}|null; organizationPolicy: Policy; storePolicy: (Policy & {inherit?:boolean})|null; resolved: Policy|null; canUpdate:boolean };

export default function ReturnPolicy({ store, organizationPolicy, storePolicy, resolved, canUpdate }: Props) {
    const { t } = useI18n();
    const selected = store && storePolicy && !storePolicy.inherit ? storePolicy : organizationPolicy;
    const form = useForm({ scope: store ? 'store' : 'organization', inherit: Boolean(store && (!storePolicy || storePolicy.inherit)), enabled: selected.enabled ?? false, window_value: selected.window_value ?? 7, window_unit: selected.window_unit ?? 'days', allow_partial: selected.allow_partial ?? true, allow_full: selected.allow_full ?? true, manager_override_allowed: selected.manager_override_allowed ?? false, require_reason: selected.require_reason ?? true, default_disposition: selected.default_disposition ?? 'restock' });
    const inheriting = form.data.scope === 'store' && form.data.inherit;
    const changeScope = (scope: string) => {
        const inherit = scope === 'store' && Boolean(!storePolicy || storePolicy.inherit);
        const policy = scope === 'store' && storePolicy && !storePolicy.inherit ? storePolicy : organizationPolicy;
        form.setData({ ...form.data, scope, inherit, enabled: policy.enabled ?? false, window_value: policy.window_value ?? 7, window_unit: policy.window_unit ?? 'days', allow_partial: policy.allow_partial ?? true, allow_full: policy.allow_full ?? true, manager_override_allowed: policy.manager_override_allowed ?? false, require_reason: policy.require_reason ?? true, default_disposition: policy.default_disposition ?? 'restock' });
    };
    const submit = (event: FormEvent) => { event.preventDefault(); form.put('/return-policy', { preserveScroll: true }); };
    return <SalesLayout><Head title={t('returns.settingsTitle')}/><div className="mx-auto max-w-2xl"><PageHeader title={t('returns.policyTitle')} description={t('returns.settingsHelp')}/>
        <form onSubmit={submit} className="space-y-5 rounded-card border border-line bg-surface p-6"><fieldset disabled={!canUpdate} className="space-y-5">
            {store && <><label className="block text-sm font-medium">{t('returns.scope')}<select value={form.data.scope} onChange={e=>changeScope(e.target.value)} className={input}><option value="organization">{t('returns.organizationDefault')}</option><option value="store">{t('returns.storeOverride')} {store.name}</option></select></label>{form.data.scope==='store'&&<Check label={t('returns.inherit')} checked={form.data.inherit} onChange={v=>form.setData('inherit',v)}/>}</>}
            {!inheriting&&<><h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">{t('returns.policy')}</h2><Check label={t('returns.allow')} checked={form.data.enabled} onChange={v=>form.setData('enabled',v)}/>
            <label className="block text-sm font-medium">{t('returns.window')}<div className="mt-1 grid grid-cols-2 gap-2"><input type="number" min={0} max={365} value={form.data.window_value} onChange={e=>form.setData('window_value',Number(e.target.value))} className={input}/><select value={form.data.window_unit} onChange={e=>form.setData('window_unit',e.target.value as 'hours'|'days')} className={input}><option value="hours">{t('returns.hours')}</option><option value="days">{t('returns.days')}</option></select></div></label>
            <Check label={t('returns.allowPartial')} checked={form.data.allow_partial} onChange={v=>form.setData('allow_partial',v)}/><Check label={t('returns.allowFull')} checked={form.data.allow_full} onChange={v=>form.setData('allow_full',v)}/><Check label={t('returns.managerOverride')} checked={form.data.manager_override_allowed} onChange={v=>form.setData('manager_override_allowed',v)}/><Check label={t('returns.reasonRequired')} checked={form.data.require_reason} onChange={v=>form.setData('require_reason',v)}/>
            <label className="block text-sm font-medium">{t('returns.defaultDisposition')}<select value={form.data.default_disposition} onChange={e=>form.setData('default_disposition',e.target.value as 'restock'|'damaged')} className={input}><option value="restock">{t('returns.restock')}</option><option value="damaged">{t('returns.damaged')}</option></select></label></>}
            {resolved&&<p className="rounded-field bg-raised px-3 py-2 text-xs text-ink-muted">{t('returns.effectivePolicy')} : {resolved.source==='store'?t('returns.store'):t('returns.organization')} · {resolved.window_value} {resolved.window_unit==='hours'?t('returns.hours'):t('returns.days')}.</p>}
            {Object.values(form.errors).map(error=>error&&<p key={error} className="text-sm text-danger">{error}</p>)}<Button type="submit" loading={form.processing}>{t('common.save')}</Button>
        </fieldset></form></div></SalesLayout>;
}
const input='mt-1 w-full rounded-field border border-line-strong px-3 py-2 text-sm';
function Check({label,checked,onChange}:{label:string;checked:boolean;onChange:(v:boolean)=>void}){return <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={checked} onChange={e=>onChange(e.target.checked)}/>{label}</label>}
