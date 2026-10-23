import { useFreshAuth } from '@/components/auth/FreshAuthProvider';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import PageHeader from '@/components/ui/PageHeader';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useMemo, useState } from 'react';

type Connection = { id: string; name: string; status: string; scopes: string[]; expires_at: string | null; last_used_at: string | null; created_at: string; owner: { name: string; email: string } | null; is_own: boolean };
type Scope = { key: string; label: string; available: boolean; permission: string };
type Props = { organization: { id: number; name: string }; enabled: boolean; canAdminister: boolean; connections: Connection[]; availableScopes: Scope[]; presets: Record<string, string[]> };

const scopeTranslationKeys: Record<string, TranslationKey> = {
    'products.view': 'mcp.scope.productsView', 'products.create': 'mcp.scope.productsCreate',
    'products.update': 'mcp.scope.productsUpdate', 'products.archive': 'mcp.scope.productsArchive',
    'product_cost.view': 'mcp.scope.costView', 'product_cost.manage': 'mcp.scope.costManage',
    'inventory.view': 'mcp.scope.inventoryView', 'brands.manage': 'mcp.scope.brandsManage',
    'categories.manage': 'mcp.scope.categoriesManage', 'units.manage': 'mcp.scope.unitsManage',
    'tax_rates.view': 'mcp.scope.taxesView',
};

