import { formatDateTime, formatMoney, formatInteger } from '@/utils/format';
import type { HeldSale } from './types';

type Props = {
    open: boolean;
    sales: HeldSale[];
    busy: boolean;
    onClose: () => void;
    onResume: (sale: HeldSale) => void;
    onDelete: (sale: HeldSale) => void;
};

export default function HeldSalesDrawer({ open, sales, busy, onClose, onResume, onDelete }: Props) {
    if (!open) return null;

    return (
        <div className="fixed inset-0 z-40 flex justify-end bg-slate-950/30">
            <button type="button" className="flex-1" onClick={onClose} aria-label="Fermer" />
            <aside className="w-full max-w-md overflow-y-auto bg-white p-5 shadow-2xl">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-lg font-semibold">Ventes en attente ({sales.length})</h2>
                        <p className="text-sm text-slate-500">Reprenez une vente mise en attente sans réserver le stock.</p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg border border-slate-200 px-3 py-2 text-sm">Fermer</button>
                </div>
                <div className="mt-5 space-y-3">
                    {sales.length === 0 && <div className="rounded-xl border border-dashed border-slate-300 p-6 text-sm text-slate-500">Aucune vente en attente pour ce magasin.</div>}
                    {sales.map((sale) => (
                        <article key={sale.id} className="rounded-2xl border border-slate-200 p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-semibold">{sale.order_number}</p>
                                    <p className="text-sm text-slate-500">{sale.held_at ? formatDateTime(sale.held_at) : 'En attente'}</p>
                                </div>
                                <p className="text-sm font-semibold">{formatMoney(sale.subtotal, sale.currency_code)}</p>
                            </div>
                            <div className="mt-3 space-y-1 text-sm text-slate-600">
                                <p>{formatInteger(sale.product_count)} produits</p>
                                {sale.customer_name && <p>Client: {sale.customer_name}</p>}
                                {sale.warehouse && <p>Entrepôt: {sale.warehouse.name}</p>}
                            </div>
                            <div className="mt-4 flex gap-2">
                                <button type="button" disabled={busy} onClick={() => onResume(sale)} className="inline-flex min-h-10 flex-1 items-center justify-center rounded-xl bg-slate-950 px-3 py-2 text-sm font-medium text-white disabled:opacity-50">Reprendre</button>
                                <button type="button" disabled={busy} onClick={() => onDelete(sale)} className="inline-flex min-h-10 items-center justify-center rounded-xl border border-red-200 px-3 py-2 text-sm font-medium text-red-700 disabled:opacity-50">Supprimer</button>
                            </div>
                        </article>
                    ))}
                </div>
            </aside>
        </div>
    );
}
