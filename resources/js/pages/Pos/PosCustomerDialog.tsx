import ContactForm from '@/components/contacts/ContactForm';
import { toCommercialPayload } from '@/components/contacts/contactValues';
import type { ContactCan, ContactResult, ContactValues } from '@/components/contacts/types';
import { useDebouncedValue } from '@/components/pos/useDebouncedValue';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Skeleton } from '@/components/ui/Skeleton';
import { Badge } from '@/components/ui/StatusBadge';
import { Spinner } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/toast';
import { useI18n } from '@/i18n';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { posJson } from './api';
import type { Customer } from './types';

type View = 'search' | 'create' | 'edit';
type LoadState = 'idle' | 'loading' | 'ready' | 'error' | 'forbidden';

/**
 * Centered customer picker for the POS. Uses the existing endpoints only:
 *   GET   /pos/customers?search=   (empty search → recently updated customers)
 *   POST  /pos/customers           (create; 422 field errors shown inline)
 *   PATCH /pos/customers/{id}      (edit the attached customer)
 * Attaching/removing goes through the caller (draft update), so the transaction
 * state stays owned by the POS page. A customer is optional (walk-in sales).
 */
export default function PosCustomerDialog({
    open,
    initialView = 'search',
    current,
    canCreate,
    canUpdate,
    attaching,
    onClose,
    onSelect,
    onUpdated,
    onRemove,
}: {
    open: boolean;
    initialView?: View;
    current: Customer | null;
    canCreate: boolean;
    canUpdate: boolean;
    attaching: boolean;
    onClose: () => void;
    /** Resolves true when attached; on false the caller already showed the error. */
    onSelect: (customer: Customer) => Promise<boolean>;
    onUpdated: (customer: Customer) => Promise<void>;
    onRemove: () => void;
}) {
    const { t } = useI18n();
    const toast = useToast();
    const [view, setView] = useState<View>(initialView);
    const [query, setQuery] = useState('');
    const debounced = useDebouncedValue(query.trim(), 250);
    const [results, setResults] = useState<Customer[]>([]);
    const [load, setLoad] = useState<LoadState>('idle');
    const [reloadKey, setReloadKey] = useState(0);
    const [choosingId, setChoosingId] = useState<number | null>(null);
    const listRef = useRef<HTMLUListElement>(null);

     // The create / edit view is the shared ContactForm (quick mode). The cart, payments and every other POS state live in the POS page and are untouched.
    const [prefill, setPrefill] = useState('');
    const [saving, setSaving] = useState(false);

    // Reset each time the dialog opens.
    useEffect(() => {
        if (!open) return;
        setQuery('');
        setResults([]);
        setChoosingId(null);
        if (initialView === 'edit' && current) startEdit(current);
        else setView(initialView === 'create' ? 'create' : 'search');
        if (initialView === 'create') startCreate('');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // Moving between views unmounts the focused button: put focus on the view's first field.
    const firstView = useRef(true);
    useEffect(() => {
        if (!open) {
            firstView.current = true;
            return;
        }
        if (firstView.current) {
            firstView.current = false; // the dialog's own initial focus handles the first view
            return;
        }
        window.setTimeout(() => {
            const target = view === 'search' ? document.getElementById('pos-customer-search') : document.querySelector<HTMLElement>('#pos-customer-form [data-autofocus]');
            target?.focus();
        }, 0);
    }, [open, view]);

    // Search: empty → recent customers; 1 char → keep list + hint; ≥2 → server search.
    useEffect(() => {
        if (!open || view !== 'search') return;
        if (debounced.length === 1) return;
        const controller = new AbortController();
        setLoad('loading');
        fetch(`/pos/customers?${new URLSearchParams({ search: debounced })}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (response.status === 403) {
                    setLoad('forbidden');
                    return;
                }
                if (!response.ok) throw new Error('search failed');
                const payload = (await response.json()) as { data: Customer[] };
                setResults(payload.data);
                setLoad('ready');
            })
            .catch((reason: Error) => {
                if (reason.name !== 'AbortError') setLoad('error');
            });
        return () => controller.abort();
    }, [open, view, debounced, reloadKey]);

     function startCreate(name: string) {
        setPrefill(name);
        setView('create');
    }

    function startEdit(_customer: Customer) {
        setView('edit');
    }

    async function choose(customer: Customer) {
        if (choosingId !== null || attaching) return;
        if (current?.id === customer.id) {
            onClose();
            return;
        }
        setChoosingId(customer.id);
        try {
            if (await onSelect(customer)) {
                toast.success(t('pos.customerAttached').replace('{name}', customer.display_name));
                onClose();
            }
        } finally {
            setChoosingId(null);
        }
    }

     /** POS transport of the shared form: the existing backward-compatible customer endpoints (no second creation path). */
    async function transport(values: ContactValues): Promise<ContactResult> {
        const body = toCommercialPayload(values);
        const editing = view === 'edit' && current;
        const payload = await posJson<{ data: Customer }>(editing ? `/pos/customers/${current.id}` : '/pos/customers', { method: editing ? 'PATCH' : 'POST', body });
        return { party_id: 0, display_name: payload.data.display_name, reused: false, customer: payload.data as ContactResult['customer'], supplier: null };
    }

    async function saved(result: ContactResult) {
        const customer = result.customer as unknown as Customer;
        if (view === 'create') {
            toast.success(t('pos.customerSaved'));
            if (!(await onSelect(customer))) {
                // Created but not attached: keep it findable instead of losing it.
                setView('search');
                setQuery(customer.display_name);
                return;
            }
        } else {
            await onUpdated(customer);
            toast.success(t('pos.customerUpdated'));
        }
        onClose();
    }

    // Arrow-key movement between the search field and result rows.
    function onListKeyDown(event: KeyboardEvent<HTMLElement>) {
        const items = Array.from(listRef.current?.querySelectorAll<HTMLButtonElement>('button[data-customer]') ?? []);
        if (items.length === 0) return;
        const index = items.indexOf(document.activeElement as HTMLButtonElement);
        let next: number | null = null;
        if (event.key === 'ArrowDown') next = index < 0 ? 0 : Math.min(items.length - 1, index + 1);
        else if (event.key === 'ArrowUp') next = index <= 0 ? -1 : index - 1;
        else if (event.key === 'Home' && index >= 0) next = 0;
        else if (event.key === 'End' && index >= 0) next = items.length - 1;
        if (next === null) return;
        event.preventDefault();
        if (next === -1) document.getElementById('pos-customer-search')?.focus();
        else items[next]?.focus();
    }

    const busy = saving || attaching;

    const searchFooter = (
        <div className="flex flex-wrap items-center justify-between gap-2">
            {current ? (
                <Button
                    variant="danger"
                    onClick={() => {
                        onRemove();
                        onClose();
                    }}
                    disabled={attaching}
                >
                    {t('pos.removeCustomer')}
                </Button>
            ) : (
                <span className="text-[13px] text-ink-muted">{t('pos.walkInHelp')}</span>
            )}
            <div className="ms-auto flex gap-2">
                <Button variant="secondary" onClick={onClose}>
                    {t('pos.cancel')}
                </Button>
                {canCreate && (
                    <Button variant="outline" onClick={() => startCreate(query.trim())}>
                        <PlusIcon />
                        {t('pos.addNewCustomer')}
                    </Button>
                )}
            </div>
        </div>
    );

    const formFooter = (
        <div className="flex flex-wrap justify-end gap-2">
            <Button variant="secondary" onClick={() => (view === 'edit' && initialView === 'edit' ? onClose() : setView('search'))} disabled={busy}>
                {view === 'edit' && initialView === 'edit' ? t('pos.cancel') : t('pos.backToSearch')}
            </Button>
            <Button type="submit" form="pos-customer-form" loading={busy} loadingText={t('pos.saving')}>
                {view === 'create' ? t('pos.createCustomer') : t('pos.save')}
            </Button>
        </div>
    );

    return (
        <Dialog
            open={open}
            onClose={() => !busy && onClose()}
            dismissible={!busy}
            size="md"
            title={view === 'create' ? t('pos.newCustomerTitle') : view === 'edit' ? t('pos.editCustomerTitle') : t('pos.customerDialogTitle')}
            description={view === 'search' ? t('pos.customerDialogHelp') : undefined}
            footer={view === 'search' ? searchFooter : formFooter}
        >
            {view === 'search' ? (
                <div className="flex min-h-[22rem] flex-col">
                    <div className="sticky top-0 z-10 border-b border-line bg-surface px-4 py-3 sm:px-6">
                        <label htmlFor="pos-customer-search" className="sr-only">
                            {t('pos.searchCustomer')}
                        </label>
                        <div className="relative">
                            <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-ink-faint">
                                <SearchIcon />
                            </span>
                            <input
                                id="pos-customer-search"
                                data-autofocus
                                type="search"
                                autoComplete="off"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                onKeyDown={onListKeyDown}
                                placeholder={t('pos.searchCustomer')}
                                aria-describedby="pos-customer-search-hint"
                                className="control-input h-12 ps-10 pe-10 text-base"
                            />
                            {load === 'loading' && (
                                <span className="absolute inset-y-0 end-3 flex items-center text-ink-muted">
                                    <Spinner size="sm" />
                                </span>
                            )}
                        </div>
                        <p id="pos-customer-search-hint" className="mt-1.5 text-[13px] text-ink-muted">
                            {query.trim().length === 1 ? t('pos.customerSearchMin') : query.trim() ? t('pos.searchResults') : t('pos.recentCustomers')}
                        </p>
                    </div>

                    <div className="flex-1 px-2 py-2 sm:px-4" aria-live="polite">
                        {load === 'forbidden' ? (
                            <Alert tone="neutral" className="m-2">
                                {t('pos.customerListForbidden')}
                            </Alert>
                        ) : load === 'error' ? (
                            <Alert
                                tone="danger"
                                className="m-2"
                                actions={
                                    <Button size="sm" variant="secondary" onClick={() => setReloadKey((key) => key + 1)}>
                                        {t('ui.retry')}
                                    </Button>
                                }
                            >
                                {t('pos.customerSearchFailed')}
                            </Alert>
                        ) : load === 'loading' && results.length === 0 ? (
                            <div className="space-y-2 p-2" aria-hidden="true">
                                {[0, 1, 2, 3].map((index) => (
                                    <Skeleton key={index} className="h-14" />
                                ))}
                            </div>
                        ) : results.length === 0 && load === 'ready' ? (
                            <div className="px-4 py-8 text-center">
                                <p className="text-sm font-medium text-ink">{t('pos.noCustomerFound')}</p>
                                {canCreate && (
                                    <Button className="mt-3" variant="outline" onClick={() => startCreate(query.trim())}>
                                        <PlusIcon />
                                        {t('pos.addNewCustomer')}
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <ul ref={listRef} className={`space-y-1 transition-opacity ${load === 'loading' ? 'opacity-60' : ''}`} onKeyDown={onListKeyDown}>
                                {results.map((customer) => {
                                    const isCurrent = current?.id === customer.id;
                                    const details = [customer.company_name !== customer.display_name ? customer.company_name : null, customer.phone, customer.email].filter(Boolean).join(' · ');
                                    return (
                                        <li key={customer.id}>
                                            <button
                                                type="button"
                                                data-customer
                                                onClick={() => void choose(customer)}
                                                disabled={choosingId !== null}
                                                aria-describedby={isCurrent ? `pos-current-${customer.id}` : undefined}
                                                className="group flex min-h-14 w-full cursor-pointer items-center gap-3 rounded-lg border border-transparent px-3 py-2.5 text-start transition-soft hover:border-line hover:bg-sage focus-visible:border-line focus-visible:bg-sage disabled:cursor-wait"
                                            >
                                                <span aria-hidden="true" className="grid size-9 shrink-0 place-items-center rounded-full bg-sage-deep text-sm font-semibold text-ink">
                                                    {customer.display_name.trim().charAt(0).toUpperCase() || '?'}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center gap-2">
                                                        <span className="truncate text-sm font-semibold text-ink">{customer.display_name}</span>
                                                        {isCurrent && (
                                                            <span id={`pos-current-${customer.id}`}>
                                                                <Badge variant="accent" size="sm">
                                                                    {t('pos.currentCustomer')}
                                                                </Badge>
                                                            </span>
                                                        )}
                                                    </span>
                                                    {details && <span className="mt-0.5 block truncate text-[13px] text-ink-muted">{details}</span>}
                                                </span>
                                                <span className="inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-field border border-line-strong bg-surface px-3 text-[13px] font-semibold text-ink group-hover:border-ink-faint">
                                                    {choosingId === customer.id ? <Spinner size="xs" /> : null}
                                                    {t('pos.selectThisCustomer')}
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                </div>
            ) : (
                 <div className="p-4 sm:p-6">
                    {!canUpdate && view === 'edit' && <Alert tone="neutral" className="mb-4">{t('ui.forbidden')}</Alert>}
                    <ContactForm
                        key={view}
                        mode="quick"
                        layout="overlay"
                        natureLabels="commercial"
                        legacyFiscal
                        allowedRoles={['client']}
                        presetRoles={['client']}
                        catalogue={{ roles: [], paymentTerms: [] }}
                        can={QUICK_CAN}
                        formId="pos-customer-form"
                        hideActions
                        initialValues={view === 'edit' && current ? valuesFromCustomer(current) : { display_name: prefill }}
                        transport={transport}
                        onBusyChange={setSaving}
                        onSuccess={(result) => void saved(result)}
                    />
                </div>
            )}
        </Dialog>
    );
}

/** The quick form never offers roles or commercial conditions: nothing but identity, so no permission beyond the customer endpoints is implied. */
const QUICK_CAN: ContactCan = {
    create: false,
    update: false,
    archive: false,
    manageRoles: false,
    createCustomer: false,
    createSupplier: false,
    manageTerms: false,
    assignCustomerTerms: false,
    assignSupplierTerms: false,
    viewCustomers: false,
    viewSuppliers: false,
};

/** Existing POS customer -> shared form values (Entreprise: the company name is the display name, the contact person its own field). */
function valuesFromCustomer(customer: Customer): Partial<ContactValues> {
    const company = customer.type === 'company';
    return {
        kind: company ? 'organization' : 'person',
        display_name: company ? (customer.company_name ?? customer.display_name) : customer.display_name,
        contact_person: company && customer.company_name && customer.company_name !== customer.display_name ? customer.display_name : '',
        phone: customer.phone ?? '',
        email: customer.email ?? '',
        tax_identifier: customer.tax_identifier ?? '',
        address: customer.billing_address ?? '',
    };
}

function SearchIcon() {
    return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </svg>
    );
}

export function PlusIcon() {
    return (
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
            <path d="M12 5v14M5 12h14" />
        </svg>
    );
}