export default function AiMcp({ organization, enabled, canAdminister, connections, availableScopes, presets }: Props) {
    const { t, locale } = useI18n();
    const { request } = useFreshAuth();
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Connection | null>(null);
    const [name, setName] = useState('');
    const [scopes, setScopes] = useState<string[]>(presets.read_only ?? []);
    const [expiresAt, setExpiresAt] = useState('');
    const [secret, setSecret] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const selectableScopes = useMemo(() => availableScopes.filter((scope) => scope.available), [availableScopes]);
    const refresh = () => router.reload({ only: ['enabled', 'connections', 'availableScopes'] });

    const togglePolicy = async () => {
        setBusy(true); setError('');
        try { await request({ method: 'put', url: '/settings/ai-mcp/policy', data: { enabled: !enabled } }); refresh(); }
        catch { setError(t('mcp.requestFailed')); }
        finally { setBusy(false); }
    };

    const save = async () => {
        if (busy) return;
        setBusy(true); setError('');
        try {
            if (editing) {
                await request({ method: 'put', url: `/settings/ai-mcp/connections/${editing.id}`, data: { name, scopes, expires_at: expiresAt || null } });
            } else {
                const response = await request<{ data: { secret: string } }>({ method: 'post', url: '/settings/ai-mcp/connections', data: { name, scopes, expires_at: expiresAt || null } });
                setSecret(response.data.data.secret);
            }
            setOpen(false); setEditing(null); setName(''); setExpiresAt(''); refresh();
        } catch (exception) {
            if (axios.isAxiosError(exception) && exception.response?.status === 422) setError(t('mcp.validationFailed'));
            else setError(t('mcp.requestFailed'));
        } finally { setBusy(false); }
    };

    const openCreate = () => { setEditing(null); setName(''); setScopes((presets.read_only ?? []).filter((key) => selectableScopes.some((scope) => scope.key === key))); setExpiresAt(''); setOpen(true); };
    const openEdit = (connection: Connection) => { setEditing(connection); setName(connection.name); setScopes(connection.scopes); setExpiresAt(connection.expires_at ? connection.expires_at.slice(0, 16) : ''); setOpen(true); };

    const rotate = async (connection: Connection) => {
        if (busy || !connection.is_own) return;
        setBusy(true); setError('');
        try {
            const response = await request<{ data: { secret: string } }>({ method: 'post', url: `/settings/ai-mcp/connections/${connection.id}/rotate` });
            setSecret(response.data.data.secret); refresh();
        } catch { setError(t('mcp.requestFailed')); }
        finally { setBusy(false); }
    };

    const revoke = async (connection: Connection) => {
        if (busy) return;
        setBusy(true); setError('');
        try { await request({ method: 'delete', url: `/settings/ai-mcp/connections/${connection.id}` }); refresh(); }
        catch { setError(t('mcp.requestFailed')); }
        finally { setBusy(false); }
    };

    return <ApplicationShell>
        <Head title={t('mcp.title')} />
        <div className="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6">
            <PageHeader title={t('mcp.title')} description={t('mcp.description')} />
            {error && <div className="rounded-field border border-danger/30 bg-danger-soft p-3 text-sm text-danger">{error}</div>}

            <section className="rounded-panel border border-line bg-surface p-5 shadow-soft">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div><h2 className="font-semibold text-ink">{t('mcp.policyTitle')}</h2><p className="mt-1 text-sm text-ink-muted">{t('mcp.policyDescription')}</p></div>
                    {canAdminister && <Button type="button" variant={enabled ? 'danger' : 'primary'} loading={busy} onClick={togglePolicy}>{enabled ? t('mcp.disable') : t('mcp.enable')}</Button>}
                </div>
                <p className={`mt-4 text-sm font-medium ${enabled ? 'text-success' : 'text-ink-muted'}`}>{enabled ? t('mcp.enabled') : t('mcp.disabled')}</p>
            </section>

            <section className="rounded-panel border border-line bg-surface shadow-soft">
                <div className="flex items-center justify-between gap-3 border-b border-line p-5"><div><h2 className="font-semibold text-ink">{t('mcp.connections')}</h2><p className="text-sm text-ink-muted">{organization.name}</p></div><Button type="button" disabled={!enabled} onClick={openCreate}>{t('mcp.create')}</Button></div>
                {connections.length === 0 ? <div className="p-8 text-center text-sm text-ink-muted">{t('mcp.empty')}</div> : <div className="divide-y divide-line">
                    {connections.map((connection) => <article key={connection.id} className="p-5">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><h3 className="font-semibold text-ink">{connection.name}</h3><span className={`rounded-full px-2 py-0.5 text-xs ${connection.status === 'active' ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'}`}>{connection.status === 'active' ? t('mcp.active') : t('mcp.revoked')}</span></div>
                            {connection.owner && <p className="mt-1 text-xs text-ink-muted">{connection.owner.name} · <bdi>{connection.owner.email}</bdi></p>}
                            <div className="mt-3 flex flex-wrap gap-1.5">{connection.scopes.map((scope) => <span key={scope} className="rounded-full border border-line bg-raised px-2 py-1 text-xs text-ink-muted"><bdi>{scope}</bdi></span>)}</div>
                            <p className="mt-3 text-xs text-ink-faint">{t('mcp.lastUsed')}: {connection.last_used_at ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(connection.last_used_at)) : t('mcp.never')}</p>
                        </div><div className="flex shrink-0 flex-wrap gap-2">{connection.is_own && connection.status === 'active' && <><Button type="button" variant="secondary" size="sm" disabled={busy} onClick={() => openEdit(connection)}>{t('common.edit')}</Button><Button type="button" variant="secondary" size="sm" disabled={busy} onClick={() => rotate(connection)}>{t('mcp.rotate')}</Button></>} {connection.status === 'active' && <Button type="button" variant="danger" size="sm" disabled={busy} onClick={() => revoke(connection)}>{t('mcp.revoke')}</Button>}</div></div>
                    </article>)}
                </div>}
            </section>
        </div>

        <Dialog open={open} onClose={() => !busy && setOpen(false)} title={editing ? t('mcp.edit') : t('mcp.create')} description={editing ? t('mcp.editHint') : t('mcp.secretHint')} dismissible={!busy} footer={<div className="flex justify-end gap-2"><Button variant="secondary" onClick={() => setOpen(false)} disabled={busy}>{t('common.cancel')}</Button><Button onClick={save} loading={busy} disabled={!name || scopes.length === 0}>{editing ? t('common.save') : t('mcp.create')}</Button></div>}>
            <div className="space-y-5 p-5"><label className="block text-sm font-medium text-ink">{t('mcp.name')}<input className="control-input mt-1 w-full" value={name} onChange={(event) => setName(event.target.value)} maxLength={100} /></label>
                <div><p className="text-sm font-medium text-ink">{t('mcp.preset')}</p><div className="mt-2 flex flex-wrap gap-2"><Button type="button" size="sm" variant="secondary" onClick={() => setScopes((presets.read_only ?? []).filter((key) => selectableScopes.some((scope) => scope.key === key)))}>{t('mcp.readOnly')}</Button><Button type="button" size="sm" variant="secondary" onClick={() => setScopes((presets.product_manager ?? []).filter((key) => selectableScopes.some((scope) => scope.key === key)))}>{t('mcp.productManager')}</Button></div></div>
                <fieldset><legend className="text-sm font-medium text-ink">{t('mcp.scopes')}</legend><div className="mt-2 grid gap-2 sm:grid-cols-2">{selectableScopes.map((scope) => <label key={scope.key} className="flex gap-2 rounded-field border border-line p-3 text-sm"><input type="checkbox" checked={scopes.includes(scope.key)} onChange={(event) => setScopes((current) => event.target.checked ? [...current, scope.key] : current.filter((key) => key !== scope.key))} /><span>{scopeTranslationKeys[scope.key] ? t(scopeTranslationKeys[scope.key]) : scope.label}<small className="block text-ink-faint"><bdi>{scope.key}</bdi></small></span></label>)}</div></fieldset>
                <label className="block text-sm font-medium text-ink">{t('mcp.expiration')}<input type="datetime-local" className="control-input mt-1 w-full" value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} /></label></div>
        </Dialog>

        <Dialog open={secret !== null} onClose={() => setSecret(null)} title={t('mcp.secretTitle')} description={t('mcp.secretWarning')} danger footer={<Button onClick={() => setSecret(null)}>{t('mcp.saved')}</Button>}><div className="p-5"><code className="block break-all rounded-field border border-warning/30 bg-warning-soft p-4 text-sm" dir="ltr">{secret}</code><Button type="button" variant="secondary" className="mt-3" onClick={() => secret && navigator.clipboard.writeText(secret)}>{t('mcp.copy')}</Button></div></Dialog>
    </ApplicationShell>;
}
