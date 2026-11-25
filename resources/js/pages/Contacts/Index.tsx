import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import { useConfirm } from '@/components/ui/ConfirmDialog';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { Badge } from '@/components/ui/StatusBadge';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import RoleBadges, { useRoleLabel } from '@/components/contacts/RoleBadges';
import type { Catalogue, ContactCan, Role } from '@/components/contacts/types';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link, router } from '@inertiajs/react';

type Row = {
    id: number;
    display_name: string;
    legal_name: string | null;
    kind: 'person' | 'organization';
    status: 'active' | 'archived';
    phone: string | null;
    email: string | null;
    roles: Role[];
    customer_id: number | null;
    supplier_id: number | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Filters = { search?: string; kind?: string; role?: string; roles?: string[]; role_ids?: number[]; status?: string; scope?: string; group?: string };
type Props = {
    parties: { data: Row[]; links: PageLink[]; from?: number | null; to?: number | null; total?: number };
    filters: Filters;
    catalogue: Catalogue;
    can: ContactCan;
};

/** The active navigation entry (a query-string view of ONE directory) decides the title and the creation preset. */
function viewOf(filters: Filters): { title: 'all' | 'clients' | 'suppliers' | 'internal' | 'external' | 'partners'; preset: string[] } {
    const only = (key: string) => (filters.role === key && !filters.roles?.length && !filters.role_ids?.length) || (!filters.role && filters.roles?.length === 1 && filters.roles[0] === key && !filters.role_ids?.length);
    if (only('client')) return { title: 'clients', preset: ['client'] };
    if (only('supplier')) return { title: 'suppliers', preset: ['supplier'] };
    if (only('internal')) return { title: 'internal', preset: ['internal'] };
    if (filters.scope === 'external') return { title: 'external', preset: [] };
    if (filters.group === 'partners') return { title: 'partners', preset: ['partner'] };

    return { title: 'all', preset: [] };
}

export default function ContactsIndex({ parties, filters, catalogue, can }: Props) {
    const { t } = useI18n();
    const confirm = useConfirm();
    const roleLabel = useRoleLabel();
    const f = useQueryFilters('/contacts', filters as Record<string, string | string[] | undefined>, {
        keys: ['search', 'kind', 'role', 'roles', 'role_ids', 'status', 'scope', 'group'],
    });
    const view = viewOf(filters);
    const title = t(`cts.view.${view.title}` as never);

    const roleOptions = catalogue.roles.map((role) => ({ value: role.system_key ?? `#${role.id}`, label: roleLabel(role) }));
    const selectedRoles = [...(filters.role ? [filters.role] : []), ...(filters.roles ?? []), ...(filters.role_ids ?? []).map((id) => `#${id}`)];
    const setRoles = (values: string[]) =>
        f.patch({
            role: undefined,
            roles: values.filter((v) => !v.startsWith('#')),
            role_ids: values.filter((v) => v.startsWith('#')).map((v) => v.slice(1)),
        } as never);
    const kindOptions = [
        { value: 'person', label: t('cts.kind.person') },
        { value: 'organization', label: t('cts.kind.organization') },
    ];
    const statusOptions = [
        { value: 'active', label: t('cts.status.active') },
        { value: 'archived', label: t('cts.status.archived') },
        { value: 'all', label: t('cts.status.all') },
    ];
    const scopeOptions = [
        { value: 'internal', label: t('cts.scope.internal') },
        { value: 'external', label: t('cts.scope.external') },
    ];

    const chips: FilterChip[] = [];
    if (filters.search) chips.push({ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') });
    if (filters.kind) chips.push({ key: 'kind', label: t('cts.nature'), value: kindOptions.find((o) => o.value === filters.kind)?.label ?? filters.kind, onRemove: () => f.remove('kind') });
    selectedRoles.forEach((value) =>
        chips.push({ key: `role:${value}`, label: t('cts.roles'), value: roleOptions.find((o) => o.value === value)?.label ?? value, onRemove: () => setRoles(selectedRoles.filter((v) => v !== value)) }),
    );
    if (filters.scope) chips.push({ key: 'scope', label: t('cts.relationship'), value: scopeOptions.find((o) => o.value === filters.scope)?.label ?? filters.scope, onRemove: () => f.remove('scope') });
    if (filters.group) chips.push({ key: 'group', label: t('cts.relationship'), value: t('cts.view.partners'), onRemove: () => f.remove('group') });
    if (filters.status && filters.status !== 'active') chips.push({ key: 'status', label: t('common.status'), value: statusOptions.find((o) => o.value === filters.status)?.label ?? filters.status, onRemove: () => f.remove('status') });
    const filtered = chips.length > 0;

    const createHref = `/contacts/create${view.preset.length ? `?${view.preset.map((r) => `role%5B%5D=${r}`).join('&')}` : ''}`;
    const createAction = can.create ? (
        <ButtonLink href={createHref}>
            + {t(view.title === 'clients' ? 'cts.newClient' : view.title === 'suppliers' ? 'cts.newSupplier' : view.title === 'internal' ? 'cts.newInternal' : 'cts.new')}
        </ButtonLink>
    ) : undefined;
    const emptyTitle = filtered ? t('filters.noMatch') : t('cts.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('cts.emptyHelp');
    const emptyAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : (
        createAction
    );
    const pager = <TablePager links={parties.links} from={parties.from} to={parties.to} total={parties.total} />;

    /** Every row opens the SAME canonical page; a user without contacts access opens the commercial sheet they are allowed to see. */
    const openHref = (row: Row) =>
        can.viewContacts
            ? `/contacts/parties/${row.id}`
            : row.customer_id
              ? `/sales/customers/${row.customer_id}`
              : row.supplier_id
                ? `/procurement/suppliers/${row.supplier_id}`
                : null;

    async function toggleArchive(row: Row) {
        const archive = row.status === 'active';
        const ok = await confirm({
            title: t(archive ? 'cts.archiveTitle' : 'cts.restoreTitle'),
            description: t(archive ? 'cts.archiveHelp' : 'cts.restoreHelp'),
            confirmLabel: t(archive ? 'cts.archive' : 'cts.restore'),
            danger: archive,
        });
        if (ok) router.post(`/contacts/parties/${row.id}/${archive ? 'archive' : 'restore'}`, {}, { preserveScroll: true });
    }

    const actions = (row: Row) => {
        const href = openHref(row);

        return (
            <div className="flex flex-wrap justify-end gap-1.5">
                {href && (
                    <ButtonLink href={href} size="sm" variant="outline">
                        {t('cts.open')}
                        <span className="sr-only"> {row.display_name}</span>
                    </ButtonLink>
                )}
                {can.update && can.viewContacts && (
                    <ButtonLink href={`/contacts/parties/${row.id}/edit`} size="sm" variant="ghost">
                        {t('cts.edit')}
                        <span className="sr-only"> {row.display_name}</span>
                    </ButtonLink>
                )}
                {can.archive && (
                    <Button size="sm" variant="ghost" onClick={() => toggleArchive(row)}>
                        {t(row.status === 'active' ? 'cts.archive' : 'cts.restore')}
                        <span className="sr-only"> {row.display_name}</span>
                    </Button>
                )}
            </div>
        );
    };
    const nameCell = (row: Row) => {
        const href = openHref(row);
        const name = href ? (
            <Link href={href} className="font-medium text-ink underline-offset-2 hover:underline">
                {row.display_name}
            </Link>
        ) : (
            <span className="font-medium text-ink">{row.display_name}</span>
        );

        return (
            <>
                {name}
                {row.legal_name && row.legal_name !== row.display_name && <p className="text-xs text-ink-muted">{row.legal_name}</p>}
            </>
        );
    };
    const statusBadge = (row: Row) => <Badge variant={row.status === 'active' ? 'success' : 'neutral'}>{t(row.status === 'active' ? 'cts.status.active' : 'cts.status.archived')}</Badge>;
    const natureLabel = (row: Row) => t(row.kind === 'person' ? 'cts.kind.person' : 'cts.kind.organization');

    return (
        <ApplicationShell>
            <Head title={title} />
            <PageHeader title={title} description={t('cts.description')} actions={createAction} />

            <div className="mb-4">
                <AdvancedFilterBar
                    label={title}
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('cts.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={chips.length}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter label={t('cts.nature')} allLabel={t('cts.allKinds')} options={kindOptions} value={filters.kind} onChange={(value) => f.set('kind', value)} />
                    <ChoiceFilter multiple searchable label={t('cts.roles')} allLabel={t('cts.allRoles')} options={roleOptions} value={selectedRoles} onChange={setRoles} />
                    {can.viewContacts && <ChoiceFilter label={t('cts.relationship')} allLabel={t('cts.allRelationships')} options={scopeOptions} value={filters.scope} onChange={(value) => f.set('scope', value)} />}
                    <ChoiceFilter label={t('common.status')} allLabel={t('cts.status.active')} options={statusOptions} value={filters.status} onChange={(value) => f.set('status', value)} />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={title}
                state={parties.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={emptyAction}
                className="hidden md:block"
                footer={parties.data.length === 0 ? undefined : pager}
            >
                <table className="erp-table min-w-[900px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-3">{t('cts.col.name')}</th>
                            <th className="px-4 py-3">{t('cts.nature')}</th>
                            <th className="px-4 py-3">{t('cts.roles')}</th>
                            <th className="px-4 py-3">{t('cts.phone')}</th>
                            <th className="px-4 py-3">{t('cts.email')}</th>
                            <th className="px-4 py-3">{t('common.status')}</th>
                            <th className="px-4 py-3">
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {parties.data.map((row) => (
                            <tr key={row.id}>
                                <td className="max-w-72 px-4 py-3">{nameCell(row)}</td>
                                <td className="px-4 py-3 text-ink-muted">{natureLabel(row)}</td>
                                <td className="px-4 py-3">
                                    <RoleBadges roles={row.roles} />
                                </td>
                                <td className="px-4 py-3 text-ink-muted">{row.phone ? <bdi dir="ltr">{row.phone}</bdi> : '—'}</td>
                                <td className="px-4 py-3 text-ink-muted">{row.email ? <bdi>{row.email}</bdi> : '—'}</td>
                                <td className="px-4 py-3">{statusBadge(row)}</td>
                                <td className="px-4 py-3 text-end">{actions(row)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile: stacked cards (same data, same actions) */}
            {parties.data.length === 0 ? (
                <div className="md:hidden">
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={emptyAction} />
                </div>
            ) : (
                <div className="space-y-3 md:hidden" aria-busy={f.loading || undefined}>
                    <ul className={`space-y-3 transition-opacity ${f.loading ? 'opacity-55' : ''}`}>
                        {parties.data.map((row) => (
                            <li key={row.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0 text-sm">{nameCell(row)}</div>
                                    <span className="shrink-0">{statusBadge(row)}</span>
                                </div>
                                <div className="mt-2">
                                    <RoleBadges roles={row.roles} />
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('cts.nature')}</dt>
                                        <dd className="truncate text-ink-muted">{natureLabel(row)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('cts.phone')}</dt>
                                        <dd className="truncate text-ink-muted">{row.phone ? <bdi dir="ltr">{row.phone}</bdi> : '—'}</dd>
                                    </div>
                                    <div className="col-span-2 min-w-0">
                                        <dt className="text-ink-faint">{t('cts.email')}</dt>
                                        <dd className="truncate text-ink-muted">{row.email ?? '—'}</dd>
                                    </div>
                                </dl>
                                <div className="mt-3">{actions(row)}</div>
                            </li>
                        ))}
                    </ul>
                    {pager}
                </div>
            )}
        </ApplicationShell>
    );
}
