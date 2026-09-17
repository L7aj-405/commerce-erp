import { formatQuantity } from '@/utils/format';

export type SoldLine = { quantity: string; designation: string; reference: string | null; variant: string | null };

/**
 * Every sold line, in full — never a truncated "Article A (+3 autres)"
 * summary. The list grows vertically with the number of lines rather than
 * hiding any of them; that's an accepted trade-off (see the Finance
 * Journal/CA designation-fix audit) since the accountant must be able to
 * identify exactly what was sold. Shared by Journal des ventes and CA
 * encaissé — both render the same authoritative line-snapshot shape.
 */
export default function SoldLines({ lines }: { lines: SoldLine[] }) {
    return (
        <ul className="space-y-1.5">
            {lines.map((line, index) => (
                <li key={index} className="text-[13px] text-ink">
                    <span className="tabular-nums font-medium">{formatQuantity(line.quantity)}</span> × {line.designation}
                    {line.variant ? ` — ${line.variant}` : ''}
                    {line.reference && <span className="block text-[11px] text-ink-faint">Réf. {line.reference}</span>}
                </li>
            ))}
        </ul>
    );
}
