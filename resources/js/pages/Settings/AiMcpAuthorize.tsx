import AuthLayout from '@/components/auth/AuthLayout';
import { useFreshAuth } from '@/components/auth/FreshAuthProvider';
import { Button } from '@/components/ui/Button';
import { useI18n } from '@/i18n';
import { mcpScopeTranslationKeys } from '@/utils/mcpScopes';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useMemo, useState } from 'react';

type ScopeOption = { key: string; label: string; grantable: boolean; requested: boolean };
type ExistingConnection = { id: string; name: string; scopes: string[] };
type OrganizationOption = { id: number; name: string; mcp_enabled: boolean; scopes: ScopeOption[]; connections: ExistingConnection[] };
type Props = {
    error: string | null;
    requestId?: string;
    client?: { id: string; name: string; client_uri: string | null; redirect: string };
    requestedScopes?: string[];
    defaultScopes?: string[];
    organizations?: OrganizationOption[];
};

/**
 * OAuth consent for a remote MCP client. The user picks one of their own
 * organisations, a new or existing connection for this client, and only the
 * scopes they currently hold (and the client requested).
 */
export default function AiMcpAuthorize({ error, requestId, client, requestedScopes = [], defaultScopes = [], organizations = [] }: Props) {
    const { t } = useI18n();
    const { request } = useFreshAuth();
    const enabledOrganizations = organizations.filter((organization) => organization.mcp_enabled);
    const [organizationId, setOrganizationId] = useState<number | null>(enabledOrganizations[0]?.id ?? null);
    const organization = useMemo(() => organizations.find((item) => item.id === organizationId) ?? null, [organizations, organizationId]);
    const initialScopes = (option: OrganizationOption | null) => (option?.scopes ?? [])
        .filter((scope) => scope.grantable && (requestedScopes.length > 0 ? scope.requested : defaultScopes.includes(scope.key)))
        .map((scope) => scope.key);
    const [scopes, setScopes] = useState<string[]>(() => initialScopes(organization));
    const [connectionId, setConnectionId] = useState('');
    const [name, setName] = useState(client?.name ?? '');
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState('');

    const label = (scope: ScopeOption) => (mcpScopeTranslationKeys[scope.key] ? t(mcpScopeTranslationKeys[scope.key]) : scope.label);

    const chooseOrganization = (id: number) => {
        const next = organizations.find((item) => item.id === id) ?? null;
        setOrganizationId(id); setConnectionId(''); setScopes(initialScopes(next));
    };

    const finish = async (action: 'approve' | 'deny') => {
        if (busy || !requestId) return;
        setBusy(true); setFailure('');
        try {
            const response = await request<{ redirect_to: string }>({
                method: 'post',
                url: `/oauth/authorize/${requestId}/${action}`,
                data: action === 'approve' ? { organization_id: organizationId, connection_id: connectionId || null, connection_name: name || null, scopes } : {},
            });
            window.location.assign(response.data.redirect_to);
        } catch (exception) {
            setBusy(false);
            setFailure(axios.isAxiosError(exception) && exception.response?.status === 410 ? t('mcp.oauth.expired') : t('mcp.requestFailed'));
        }
    };

    if (error || !client || !requestId) {
        return <>
            <Head title={t('mcp.oauth.title')} />
            <AuthLayout title={t('mcp.oauth.title')} subtitle={t('mcp.oauth.invalidRequest')}>
                <div className="rounded-field border border-danger/30 bg-danger-soft p-3 text-sm text-danger">{error ?? t('mcp.oauth.invalidRequest')}</div>
            </AuthLayout>
        </>;
    }

    return <>
        <Head title={t('mcp.oauth.title')} />
        <AuthLayout title={t('mcp.oauth.title')} subtitle={t('mcp.oauth.subtitle')}>
            <div className="space-y-5">
                <div className="rounded-field border border-line bg-raised p-4 text-sm">
                    <p className="font-semibold text-ink"><bdi>{client.name}</bdi></p>
                    <p className="mt-1 text-xs text-ink-muted">{t('mcp.oauth.redirectsTo')} <bdi dir="ltr">{client.redirect}</bdi></p>
                    <p className="mt-2 text-xs text-warning">{t('mcp.oauth.selfDeclared')}</p>
                </div>

                {failure && <div className="rounded-field border border-danger/30 bg-danger-soft p-3 text-sm text-danger">{failure}</div>}

                {enabledOrganizations.length === 0 ? <p className="text-sm text-ink-muted">{t('mcp.oauth.noOrganization')}</p> : <>
                    <label className="block text-sm font-medium text-ink">{t('common.organization')}
                        <select className="control-input mt-1 w-full" value={organizationId ?? ''} onChange={(event) => chooseOrganization(Number(event.target.value))}>
                            {enabledOrganizations.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
                        </select>
                    </label>

                    <label className="block text-sm font-medium text-ink">{t('mcp.oauth.connection')}
                        <select className="control-input mt-1 w-full" value={connectionId} onChange={(event) => setConnectionId(event.target.value)}>
                            <option value="">{t('mcp.oauth.newConnection')}</option>
                            {(organization?.connections ?? []).map((connection) => <option key={connection.id} value={connection.id}>{connection.name}</option>)}
                        </select>
                    </label>
                    <label className="block text-sm font-medium text-ink">{t('mcp.name')}
                        <input className="control-input mt-1 w-full" maxLength={100} value={name} onChange={(event) => setName(event.target.value)} />
                    </label>

                    <fieldset>
                        <legend className="text-sm font-medium text-ink">{t('mcp.oauth.requestedScopes')}</legend>
                        <div className="mt-2 grid gap-2">
                            {(organization?.scopes ?? []).filter((scope) => scope.requested).map((scope) => <label key={scope.key} className={`flex gap-2 rounded-field border border-line p-3 text-sm ${scope.grantable ? '' : 'opacity-60'}`}>
                                <input type="checkbox" disabled={!scope.grantable} checked={scopes.includes(scope.key)} onChange={(event) => setScopes((current) => event.target.checked ? [...current, scope.key] : current.filter((key) => key !== scope.key))} />
                                <span>{label(scope)}<small className="block text-ink-faint"><bdi>{scope.key}</bdi>{!scope.grantable && <> · {t('mcp.oauth.notHeld')}</>}</small></span>
                            </label>)}
                        </div>
                    </fieldset>
                    <p className="text-xs text-ink-muted">{t('mcp.oauth.effectiveHint')}</p>
                </>}

                <div className="flex flex-col gap-2 sm:flex-row-reverse">
                    <Button type="button" block loading={busy} disabled={!organizationId || scopes.length === 0} onClick={() => finish('approve')}>{t('mcp.oauth.approve')}</Button>
                    <Button type="button" block variant="secondary" disabled={busy} onClick={() => finish('deny')}>{t('mcp.oauth.deny')}</Button>
                </div>
            </div>
        </AuthLayout>
    </>;
}
