import { AdvancedFilterBar, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import { Checkbox, FieldError } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import { Sheet } from '@/components/ui/Sheet';
import StatusBadge from '@/components/ui/StatusBadge';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useId, useState } from 'react';

type Supplier = {
    id: number;
    name: string;
    contact_person: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    notes: string | null;
    active: boolean;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    suppliers: { data: Supplier[]; links: LinkData[]; from?: number | null; to?: number | null; total?: number };
    filters: { search?: string };
    can: { manage: boolean };
};

const blank = { name: '', contact_person: '', phone: '', email: '', address: '', notes: '', active: true };

export default function SuppliersIndex({ suppliers, filters, can }: Props) {
    const { t } = useI18n();
    // SupplierController@index validates `search` only.
    const f = useQueryFilters('/procurement/suppliers', filters, { keys: ['search'] });
    const [editing, setEditing] = useState<Supplier | null>(null);
    const [creating, setCreating] = useState(false);
    const form = useForm<typeof blank>({ ...blank });
    const formId = useId();

    const startCreate = () => {
        form.setData({ ...blank });
        form.clearErrors();
        setEditing(null);
        setCreating(true);
    };
    const startEdit = (supplier: Supplier) => {
        form.setData({
            name: supplier.name,
            contact_person: supplier.contact_person ?? '',
            phone: supplier.phone ?? '',
            email: supplier.email ?? '',
            address: supplier.address ?? '',
            notes: supplier.notes ?? '',
            active: supplier.active,
        });
        form.clearErrors();
        setCreating(false);
        setEditing(supplier);
    };
    const close = () => {
        setCreating(false);
        setEditing(null);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editing) {
            form.patch(`/procurement/suppliers/${editing.id}`, { preserveScroll: true, onSuccess: close });
        } else {
            form.post('/procurement/suppliers', { preserveScroll: true, onSuccess: close });
        }
    };

    const chips: FilterChip[] = filters.search
        ? [{ key: 'search', label: t('filters.search'), value: filters.search, onRemove: () => f.remove('search') }]
        : [];
    const filtered = f.activeCount > 0;
    const emptyTitle = filtered ? t('filters.noMatch') : t('suppliers.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('suppliers.description');
    const emptyAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : can.manage ? (
        <Button onClick={startCreate}>{t('suppliers.new')}</Button>
    ) : undefined;
    const pager = <TablePager links={suppliers.links} from={suppliers.from} to={suppliers.to} total={suppliers.total} />;

    return (
        <ProcurementLayout>
            <Head title={t('suppliers.title')} />
            <PageHeader
                title={t('suppliers.title')}
                description={t('suppliers.description')}
                actions={
                    <>
                        <ButtonLink href="/procurement" variant="secondary">
                            {t('suppliers.procurements')}
                        </ButtonLink>
                        {can.manage && <Button onClick={startCreate}>{t('suppliers.new')}</Button>}
                    </>
                }
            />

            <div className="mb-4">
                <AdvancedFilterBar
                    label={t('suppliers.title')}
                    search={
                        <FilterSearch
                            value={f.search}
                            onChange={f.setSearch}
                            loading={f.searching}
                            label={t('common.search')}
                            placeholder={t('suppliers.searchPlaceholder')}
                        />
                    }
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                />
            </div>

            <TableShell
                label={t('suppliers.title')}
                state={suppliers.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={emptyAction}
                className="hidden md:block"
                footer={suppliers.data.length === 0 ? undefined : pager}
            >
                <table className="erp-table min-w-[720px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-3">{t('suppliers.name')}</th>
                            <th className="px-4 py-3">{t('suppliers.contact')}</th>
                            <th className="px-4 py-3">{t('suppliers.phone')}</th>
                            <th className="px-4 py-3">{t('suppliers.email')}</th>
                            <th className="px-4 py-3">{t('common.status')}</th>
                            {can.manage && (
                                <th className="px-4 py-3">
                                    <span className="sr-only">{t('common.actions')}</span>
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        {suppliers.data.map((supplier) => (
                            <tr key={supplier.id}>
                                <td className="px-4 py-3 font-semibold">
                                    <Link href={`/procurement/suppliers/${supplier.id}`} className="text-ink hover:text-accent hover:underline">
                                        {supplier.name}
                                    </Link>
                                </td>
                                <td className="px-4 py-3 text-ink-muted">{supplier.contact_person ?? '—'}</td>
                                <td className="px-4 py-3 text-ink-muted">
                                    <bdi>{supplier.phone ?? '—'}</bdi>
                                </td>
                                <td className="px-4 py-3 text-ink-muted">{supplier.email ?? '—'}</td>
                                <td className="px-4 py-3">
                                    <StatusBadge status={supplier.active ? 'active' : 'inactive'} />
                                </td>
                                {can.manage && (
                                    <td className="px-4 py-3 text-end">
                                        <Button type="button" size="sm" variant="secondary" onClick={() => startEdit(supplier)}>
                                            {t('suppliers.edit')}
                                        </Button>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile: stacked cards */}
            {suppliers.data.length === 0 ? (
                <div className="md:hidden">
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={emptyAction} />
                </div>
            ) : (
                <div className="space-y-3 md:hidden" aria-busy={f.loading || undefined}>
                    <ul className={`space-y-3 transition-opacity ${f.loading ? 'opacity-55' : ''}`}>
                        {suppliers.data.map((supplier) => (
                            <li key={supplier.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/procurement/suppliers/${supplier.id}`} className="block truncate text-sm font-semibold text-ink hover:underline">
                                            {supplier.name}
                                        </Link>
                                        {supplier.contact_person && <p className="truncate text-[13px] text-ink-muted">{supplier.contact_person}</p>}
                                    </div>
                                    <StatusBadge status={supplier.active ? 'active' : 'inactive'} />
                                </div>
                                <dl className="mt-3 space-y-1 text-[13px]">
                                    <div className="flex gap-1.5">
                                        <dt className="shrink-0 text-ink-faint">{t('suppliers.phone')}</dt>
                                        <dd className="min-w-0 truncate text-ink-muted">
                                            {supplier.phone ? (
                                                <a href={`tel:${supplier.phone}`} className="text-ink">
                                                    <bdi>{supplier.phone}</bdi>
                                                </a>
                                            ) : (
                                                '—'
                                            )}
                                        </dd>
                                    </div>
                                    <div className="flex gap-1.5">
                                        <dt className="shrink-0 text-ink-faint">{t('suppliers.email')}</dt>
                                        <dd className="min-w-0 truncate text-ink-muted">
                                            {supplier.email ? (
                                                <a href={`mailto:${supplier.email}`} className="text-ink">
                                                    {supplier.email}
                                                </a>
                                            ) : (
                                                '—'
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                                {can.manage && (
                                    <Button type="button" size="sm" variant="secondary" className="mt-3" onClick={() => startEdit(supplier)}>
                                        {t('suppliers.edit')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                    {pager}
                </div>
            )}

            <Sheet
                open={creating || editing !== null}
                onClose={close}
                title={editing ? t('suppliers.edit') : t('suppliers.new')}
                description={editing?.name}
                widthClassName="max-w-lg"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={close}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" form={formId} loading={form.processing} loadingText={t('common.loading')}>
                            {t('common.save')}
                        </Button>
                    </div>
                }
            >
                <form id={formId} onSubmit={submit} className="grid gap-4 px-4 py-5 sm:px-6">
                    <Field label={t('suppliers.name')} error={form.errors.name}>
                        <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="control-input w-full" required />
                    </Field>
                    <Field label={t('suppliers.contactPerson')} error={form.errors.contact_person}>
                        <input value={form.data.contact_person} onChange={(e) => form.setData('contact_person', e.target.value)} className="control-input w-full" />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('suppliers.phone')} error={form.errors.phone}>
                            <input
                                type="tel"
                                dir="ltr"
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                                className="control-input w-full text-start"
                            />
                        </Field>
                        <Field label={t('suppliers.email')} error={form.errors.email}>
                            <input
                                type="email"
                                dir="ltr"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                className="control-input w-full text-start"
                            />
                        </Field>
                    </div>
                    <Field label={t('suppliers.address')} error={form.errors.address}>
                        <textarea value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} rows={2} className="control-input w-full py-2" />
                    </Field>
                    <Field label={t('suppliers.notes')} error={form.errors.notes}>
                        <textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={2} className="control-input w-full py-2" />
                    </Field>
                    <Checkbox label={t('suppliers.active')} checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} />
                </form>
            </Sheet>
        </ProcurementLayout>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <label className="block text-sm">
            <span className="mb-1.5 block text-[13px] font-medium text-ink">{label}</span>
            {children}
            <FieldError>{error}</FieldError>
        </label>
    );
}
