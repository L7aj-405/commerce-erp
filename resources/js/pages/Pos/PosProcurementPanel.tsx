import { Quantity } from '@/components/pos/primitives';
import { Spinner } from '@/components/ui/Spinner';
import { useState } from 'react';
import type { ActiveSale, CartLine, Supplier } from './types';

export type ProcurementSubmit = {
    sales_order_line_id: number;
    supplier_id: number;
    quantity: string;
    supplier_availability_status: 'pending_confirmation' | 'confirmed_available' | 'unavailable';
    supplier_reference?: string;
    notes?: string;
};

type Props = {
    sale: ActiveSale;
    suppliers: Supplier[];
    busy: boolean;
    onSubmit: (payload: ProcurementSubmit) => Promise<void>;
    onClose: () => void;
};

/**
 * Compact POS supplier-sourcing panel. It drives the EXISTING procurement
 * endpoints (create + record availability) — POS and the back-office
 * "APPROVISIONNEMENT FOURNISSEUR" panel act on the same SalesOrderProcurement
 * rows. Only lines that company stock cannot fully cover are listed.
 */
export default function PosProcurementPanel({ sale, suppliers, busy, onSubmit, onClose }: Props) {
    const deficitLines = sale.lines.filter(
        (line) => line.line_type === 'catalog' && Number(line.to_procure ?? '0') > 0,
    );

    if (suppliers.length === 0) {
        return (
            <div className="border-t border-line bg-warning-soft/40 p-4 text-[12px] text-warning">
                Aucun fournisseur actif. Ajoutez-en un dans Achats → Fournisseurs avant d’approvisionner.
                <button type="button" onClick={onClose} className="ml-2 underline">
                    Fermer
                </button>
            </div>
        );
    }

    return (
        <div className="border-t border-line bg-raised">
            <div className="flex items-center justify-between px-4 pt-3">
                <h3 className="text-[12px] font-semibold uppercase tracking-wide text-ink-muted">Approvisionnement fournisseur</h3>
                <button type="button" onClick={onClose} className="text-[12px] text-ink-muted hover:text-ink">
                    Fermer
                </button>
            </div>
            <ul className="max-h-72 divide-y divide-line overflow-y-auto px-2 py-2">
                {deficitLines.map((line) => (
                    <li key={line.id} className="p-2">
                        <ProcurementRow line={line} suppliers={suppliers} busy={busy} onSubmit={onSubmit} />
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ProcurementRow({
    line,
    suppliers,
    busy,
    onSubmit,
}: {
    line: CartLine;
    suppliers: Supplier[];
    busy: boolean;
    onSubmit: (payload: ProcurementSubmit) => Promise<void>;
}) {
    const toProcure = line.to_procure ?? '0';
    const existing = line.procurement ?? null;
    const [supplierId, setSupplierId] = useState<number>(existing?.supplier?.id ?? suppliers[0]?.id ?? 0);
    const [availability, setAvailability] = useState<ProcurementSubmit['supplier_availability_status']>(
        (existing?.supplier_availability_status as ProcurementSubmit['supplier_availability_status']) ?? 'confirmed_available',
    );
    const [qty, setQty] = useState<string>(existing?.quantity ?? toProcure);
    const [reference, setReference] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const submit = async () => {
        setSubmitting(true);
        try {
            await onSubmit({
                sales_order_line_id: line.id,
                supplier_id: supplierId,
                quantity: qty || toProcure,
                supplier_availability_status: availability,
                supplier_reference: reference || undefined,
            });
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="text-[12px]">
            <div className="flex items-center justify-between gap-2">
                <span className="truncate font-semibold text-ink">{line.description}</span>
                {existing && (
                    <span className="shrink-0 rounded-full bg-sage px-1.5 py-0.5 text-[10px] font-medium text-ink-muted">
                        {existing.procurement_number} · {existing.status_label}
                    </span>
                )}
            </div>
            <p className="mt-0.5 text-ink-muted">
                Demandé <Quantity value={line.quantity} /> · Couvert société <Quantity value={line.company_covered ?? '0'} /> ·
                À approvisionner <strong className="text-ink"><Quantity value={toProcure} /></strong>
            </p>
            <div className="mt-1.5 grid grid-cols-2 gap-1.5">
                <label className="col-span-2">
                    <span className="text-ink-faint">Fournisseur</span>
                    <select
                        value={supplierId}
                        onChange={(e) => setSupplierId(Number(e.target.value))}
                        className="mt-0.5 w-full rounded-field border border-line-strong bg-surface px-2 py-1 text-[12px] outline-none focus:border-primary"
                    >
                        {suppliers.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    <span className="text-ink-faint">Disponibilité</span>
                    <select
                        value={availability}
                        onChange={(e) => setAvailability(e.target.value as ProcurementSubmit['supplier_availability_status'])}
                        className="mt-0.5 w-full rounded-field border border-line-strong bg-surface px-2 py-1 text-[12px] outline-none focus:border-primary"
                    >
                        <option value="confirmed_available">Confirmée</option>
                        <option value="pending_confirmation">En attente</option>
                        <option value="unavailable">Indisponible</option>
                    </select>
                </label>
                <label>
                    <span className="text-ink-faint">Quantité confirmée</span>
                    <input
                        inputMode="decimal"
                        value={qty}
                        onChange={(e) => setQty(e.target.value)}
                        className="mt-0.5 w-full rounded-field border border-line-strong bg-surface px-2 py-1 text-[12px] outline-none focus:border-primary"
                    />
                </label>
                <label className="col-span-2">
                    <span className="text-ink-faint">Référence fournisseur (opt.)</span>
                    <input
                        value={reference}
                        onChange={(e) => setReference(e.target.value)}
                        className="mt-0.5 w-full rounded-field border border-line-strong bg-surface px-2 py-1 text-[12px] outline-none focus:border-primary"
                    />
                </label>
            </div>
            <button
                type="button"
                disabled={submitting || busy || !supplierId}
                onClick={() => void submit()}
                className="mt-2 inline-flex items-center gap-1.5 rounded-field bg-primary px-3 py-1.5 text-[12px] font-medium text-primary-fg transition-soft hover:bg-primary-hover disabled:opacity-50"
            >
                {(submitting || busy) && <Spinner size="xs" />}
                {existing ? 'Mettre à jour' : 'Enregistrer'}
            </button>
        </div>
    );
}
