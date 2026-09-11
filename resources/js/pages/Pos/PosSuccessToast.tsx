import { Money } from '@/components/pos/primitives';
import { Link } from '@inertiajs/react';

type Props = {
    order: {
        id: number;
        order_number: string;
        total_incl_tax: string;
        currency_code: string;
        paid: string;
        remaining: string;
        requires_replenishment: boolean;
        remote_required: string;
    };
    onPrint: () => void;
    onNewSale: () => void;
    onDismiss: () => void;
};

export default function PosSuccessToast({ order, onPrint, onNewSale, onDismiss }: Props) {
    const hasRemaining = Number(order.remaining) > 0.00005;

    return (
        <div className="pointer-events-none fixed inset-x-0 top-16 z-40 flex justify-center px-4 print:hidden">
            <div className="pointer-events-auto w-full max-w-md rounded-card border border-line bg-surface p-4 shadow-pop">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <span className="grid size-7 place-items-center rounded-full bg-success-soft text-success">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="m5 13 4 4L19 7" /></svg>
                        </span>
                        <div>
                            <p className="text-[13px] font-semibold text-ink">Vente {order.order_number} enregistrée</p>
                            <p className="text-[12px] text-ink-muted">
                                Total <Money value={order.total_incl_tax} currency={order.currency_code} /> · Payé <Money value={order.paid} currency={order.currency_code} />
                                {hasRemaining && <> · Reste <Money value={order.remaining} currency={order.currency_code} /></>}
                            </p>
                        </div>
                    </div>
                    <button type="button" onClick={onDismiss} aria-label="Fermer" className="shrink-0 text-ink-faint transition-soft hover:text-ink">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                </div>

                {order.requires_replenishment && (
                    <p className="mt-2 rounded-field bg-warning-soft px-2.5 py-1.5 text-[12px] text-warning">
                        Préparation requise pour {Number(order.remote_required)} unité(s).
                    </p>
                )}

                <div className="mt-3 flex flex-wrap gap-2">
                    <button type="button" onClick={onPrint} className="rounded-field border border-line-strong bg-surface px-3 py-1.5 text-[12px] font-medium text-ink transition-soft hover:bg-sage">
                        Imprimer le reçu
                    </button>
                    <Link href={`/sales/orders/${order.id}`} className="rounded-field border border-line-strong bg-surface px-3 py-1.5 text-[12px] font-medium text-ink transition-soft hover:bg-sage">
                        Voir la commande
                    </Link>
                    <button type="button" onClick={onNewSale} className="rounded-field bg-primary px-3 py-1.5 text-[12px] font-semibold text-primary-fg transition-soft hover:bg-primary-hover">
                        Nouvelle vente
                    </button>
                </div>
            </div>
        </div>
    );
}
