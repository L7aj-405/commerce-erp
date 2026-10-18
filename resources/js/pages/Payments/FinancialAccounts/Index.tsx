import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import PaymentsLayout from '@/layouts/PaymentsLayout';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Fragment, useState } from 'react';

type PaymentMethodOption = { value: string; label: string };
type Account = {
    id: number;
    name: string;
    code: string;
    type: string;
    status: 'active' | 'inactive';
    currency_code: string;
    notes: string | null;
    accepted_methods: string[];
};
type Props = {
    accounts: Account[];
    currencyCode: string;
    paymentMethods: PaymentMethodOption[];
    positions: { as_of: string; complete: boolean; total: string | null; configured_count: number; unconfigured_count: number; accounts: AccountPosition[] };
    can: { create: boolean; update: boolean; manageBalance: boolean };
};
type AccountPosition = { id:number; configured:boolean; effective_date:string|null; opening_amount:string|null; incoming:string|null; refunds:string|null; outgoing:string|null; balance:string|null; last_activity:string|null; currency_code:string };

const TYPE_LABELS: Record<string, string> = {
    cash: 'Caisse',
    bank: 'Banque',
    card_clearing: 'Clearing carte',
    cheque_clearing: 'Clearing chèque',
    other: 'Autre',
};

const fieldClass = 'mt-1.5 h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';
const labelClass = 'block text-[13px] font-medium text-ink';
const errorClass = 'mt-1.5 text-[13px] text-danger';

function AccountForm({
    account,
    currencyCode,
    paymentMethods,
    onDone,
}: {
    account?: Account;
    currencyCode: string;
    paymentMethods: PaymentMethodOption[];
    onDone: () => void;
}) {
    const form = useForm({
        name: account?.name ?? '',
        code: account?.code ?? '',
        type: account?.type ?? 'cash',
        status: account?.status ?? 'active',
        currency_code: account?.currency_code ?? currencyCode,
        notes: account?.notes ?? '',
        accepted_methods: account?.accepted_methods ?? [],
    });

    function toggleMethod(value: string) {
        form.setData('accepted_methods', form.data.accepted_methods.includes(value)
            ? form.data.accepted_methods.filter((method) => method !== value)
            : [...form.data.accepted_methods, value]);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        account ? form.patch(`/financial-accounts/${account.id}`, options) : form.post('/financial-accounts', options);
    }

    return (
        <form onSubmit={submit} className="space-y-4 rounded-card border border-line bg-surface p-5">
            <h3 className="text-sm font-semibold text-ink">{account ? `Modifier « ${account.name} »` : 'Nouveau compte financier'}</h3>

            <div className="grid gap-4 sm:grid-cols-2">
                <label className={labelClass}>
                    Nom
                    <input required maxLength={255} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={fieldClass} />
                    {form.errors.name && <p className={errorClass}>{form.errors.name}</p>}
                </label>
                <label className={labelClass}>
                    Code
                    <input required maxLength={64} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className={fieldClass} />
                    {form.errors.code && <p className={errorClass}>{form.errors.code}</p>}
                </label>
                <label className={labelClass}>
                    Type / catégorie
                    <select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} className={fieldClass}>
                        {Object.entries(TYPE_LABELS).map(([value, label]) => (
                            <option key={value} value={value}>{label}</option>
                        ))}
                    </select>
                </label>
                <label className={labelClass}>
                    Statut
                    <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value as 'active' | 'inactive')} className={fieldClass}>
                        <option value="active">Actif</option>
                        <option value="inactive">Inactif</option>
                    </select>
                </label>
                <label className={labelClass}>
                    Devise
                    <input required maxLength={3} value={form.data.currency_code} onChange={(e) => form.setData('currency_code', e.target.value.toUpperCase())} className={fieldClass} />
                    {form.errors.currency_code && <p className={errorClass}>{form.errors.currency_code}</p>}
                </label>
            </div>

            <div>
                <p className={labelClass}>Moyens de paiement acceptés</p>
                <p className="mt-1 text-[13px] text-ink-muted">Sélectionnez les moyens de paiement pouvant être encaissés sur ce compte. Un compte peut en accepter plusieurs.</p>
                <div className="mt-2.5 grid gap-2 sm:grid-cols-2">
                    {paymentMethods.map((method) => (
                        <label key={method.value} className="flex items-center gap-2 rounded-field border border-line-strong bg-raised px-3 py-2 text-sm text-ink">
                            <input
                                type="checkbox"
                                checked={form.data.accepted_methods.includes(method.value)}
                                onChange={() => toggleMethod(method.value)}
                                className="size-4 rounded border-line-strong text-primary focus:ring-primary"
                            />
                            {method.label}
                        </label>
                    ))}
                </div>
                {form.errors.accepted_methods && <p className={errorClass}>{form.errors.accepted_methods}</p>}
            </div>

            <label className={labelClass}>
                Notes
                <textarea maxLength={5000} rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className={`${fieldClass} h-auto py-2`} />
            </label>

            <div className="flex gap-2">
                <Button type="submit" loading={form.processing} loadingText="Enregistrement…">
                    Enregistrer
                </Button>
                <button type="button" onClick={onDone} className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised">
                    Annuler
                </button>
            </div>
        </form>
    );
}

