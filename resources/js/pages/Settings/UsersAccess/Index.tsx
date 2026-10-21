import { Button } from '@/components/ui/Button';
import { useFreshAuth } from '@/components/auth/FreshAuthProvider';
import { FormBanner, TextField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import type { SharedPageProps } from '@/types/app';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import RoleEditor from './RoleEditor';
import type { PermissionGroups, Preset, RoleSummary } from './RoleEditor';

type Member = {
    id: number;
    user: { id: number; name: string; email: string };
    role: { id: number; name: string; slug: string; is_system: boolean };
    status: 'active' | 'suspended';
    is_owner: boolean;
    store_memberships: Array<{ id: number; store: { id: number; name: string; code: string } }>;
    default_warehouse: { id: number; name: string; code: string; available: boolean } | null;
};

type Invitation = {
    id: number;
    email: string;
    role: { id: number; name: string };
    expires_at: string;
    created_at: string;
};

type StoreOption = { id: number; name: string; code: string };
type WarehouseOption = { id: number; name: string; code: string };

type Props = {
    organization: { id: number; name: string };
    memberships: Member[];
    roles: RoleSummary[];
    invitations: Invitation[];
    permissionGroups: PermissionGroups;
    presets: Preset[];
    stores: StoreOption[];
    warehouses: WarehouseOption[];
    can: {
        viewRoles: boolean;
        createRole: boolean;
        assignPermissions: boolean;
        manageMembers: boolean;
        updateMembers: boolean;
        deleteMembers: boolean;
        manageStoreAccess: boolean;
    };
};

export default function UsersAccessIndex({ organization, memberships, roles, invitations, permissionGroups, presets, stores, warehouses, can }: Props) {
    const { t } = useI18n();
    const { auth, flash } = usePage<SharedPageProps>().props;
    const selectableRoles = roles.filter((role) => role.slug !== 'owner');

    return (
        <ApplicationShell>
            <Head title={t('usersAccess.title')} />
            <PageHeader title={t('usersAccess.title')} description={t('usersAccess.help').replace('{organization}', organization.name)} />

            {flash?.invitationUrl && <InvitationUrlBanner url={flash.invitationUrl} />}

            {can.manageMembers && <AddMemberPanel organizationId={organization.id} roles={selectableRoles} />}

            <section className="mt-8 rounded-card border border-line bg-surface">
                <header className="border-b border-line px-5 py-3">
                    <h2 className="text-sm font-semibold text-ink">{t('usersAccess.members')} ({memberships.length})</h2>
                </header>

                {/* Desktop/tablet: table */}
                <div className="hidden overflow-x-auto md:block">
                    <table className="erp-table w-full text-start text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2.5">{t('usersAccess.name')}</th><th className="px-4 py-2.5">{t('usersAccess.email')}</th><th className="px-4 py-2.5">{t('usersAccess.role')}</th>
                                <th className="px-4 py-2.5">{t('common.status')}</th>{stores.length > 0 && <th className="px-4 py-2.5">{t('usersAccess.stores')}</th>}<th className="px-4 py-2.5">{t('usersAccess.defaultWarehouse')}</th>
                                <th className="px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {memberships.map((member) => (
                                <MemberRow
                                    key={member.id}
                                    member={member}
                                    roles={selectableRoles}
                                    stores={stores}
                                    warehouses={warehouses}
                                    isSelf={member.user.id === auth.user?.id}
                                    can={can}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Mobile: stacked cards */}
                <ul className="space-y-3 p-3 md:hidden">
                    {memberships.map((member) => (
                        <MemberCard
                            key={member.id}
                            member={member}
                            roles={selectableRoles}
                            stores={stores}
                            warehouses={warehouses}
                            isSelf={member.user.id === auth.user?.id}
                            can={can}
                        />
                    ))}
                </ul>
            </section>

            {can.manageMembers && (
                <section className="mt-8 rounded-card border border-line bg-surface">
                    <header className="border-b border-line px-5 py-3">
                        <h2 className="text-sm font-semibold text-ink">{t('usersAccess.pendingInvitations')} ({invitations.length})</h2>
                    </header>
                    {invitations.length === 0 ? (
                        <p className="px-5 py-4 text-sm text-ink-muted">{t('usersAccess.noInvitations')}</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="erp-table w-full text-start text-sm">
                                <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                    <tr>
                                        <th className="px-4 py-2.5">{t('usersAccess.email')}</th><th className="px-4 py-2.5">{t('usersAccess.role')}</th><th className="px-4 py-2.5">{t('usersAccess.expiresOn')}</th>
                                        <th className="px-4 py-2.5" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {invitations.map((invitation) => (
                                        <tr key={invitation.id} className="border-t border-line">
                                            <td className="px-4 py-2.5">{invitation.email}</td>
                                            <td className="px-4 py-2.5">{invitation.role.name}</td>
                                            <td className="px-4 py-2.5 text-ink-muted">{new Date(invitation.expires_at).toLocaleDateString()}</td>
                                            <td className="px-4 py-2.5 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        window.confirm(t('usersAccess.cancelInvitation').replace('{email}', invitation.email)) &&
                                                        router.delete(`/invitations/${invitation.id}`, { preserveScroll: true })
                                                    }
                                                    className="inline-flex min-h-9 items-center rounded-field px-2 text-xs text-danger hover:bg-danger-soft hover:underline"
                                                >
                                                    {t('common.cancel')}
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            )}

            {can.viewRoles && (
                <RolesSection roles={roles} permissionGroups={permissionGroups} presets={presets} can={can} />
            )}
        </ApplicationShell>
    );
}

function InvitationUrlBanner({ url }: { url: string }) {
    const { t } = useI18n();
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            /* clipboard unavailable */
        }
    };
    return (
        <div className="mb-6">
            <FormBanner tone="success">
                <p className="mb-1.5 font-medium">{t('usersAccess.invitationCreated')}</p><p className="mb-2 text-[13px]">{t('usersAccess.invitationFallback')}</p>
                <div className="flex flex-wrap items-center gap-2">
                    <code className="break-all rounded bg-surface px-2 py-1 text-[12px]">{url}</code>
                    <Button type="button" size="sm" variant="secondary" onClick={copy}>
                        {copied ? t('usersAccess.copied') : t('usersAccess.copyLink')}
                    </Button>
                </div>
            </FormBanner>
        </div>
    );
}

function AddMemberPanel({ organizationId, roles }: { organizationId: number; roles: RoleSummary[] }) {
    const { t } = useI18n();
    const { submit: submitProtected } = useFreshAuth();
    const [mode, setMode] = useState<'existing' | 'invite'>('existing');
    const [email, setEmail] = useState('');
    const [roleId, setRoleId] = useState<number | ''>(roles[0]?.id ?? '');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!roleId) return;
        setError(null);
        setProcessing(true);

        const url = mode === 'existing' ? `/organizations/${organizationId}/memberships` : `/organizations/${organizationId}/invitations`;

        void submitProtected({
            method: 'post', url, data: { email, role_id: roleId }, preserveScroll: true,
            onSuccess: () => setEmail(''),
            onError: (errors) => setError((Object.values(errors)[0] as string) ?? t('usersAccess.genericError')),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <section className="rounded-card border border-line bg-surface p-5">
            <div className="mb-4 flex items-center gap-2">
                <TabButton active={mode === 'existing'} onClick={() => setMode('existing')}>
                    {t('usersAccess.addExisting')}
                </TabButton>
                <TabButton active={mode === 'invite'} onClick={() => setMode('invite')}>
                    {t('usersAccess.inviteEmail')}
                </TabButton>
            </div>
            {error && (
                <div className="mb-3">
                    <FormBanner tone="danger">{error}</FormBanner>
                </div>
            )}
            <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                <TextField
                    label={t('usersAccess.email')}
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="nom@exemple.com"
                    className="w-full min-w-0 flex-1 sm:w-auto sm:min-w-64"
                />
                <label className="block">
                    <span className="mb-1.5 block text-[13px] font-medium text-ink">{t('usersAccess.role')}</span>
                    <select
                        value={roleId}
                        onChange={(e) => setRoleId(Number(e.target.value))}
                        required
                        className="control-select h-11"
                    >
                        {roles.map((role) => (
                            <option key={role.id} value={role.id}>
                                {role.name}
                            </option>
                        ))}
                    </select>
                </label>
                <Button type="submit" loading={processing} loadingText={t('profile.sending')}>
                    {mode === 'existing' ? t('usersAccess.add') : t('usersAccess.sendInvitation')}
                </Button>
            </form>
            {mode === 'invite' && (
                <p className="mt-2 text-[13px] text-ink-muted">
                    {t('usersAccess.invitationHelp')}
                </p>
            )}
        </section>
    );
}

function TabButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-field px-3 py-1.5 text-[13px] font-medium transition-soft ${
                active ? 'bg-sage text-ink' : 'text-ink-muted hover:bg-raised'
            }`}
        >
            {children}
        </button>
    );
}

/**
 * POS default warehouse — pre-selected when this member opens the POS. Not an
 * access restriction: the member can still pick another warehouse there. Owner
 * and self are editable too (it is a preference, not a privilege).
 */
function DefaultWarehouseField({
    member,
    warehouses,
    editable,
    fullWidth = false,
}: {
    member: Member;
    warehouses: WarehouseOption[];
    editable: boolean;
    fullWidth?: boolean;
}) {
    const { t } = useI18n();
    const { submit: submitProtected } = useFreshAuth();
    const current = member.default_warehouse;
    const unavailable = current !== null && !current.available;

    const change = (value: string) => {
        void submitProtected({
            method: 'patch', url: `/organization-memberships/${member.id}`,
            data: { default_warehouse_id: value === '' ? null : Number(value) }, preserveScroll: true,
        });
    };

    if (!editable) {
        return current ? (
            <span className={unavailable ? 'text-ink-faint' : 'text-ink'}>
                {current.name} · {current.code}
                {unavailable && <span className="ms-1.5 text-[11px] text-danger">({t('usersAccess.unavailable')})</span>}
            </span>
        ) : (
            <span className="text-ink-faint">—</span>
        );
    }

    return (
        <div className={fullWidth ? '' : 'min-w-40'}>
            <select
                value={current?.id ?? ''}
                onChange={(e) => change(e.target.value)}
                aria-label={t('usersAccess.defaultWarehouseFor').replace('{name}', member.user.name)}
                title={t('usersAccess.defaultWarehouseHelp')}
                className={`control-select text-[13px] ${fullWidth ? 'h-9 w-full' : 'py-1'}`}
            >
                <option value="">{t('usersAccess.posDefault')}</option>
                {unavailable && (
                    <option value={current.id} disabled>
                        {current.name} · {current.code} ({t('usersAccess.unavailable')})
                    </option>
                )}
                {warehouses.map((warehouse) => (
                    <option key={warehouse.id} value={warehouse.id}>
                        {warehouse.name} · {warehouse.code}
                    </option>
                ))}
            </select>
            {unavailable && (
                <p className="mt-1 text-[11px] text-danger">{t('usersAccess.inactiveWarehouse')}</p>
            )}
        </div>
    );
}

/** Shared state/handlers for a membership row, reused by both the desktop `<tr>` and the mobile `<li>` card. */
function useMemberRowActions(member: Member) {
    const { t } = useI18n();
    const { submit: submitProtected } = useFreshAuth();
    const [managingStores, setManagingStores] = useState(false);

    const changeRole = (roleId: number) => {
        void submitProtected({ method: 'patch', url: `/organization-memberships/${member.id}`, data: { role_id: roleId }, preserveScroll: true });
    };

    const toggleStatus = () => {
        const next = member.status === 'active' ? 'suspended' : 'active';
        void submitProtected({ method: 'patch', url: `/organization-memberships/${member.id}`, data: { status: next }, preserveScroll: true });
    };

    const remove = () => {
        if (!window.confirm(t('usersAccess.removeMemberConfirm').replace('{name}', member.user.name))) return;
        void submitProtected({ method: 'delete', url: `/organization-memberships/${member.id}`, preserveScroll: true });
    };

    const memberStoreIds = new Set(member.store_memberships.map((sm) => sm.store.id));
    const addStore = (storeId: number) => {
        router.post(`/stores/${storeId}/memberships`, { user_id: member.user.id }, { preserveScroll: true });
    };
    const removeStore = (storeMembershipId: number) => {
        router.delete(`/store-memberships/${storeMembershipId}`, { preserveScroll: true });
    };

    return { managingStores, setManagingStores, changeRole, toggleStatus, remove, memberStoreIds, addStore, removeStore };
}

function MemberRow({
    member,
    roles,
    stores,
    warehouses,
    isSelf,
    can,
}: {
    member: Member;
    roles: RoleSummary[];
    stores: StoreOption[];
    warehouses: WarehouseOption[];
    isSelf: boolean;
    can: Props['can'];
}) {
    const { t } = useI18n();
    const locked = member.is_owner || isSelf;
    const { managingStores, setManagingStores, changeRole, toggleStatus, remove, memberStoreIds, addStore, removeStore } =
        useMemberRowActions(member);

    return (
        <tr className="border-t border-line align-top">
            <td className="px-4 py-2.5 font-medium text-ink">{member.user.name}</td>
            <td className="px-4 py-2.5 text-ink-muted">{member.user.email}</td>
            <td className="px-4 py-2.5">
                {can.updateMembers && !locked ? (
                    <select
                        value={member.role.id}
                        onChange={(e) => changeRole(Number(e.target.value))}
                        className="control-select py-1 text-[13px]"
                    >
                        {roles.map((role) => (
                            <option key={role.id} value={role.id}>
                                {role.name}
                            </option>
                        ))}
                    </select>
                ) : (
                    <span>
                        {member.role.name}
                        {member.is_owner && <span className="ms-1.5 text-[11px] text-ink-faint">({t('usersAccess.owner')})</span>}
                    </span>
                )}
            </td>
            <td className="px-4 py-2.5">
                <StatusBadge status={member.status} />
            </td>
            {stores.length > 0 && (
                <td className="px-4 py-2.5">
                    <div className="flex flex-wrap items-center gap-1">
                        {member.store_memberships.map((sm) => (
                            <span key={sm.id} className="inline-flex items-center gap-1 rounded-full bg-raised px-2 py-0.5 text-[11px] text-ink-muted">
                                {sm.store.code}
                                {can.manageStoreAccess && (
                                    <button type="button" onClick={() => removeStore(sm.id)} className="text-ink-faint hover:text-danger" aria-label={t('usersAccess.removeStoreAccess').replace('{store}', sm.store.name)}>
                                        ×
                                    </button>
                                )}
                            </span>
                        ))}
                        {can.manageStoreAccess && (
                            <button type="button" onClick={() => setManagingStores((v) => !v)} className="text-[11px] text-ink-muted hover:underline">
                                {managingStores ? t('common.close') : t('usersAccess.addStore')}
                            </button>
                        )}
                    </div>
                    {managingStores && (
                        <div className="mt-1.5 flex flex-wrap gap-1">
                            {stores
                                .filter((store) => !memberStoreIds.has(store.id))
                                .map((store) => (
                                    <button
                                        key={store.id}
                                        type="button"
                                        onClick={() => addStore(store.id)}
                                        className="rounded-full border border-line-strong px-2 py-0.5 text-[11px] text-ink-muted hover:bg-raised"
                                    >
                                        + {store.code}
                                    </button>
                                ))}
                        </div>
                    )}
                </td>
            )}
            <td className="px-4 py-2.5">
                <DefaultWarehouseField member={member} warehouses={warehouses} editable={can.updateMembers} />
            </td>
            <td className="px-4 py-2.5 text-end">
                {!locked && (can.updateMembers || can.deleteMembers) && (
                    <div className="flex justify-end gap-1 rtl:justify-start">
                        {can.updateMembers && (
                            <button type="button" onClick={toggleStatus} className="inline-flex min-h-9 items-center rounded-field px-2 text-xs text-ink-muted hover:bg-raised hover:underline">
                                {member.status === 'active' ? t('usersAccess.suspend') : t('usersAccess.reactivate')}
                            </button>
                        )}
                        {can.deleteMembers && (
                            <button type="button" onClick={remove} className="inline-flex min-h-9 items-center rounded-field px-2 text-xs text-danger hover:bg-danger-soft hover:underline">
                                {t('usersAccess.remove')}
                            </button>
                        )}
                    </div>
                )}
            </td>
        </tr>
    );
}

/** Mobile card counterpart of `MemberRow` — same actions/state via `useMemberRowActions`, stacked layout. */
function MemberCard({
    member,
    roles,
    stores,
    warehouses,
    isSelf,
    can,
}: {
    member: Member;
    roles: RoleSummary[];
    stores: StoreOption[];
    warehouses: WarehouseOption[];
    isSelf: boolean;
    can: Props['can'];
}) {
    const { t } = useI18n();
    const locked = member.is_owner || isSelf;
    const { managingStores, setManagingStores, changeRole, toggleStatus, remove, memberStoreIds, addStore, removeStore } =
        useMemberRowActions(member);

    return (
        <li className="rounded-card border border-line bg-surface p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-ink">{member.user.name}</p>
                    <p className="truncate text-[13px] text-ink-muted">{member.user.email}</p>
                </div>
                <StatusBadge status={member.status} />
            </div>

            <div className="mt-3 text-[13px]">
                <p className="text-ink-faint">{t('usersAccess.role')}</p>
                {can.updateMembers && !locked ? (
                    <select
                        value={member.role.id}
                        onChange={(e) => changeRole(Number(e.target.value))}
                        className="control-select mt-1 h-9 w-full text-[13px]"
                    >
                        {roles.map((role) => (
                            <option key={role.id} value={role.id}>
                                {role.name}
                            </option>
                        ))}
                    </select>
                ) : (
                    <p className="text-ink">
                        {member.role.name}
                        {member.is_owner && <span className="ms-1.5 text-[11px] text-ink-faint">({t('usersAccess.owner')})</span>}
                    </p>
                )}
            </div>

            {stores.length > 0 && (
                <div className="mt-3 text-[13px]">
                    <p className="text-ink-faint">{t('usersAccess.stores')}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-1">
                        {member.store_memberships.map((sm) => (
                            <span key={sm.id} className="inline-flex items-center gap-1 rounded-full bg-raised px-2 py-0.5 text-[11px] text-ink-muted">
                                {sm.store.code}
                                {can.manageStoreAccess && (
                                    <button
                                        type="button"
                                        onClick={() => removeStore(sm.id)}
                                        className="text-ink-faint hover:text-danger"
                                        aria-label={t('usersAccess.removeStoreAccess').replace('{store}', sm.store.name)}
                                    >
                                        ×
                                    </button>
                                )}
                            </span>
                        ))}
                        {can.manageStoreAccess && (
                            <button
                                type="button"
                                onClick={() => setManagingStores((v) => !v)}
                                className="rounded-full px-2 py-0.5 text-[11px] text-ink-muted hover:bg-raised hover:underline"
                            >
                                {managingStores ? t('common.close') : t('usersAccess.addStore')}
                            </button>
                        )}
                    </div>
                    {managingStores && (
                        <div className="mt-1.5 flex flex-wrap gap-1">
                            {stores
                                .filter((store) => !memberStoreIds.has(store.id))
                                .map((store) => (
                                    <button
                                        key={store.id}
                                        type="button"
                                        onClick={() => addStore(store.id)}
                                        className="rounded-full border border-line-strong px-2 py-1 text-[11px] text-ink-muted hover:bg-raised"
                                    >
                                        + {store.code}
                                    </button>
                                ))}
                        </div>
                    )}
                </div>
            )}

            <div className="mt-3 text-[13px]">
                <p className="text-ink-faint">{t('usersAccess.defaultWarehouse')}</p>
                <div className="mt-1">
                    <DefaultWarehouseField member={member} warehouses={warehouses} editable={can.updateMembers} fullWidth />
                </div>
            </div>

            {!locked && (can.updateMembers || can.deleteMembers) && (
                <div className="mt-3 flex gap-2 border-t border-line pt-3">
                    {can.updateMembers && (
                        <button
                            type="button"
                            onClick={toggleStatus}
                            className="inline-flex min-h-9 flex-1 items-center justify-center rounded-field border border-line-strong px-3 text-[13px] text-ink-muted hover:bg-raised"
                        >
                            {member.status === 'active' ? t('usersAccess.suspend') : t('usersAccess.reactivate')}
                        </button>
                    )}
                    {can.deleteMembers && (
                        <button
                            type="button"
                            onClick={remove}
                            className="inline-flex min-h-9 flex-1 items-center justify-center rounded-field border border-danger/30 px-3 text-[13px] text-danger hover:bg-danger-soft"
                        >
                            {t('usersAccess.remove')}
                        </button>
                    )}
                </div>
            )}
        </li>
    );
}

function RolesSection({
    roles,
    permissionGroups,
    presets,
    can,
}: {
    roles: RoleSummary[];
    permissionGroups: PermissionGroups;
    presets: Preset[];
    can: Props['can'];
}) {
    const { tenant } = usePage<SharedPageProps>().props;
    const { t } = useI18n();
    const [editing, setEditing] = useState<RoleSummary | null | 'new'>(null);

    return (
        <section className="mt-8">
            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-sm font-semibold text-ink">{t('usersAccess.roles')} ({roles.length})</h2>
                {can.createRole && (
                    <Button size="sm" variant="secondary" onClick={() => setEditing('new')}>
                        {t('roles.new')}
                    </Button>
                )}
            </div>

            {editing !== null && (
                <div className="mb-5">
                    <RoleEditor
                        permissionGroups={permissionGroups}
                        presets={presets}
                        actorPermissions={tenant.permissions}
                        role={editing === 'new' ? null : editing}
                        canAssignPermissions={can.assignPermissions}
                        onClose={() => setEditing(null)}
                    />
                </div>
            )}

            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {roles.map((role) => (
                    <button
                        key={role.id}
                        type="button"
                        onClick={() => setEditing(role)}
                        className="rounded-card border border-line bg-surface p-4 text-start transition-soft hover:border-line-strong"
                    >
                        <div className="mb-1 flex items-center justify-between gap-2">
                            <span className="font-medium text-ink">{role.name}</span>
                            {role.is_system && <span className="rounded-full bg-raised px-2 py-0.5 text-[11px] text-ink-faint">{t('usersAccess.system')}</span>}
                        </div>
                        <p className="text-[13px] text-ink-muted">
                            {role.permissions.length} {t('usersAccess.permissionsCount')} · {role.members_count} {t('usersAccess.membersCount')}
                        </p>
                    </button>
                ))}
            </div>
        </section>
    );
}
