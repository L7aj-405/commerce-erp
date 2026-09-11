import { useEffect } from 'react';
import { formatDateTime, formatMoney, formatInteger } from '@/utils/format';
import { Button } from '@/components/ui/Button';
import type { HeldSale } from './types';

type Props = {
    open: boolean;
    sales: HeldSale[];
    resumingId: (id: number) => boolean;
    deletingId: (id: number) => boolean;
    onClose: () => void;
    onResume: (sale: HeldSale) => void;
    onDelete: (sale: HeldSale) => void;
};

export default function HeldSalesDrawer({ open, sales, resumingId, deletingId, onClose, onResume, onDelete }: Props) {
    useEffect(() => {
        if (!open) return;
        const handler = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [open, onClose]);

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
                    {sales.map((sale) => {
                        const resuming = resumingId(sale.id);
                        const deleting = deletingId(sale.id);
                        return (
                            <article key={sale.id} className={`rounded-2xl border border-slate-200 p-4 transition-opacity ${deleting ? 'opacity-50' : ''}`}>
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
                                    <Button
                                        type="button"
                                        block
                                        disabled={deleting}
                                        loading={resuming}
                                        loadingText="Reprise…"
                                        onClick={() => onResume(sale)}
                                        className="flex-1"
                                    >
                                        Reprendre
                                    </Button>
                                    <Button type="button" variant="danger" disabled={resuming} loading={deleting} onClick={() => onDelete(sale)}>
                                        Supprimer
                                    </Button>
                                </div>
                            </article>
                        );
                    })}
                </div>
            </aside>
        </div>
    );
}