function MethodBadges({ methods, paymentMethods }: { methods: string[]; paymentMethods: PaymentMethodOption[] }) {
    if (methods.length === 0) {
        return <span className="text-[13px] text-warning">Aucun moyen accepté</span>;
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {methods.map((value) => (
                <span key={value} className="inline-flex rounded-full bg-sage px-2.5 py-1 text-[11px] font-semibold text-ink">
                    {paymentMethods.find((m) => m.value === value)?.label ?? value}
                </span>
            ))}
        </div>
    );
}

function OpeningPositionForm({ account, position, onDone }: { account:Account; position?:AccountPosition; onDone:()=>void }) {
    const form = useForm({ effective_date: position?.effective_date ?? new Date().toISOString().slice(0,10), amount: position?.opening_amount ?? '', currency_code: account.currency_code, reference:'', notes:'' });
    return <form onSubmit={(e)=>{e.preventDefault();form.put(`/financial-accounts/${account.id}/opening-position`,{preserveScroll:true,onSuccess:onDone});}} className="grid gap-4 rounded-field border border-line bg-surface p-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><h3 className="font-semibold">{position?.configured?'Corriger le solde d’ouverture':'Configurer le solde d’ouverture'}</h3><p className="mt-1 text-xs text-ink-muted">Le montant représente le solde réel au début de la date choisie. Les mouvements de cette date seront ensuite appliqués.</p></div>
        <label className={labelClass}>Date de référence<input type="date" required value={form.data.effective_date} onChange={e=>form.setData('effective_date',e.target.value)} className={fieldClass}/>{form.errors.effective_date&&<p className={errorClass}>{form.errors.effective_date}</p>}</label>
        <label className={labelClass}>Solde d’ouverture ({account.currency_code})<input type="number" step="0.0001" required value={form.data.amount} onChange={e=>form.setData('amount',e.target.value)} className={fieldClass}/>{form.errors.amount&&<p className={errorClass}>{form.errors.amount}</p>}</label>
        <label className={labelClass}>Référence<input maxLength={255} value={form.data.reference} onChange={e=>form.setData('reference',e.target.value)} className={fieldClass}/></label>
        <label className={labelClass}>Note<input maxLength={5000} value={form.data.notes} onChange={e=>form.setData('notes',e.target.value)} className={fieldClass}/></label>
        <div className="flex gap-2 sm:col-span-2"><Button type="submit" loading={form.processing}>Enregistrer</Button><Button type="button" variant="secondary" onClick={onDone}>Annuler</Button></div>
    </form>;
}

