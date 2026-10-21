import { Button } from '@/components/ui/Button';
import { useFreshAuth } from '@/components/auth/FreshAuthProvider';
import { FormBanner, TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';

export type PermissionRow = { id: number; key: string; name: string };
export type PermissionGroups = Record<string, PermissionRow[]>;
export type Preset = { slug: string; name: string; permissions: string[] };
export type RoleSummary = {
    id: number;
    name: string;
    slug: string;
    is_system: boolean;
    preset_slug: string | null;
    members_count: number;
    permissions: string[];
};

type Props = {
    permissionGroups: PermissionGroups;
    presets: Preset[];
    actorPermissions: string[];
    /** null = create mode */
    role: RoleSummary | null;
    canAssignPermissions: boolean;
    onClose: () => void;
};

export default function RoleEditor({ permissionGroups, presets, actorPermissions, role, canAssignPermissions, onClose }: Props) {
    const { t } = useI18n();
    const { submit: submitProtected } = useFreshAuth();
    const groupLabels: Record<string, string> = {
        organizations: t('roles.group.organizations'), settings: t('roles.group.settings'), stores: t('roles.group.stores'), members: t('roles.group.members'),
        'store-memberships': t('roles.group.storeMemberships'), roles: t('roles.group.roles'), catalog: t('roles.group.catalog'), products: t('roles.group.products'),
        product_cost: t('roles.group.productCost'), categories: t('roles.group.categories'), brands: t('roles.group.brands'), units: t('roles.group.units'),
        tax_rates: t('roles.group.taxRates'), warehouses: t('roles.group.warehouses'), inventory: t('roles.group.inventory'), suppliers: t('roles.group.suppliers'),
        procurement: t('roles.group.procurement'), customers: t('roles.group.customers'), sales_orders: t('roles.group.salesOrders'), pos: t('roles.group.pos'),
        financial_accounts: t('roles.group.financialAccounts'), payments: t('roles.group.payments'), invoices: t('roles.group.invoices'), quotations: t('roles.group.quotations'),
        non_stock_items: t('roles.group.nonStockItems'), delivery_notes: t('roles.group.deliveryNotes'), integrations: t('roles.group.integrations'), finance: t('roles.group.finance'), commissions: t('roles.group.commissions'),
    };
    const isCreate = role === null;
    const [name, setName] = useState(role?.name ?? '');
    const [presetSlug, setPresetSlug] = useState<string | null>(role?.preset_slug ?? null);
    const [checked, setChecked] = useState<Set<string>>(new Set(role?.permissions ?? []));
    const [touchedPreset, setTouchedPreset] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const actorSet = useMemo(() => new Set(actorPermissions), [actorPermissions]);
    // Permissions the role already carries that the current admin does not
    // hold themselves. RolePermissionService::sync rejects a permission sync
    // whenever the submitted set contains anything outside the actor's own
    // grant — so as long as these remain untouched, saving the matrix must
    // stay blocked (matrix is view-only for this role) rather than silently
    // failing on submit or silently stripping a permission the admin can't see.
    const lockedPermissions = useMemo(
        () => (role ? role.permissions.filter((key) => !actorSet.has(key)) : []),
        [role, actorSet],
    );
    const matrixLocked = isCreate ? !canAssignPermissions : role!.is_system || !canAssignPermissions || lockedPermissions.length > 0;

    const applyPreset = (slug: string | null) => {
        setPresetSlug(slug);
        setTouchedPreset(true);
        if (matrixLocked) return; // no roles.assign-permissions: label only, no permissions to grant
        if (!slug) {
            setChecked(new Set());
            return;
        }
        const preset = presets.find((p) => p.slug === slug);
        const next = new Set((preset?.permissions ?? []).filter((key) => actorSet.has(key)));
        setChecked(next);
    };

    const toggle = (key: string) => {
        if (matrixLocked || !actorSet.has(key)) return;
        setChecked((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });
    };

    const permissionIdsFor = (keys: Set<string>) => {
        const ids: number[] = [];
        Object.values(permissionGroups).forEach((rows) => {
            rows.forEach((row) => {
                if (keys.has(row.key)) ids.push(row.id);
            });
        });
        return ids;
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setError(null);
        setProcessing(true);

        if (isCreate) {
            void submitProtected({
                method: 'post',
                url: '/roles',
                data: {
                    name,
                    preset_slug: presetSlug,
                    permission_ids: permissionIdsFor(checked),
                },
                preserveScroll: true,
                onSuccess: onClose,
                onError: (errors) => setError(Object.values(errors)[0] as string),
                onFinish: () => setProcessing(false),
            });
            return;
        }

        const nameChanged = name !== role!.name;

        if (nameChanged) {
            router.patch(
                `/roles/${role!.id}`,
                { name, slug: role!.slug },
                { preserveScroll: true, onError: (errors) => setError(Object.values(errors)[0] as string) },
            );
        }

        if (!matrixLocked) {
            void submitProtected({
                method: 'put',
                url: `/roles/${role!.id}/permissions`,
                data: { permission_ids: permissionIdsFor(checked) },
                preserveScroll: true,
                onSuccess: onClose,
                onError: (errors) => setError(Object.values(errors)[0] as string),
                onFinish: () => setProcessing(false),
            });
        } else {
            setProcessing(false);
            if (nameChanged) onClose();
        }
    };

    const canDelete = !isCreate && !role!.is_system && role!.members_count === 0;

    const destroy = () => {
        if (!role) return;
        if (!window.confirm(t('roles.deleteConfirm').replace('{name}', role.name))) return;
        void submitProtected({ method: 'delete', url: `/roles/${role.id}`, preserveScroll: true, onSuccess: onClose });
    };

    return (
        <div className="rounded-card border border-line bg-surface p-5">
            <div className="mb-4 flex items-start justify-between gap-3">
                <h3 className="text-sm font-semibold text-ink">{isCreate ? t('roles.new') : role!.name}</h3>
                <button type="button" onClick={onClose} className="text-xs text-ink-muted hover:text-ink">
                    {t('common.close')}
                </button>
            </div>

            {role?.is_system && (
                <FormBanner tone="info">
                    {t('roles.systemProtected')}
                </FormBanner>
            )}

            {!role?.is_system && lockedPermissions.length > 0 && (
                <div className="mb-3">
                    <FormBanner tone="info">
                        {t('roles.lockedPermissions').replace('{count}', String(lockedPermissions.length))}
                    </FormBanner>
                </div>
            )}

            {error && (
                <div className="mb-3">
                    <FormBanner tone="danger">{error}</FormBanner>
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                <TextField
                    label={t('roles.name')}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    required
                    disabled={role?.is_system}
                    maxLength={255}
                />

                {isCreate && (
                    <div>
                        <span className="mb-2 block text-[13px] font-medium text-ink">{t('roles.preset')}</span>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {[...presets, { slug: '', name: t('roles.custom'), permissions: [] }].map((preset) => {
                                const value = preset.slug || null;
                                const active = touchedPreset ? presetSlug === value : value === null && !touchedPreset;
                                return (
                                    <button
                                        type="button"
                                        key={preset.slug || 'custom'}
                                        onClick={() => applyPreset(value)}
                                        className={`rounded-field border px-3 py-2.5 text-start text-[13px] font-medium transition-soft ${
                                            active ? 'border-primary bg-sage text-ink' : 'border-line-strong text-ink-muted hover:bg-raised'
                                        }`}
                                    >
                                        {preset.name}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

                <div>
                    <span className="mb-2 block text-[13px] font-medium text-ink">
                        {t('roles.permissions')} {matrixLocked && !role?.is_system ? `(${t('roles.readOnly')})` : ''}
                    </span>
                    <div className="max-h-96 space-y-4 overflow-y-auto rounded-field border border-line p-3">
                        {Object.entries(permissionGroups).map(([group, rows]) => (
                            <div key={group}>
                                <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-faint">
                                    {groupLabels[group] ?? group.replace(/_/g, ' ')}
                                </p>
                                <div className="grid gap-1 sm:grid-cols-2">
                                    {rows.map((row) => {
                                        const isChecked = checked.has(row.key);
                                        const available = actorSet.has(row.key);
                                        const disabled = matrixLocked || (!available && !isChecked);
                                        return (
                                            <label
                                                key={row.key}
                                                title={!available ? t('roles.permissionUnavailable') : undefined}
                                                className={`flex items-center gap-2 rounded px-1.5 py-1 text-[13px] ${
                                                    disabled ? 'text-ink-faint' : 'text-ink-muted hover:bg-raised'
                                                }`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={isChecked}
                                                    disabled={disabled}
                                                    onChange={() => toggle(row.key)}
                                                    className="size-4 rounded border-line-strong accent-primary"
                                                />
                                                {row.name}
                                            </label>
                                        );
                                    })}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex items-center justify-between gap-2">
                    <div>
                        {canDelete && (
                            <Button type="button" variant="danger" size="sm" onClick={destroy}>
                                {t('roles.delete')}
                            </Button>
                        )}
                    </div>
                    {!role?.is_system && (
                        <Button type="submit" loading={processing} loadingText={t('common.saving')}>
                            {t('common.save')}
                        </Button>
                    )}
                </div>
            </form>
        </div>
    );
}
