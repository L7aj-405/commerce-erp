import { Button } from '@/components/ui/Button';
import { FormBanner, TextField } from '@/components/ui/form';
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

const GROUP_LABELS: Record<string, string> = {
    organizations: 'Organisation',
    settings: 'Paramètres',
    stores: 'Magasins',
    members: 'Membres',
    'store-memberships': 'Accès magasins',
    roles: 'Rôles',
    catalog: 'Catalogue',
    products: 'Produits',
    categories: 'Catégories',
    brands: 'Marques',
    units: 'Unités',
    tax_rates: 'Taxes (TVA)',
    warehouses: 'Entrepôts',
    inventory: 'Stock',
    suppliers: 'Fournisseurs',
    procurement: 'Achats',
    customers: 'Clients',
    sales_orders: 'Ventes',
    pos: 'Point de vente',
    financial_accounts: 'Comptes financiers',
    payments: 'Paiements',
    invoices: 'Factures',
    quotations: 'Devis',
    non_stock_items: 'Articles hors stock',
    delivery_notes: 'Bons de livraison',
    integrations: 'Intégrations',
    finance: 'Finance',
};

function groupLabel(key: string) {
    return GROUP_LABELS[key] ?? key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

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
            router.post(
                '/roles',
                {
                    name,
                    preset_slug: presetSlug,
                    permission_ids: permissionIdsFor(checked),
                },
                {
                    preserveScroll: true,
                    onSuccess: () => onClose(),
                    onError: (errors) => setError(Object.values(errors)[0] as string),
                    onFinish: () => setProcessing(false),
                },
            );
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
            router.put(
                `/roles/${role!.id}/permissions`,
                { permission_ids: permissionIdsFor(checked) },
                {
                    preserveScroll: true,
                    onSuccess: () => onClose(),
                    onError: (errors) => setError(Object.values(errors)[0] as string),
                    onFinish: () => setProcessing(false),
                },
            );
        } else {
            setProcessing(false);
            if (nameChanged) onClose();
        }
    };

    const canDelete = !isCreate && !role!.is_system && role!.members_count === 0;

    const destroy = () => {
        if (!role) return;
        if (!window.confirm(`Supprimer le rôle « ${role.name} » ? Cette action est irréversible.`)) return;
        router.delete(`/roles/${role.id}`, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <div className="rounded-card border border-line bg-surface p-5">
            <div className="mb-4 flex items-start justify-between gap-3">
                <h3 className="text-sm font-semibold text-ink">{isCreate ? 'Nouveau rôle' : role!.name}</h3>
                <button type="button" onClick={onClose} className="text-xs text-ink-muted hover:text-ink">
                    Fermer
                </button>
            </div>

            {role?.is_system && (
                <FormBanner tone="info">
                    Rôle système protégé — son nom et ses permissions ne peuvent pas être modifiés. Créez un rôle personnalisé pour
                    adapter les accès.
                </FormBanner>
            )}

            {!role?.is_system && lockedPermissions.length > 0 && (
                <div className="mb-3">
                    <FormBanner tone="info">
                        Ce rôle inclut des permissions que vous ne détenez pas vous-même ({lockedPermissions.length}), donc vous ne
                        pouvez pas modifier sa matrice de permissions ici. Seul un titulaire de ces permissions peut l’ajuster.
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
                    label="Nom du rôle"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    required
                    disabled={role?.is_system}
                    maxLength={255}
                />

                {isCreate && (
                    <div>
                        <span className="mb-2 block text-[13px] font-medium text-ink">Préréglage</span>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {[...presets, { slug: '', name: 'Personnalisé', permissions: [] }].map((preset) => {
                                const value = preset.slug || null;
                                const active = touchedPreset ? presetSlug === value : value === null && !touchedPreset;
                                return (
                                    <button
                                        type="button"
                                        key={preset.slug || 'custom'}
                                        onClick={() => applyPreset(value)}
                                        className={`rounded-field border px-3 py-2.5 text-left text-[13px] font-medium transition-soft ${
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
                        Permissions {matrixLocked && !role?.is_system ? '(lecture seule)' : ''}
                    </span>
                    <div className="max-h-96 space-y-4 overflow-y-auto rounded-field border border-line p-3">
                        {Object.entries(permissionGroups).map(([group, rows]) => (
                            <div key={group}>
                                <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-faint">
                                    {groupLabel(group)}
                                </p>
                                <div className="grid gap-1 sm:grid-cols-2">
                                    {rows.map((row) => {
                                        const isChecked = checked.has(row.key);
                                        const available = actorSet.has(row.key);
                                        const disabled = matrixLocked || (!available && !isChecked);
                                        return (
                                            <label
                                                key={row.key}
                                                title={!available ? 'Vous ne détenez pas cette permission' : undefined}
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
                                Supprimer le rôle
                            </Button>
                        )}
                    </div>
                    {!role?.is_system && (
                        <Button type="submit" loading={processing} loadingText="Enregistrement…">
                            Enregistrer
                        </Button>
                    )}
                </div>
            </form>
        </div>
    );
}