export default function FinancialAccounts({ accounts, currencyCode, paymentMethods, positions, can }: Props) {
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const [opening, setOpening] = useState<number|null>(null);

    return (
        <PaymentsLayout>
            <Head title="Comptes financiers" />
            <PageHeader
                title="Comptes financiers"
                description="Un compte peut recevoir plusieurs moyens de paiement — inutile de créer un compte par moyen d’encaissement."
                actions={can.create && editing !== 'new' ? <Button size="sm" onClick={() => setEditing('new')}>+ Nouveau compte</Button> : undefined}
            />

            <div className={`mb-5 rounded-card border p-4 ${positions.complete?'border-line bg-surface':'border-warning/30 bg-warning-soft'}`}>
                <p className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Position de trésorerie au {positions.as_of}</p>
                <p className="mt-1 text-xl font-bold">{positions.complete ? `${Number(positions.total).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2})} ${currencyCode}` : 'Solde non configuré'}</p>
                <p className="mt-1 text-xs text-ink-muted">{positions.configured_count} compte(s) configuré(s) · {positions.unconfigured_count} non configuré(s)</p>
            </div>

            {editing === 'new' && (
                <div className="mb-6">
                    <AccountForm currencyCode={currencyCode} paymentMethods={paymentMethods} onDone={() => setEditing(null)} />
                </div>
            )}

            {accounts.length === 0 && editing !== 'new' ? (
                <EmptyState
                    title="Aucun compte financier."
                    description="Créez un compte pour pouvoir encaisser des paiements (POS, commandes)."
                    actions={can.create ? <Button size="sm" onClick={() => setEditing('new')}>+ Nouveau compte</Button> : undefined}
                />
            ) : (
                <div className="overflow-x-auto rounded-card border border-line bg-surface">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2.5">Compte</th>
                                <th className="px-4 py-2.5">Type</th>
                                <th className="px-4 py-2.5">Moyens acceptés</th>
                                <th className="px-4 py-2.5">Devise</th>
                                <th className="px-4 py-2.5">Statut</th>
                                <th className="px-4 py-2.5">Solde actuel</th>
                                <th className="px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.map((account) => (
                                <Fragment key={account.id}>
                                    {(() => { const position=positions.accounts.find(row=>row.id===account.id); return <>
                                    <tr className="border-t border-line align-top">
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-ink">{account.name}</p>
                                            <p className="text-[12px] uppercase tracking-wide text-ink-faint">{account.code}</p>
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">{TYPE_LABELS[account.type] ?? account.type}</td>
                                        <td className="px-4 py-3"><MethodBadges methods={account.accepted_methods} paymentMethods={paymentMethods} /></td>
                                        <td className="px-4 py-3 text-ink-muted">{account.currency_code}</td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ${account.status === 'active' ? 'bg-success-soft text-success' : 'bg-raised text-ink-muted'}`}>
                                                {account.status === 'active' ? 'Actif' : 'Inactif'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3"><p className="font-medium">{position?.configured ? `${Number(position.balance).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2})} ${account.currency_code}` : 'Non configuré'}</p>{position?.effective_date&&<p className="text-xs text-ink-muted">Depuis le {position.effective_date}</p>}</td>
                                        <td className="px-4 py-3 text-right">
                                            {can.manageBalance&&<button type="button" onClick={()=>setOpening(opening===account.id?null:account.id)} className="mr-3 text-[13px] font-medium text-ink-muted underline hover:text-ink">{position?.configured?'Solde initial':'Configurer le solde'}</button>}
                                            {can.update && (
                                                <button
                                                    type="button"
                                                    onClick={() => setEditing(editing === account.id ? null : account.id)}
                                                    className="text-[13px] font-medium text-ink-muted underline transition-soft hover:text-ink"
                                                >
                                                    {editing === account.id ? 'Fermer' : 'Modifier'}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                    {editing === account.id && (
                                        <tr className="border-t border-line bg-raised">
                                            <td colSpan={7} className="p-4">
                                                <AccountForm account={account} currencyCode={currencyCode} paymentMethods={paymentMethods} onDone={() => setEditing(null)} />
                                            </td>
                                        </tr>
                                    )}
                                    {opening===account.id&&<tr className="border-t border-line bg-raised"><td colSpan={7} className="p-4"><OpeningPositionForm account={account} position={position} onDone={()=>setOpening(null)}/></td></tr>}
                                    </>; })()}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </PaymentsLayout>
    );
}
