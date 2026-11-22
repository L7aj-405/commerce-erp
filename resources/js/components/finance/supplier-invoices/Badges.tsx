import { Badge } from '@/components/ui/StatusBadge';
import type { StatusVariant } from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import { interpolate } from './math';
import type { SupplierInvoiceMatchFlag, SupplierInvoiceMatching, SupplierInvoiceMatchStatus, SupplierInvoiceStatus } from './types';

const statusVariant: Record<SupplierInvoiceStatus, StatusVariant> = { draft: 'neutral', approved: 'success', void: 'danger' };
const matchVariant: Record<SupplierInvoiceMatchStatus, StatusVariant> = { no_po: 'neutral', po_partial: 'warning', po_matched: 'success', variance: 'warning' };

export function SupplierInvoiceStatusBadge({ status }: { status: SupplierInvoiceStatus }) {
    const { t } = useI18n();
    return <Badge variant={statusVariant[status] ?? 'neutral'}>{t(`supplierInvoice.status.${status}` as TranslationKey)}</Badge>;
}

export function MatchingStatusBadge({ status }: { status: SupplierInvoiceMatchStatus | null | undefined }) {
    const { t } = useI18n();
    if (!status) return <span className="text-ink-faint">—</span>;
    return <Badge variant={matchVariant[status] ?? 'neutral'}>{t(`supplierInvoice.match.${status}` as TranslationKey)}</Badge>;
}

/**
 * Live PO matching result. Advisory only: it never blocks saving. Line flags are
 * listed per line (by display position) so each is readable without color.
 * `positions` maps line_id -> displayed line number.
 */
export function MatchingWarnings({ matching, positions }: { matching: SupplierInvoiceMatching; positions: Record<number, number> }) {
    const { t } = useI18n();
    const flagged = matching.lines.filter((line) => line.flags.length > 0);
    return (
        <section aria-labelledby="si-matching-title" className="rounded-card border border-line bg-surface p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 id="si-matching-title" className="text-sm font-semibold text-ink">
                    {t('supplierInvoice.match.title')}
                </h3>
                <MatchingStatusBadge status={matching.status} />
            </div>
            <p className="mt-1 text-[13px] text-ink-muted">{t('supplierInvoice.match.advisory')}</p>
            {matching.status !== 'no_po' &&
                (flagged.length === 0 ? (
                    <p className="mt-3 text-sm text-ink-muted">{t('supplierInvoice.match.noFlags')}</p>
                ) : (
                    <ul className="mt-3 space-y-2">
                        {flagged.map((line) => (
                            <li key={line.line_id} className="text-sm">
                                <span className="font-semibold text-ink">{interpolate(t('supplierInvoice.match.line'), { n: positions[line.line_id] ?? line.line_id })}</span>
                                <ul className="mt-1 list-disc ps-5 text-ink-muted">
                                    {line.flags.map((flag: SupplierInvoiceMatchFlag) => (
                                        <li key={flag}>{t(`supplierInvoice.match.flag.${flag}` as TranslationKey)}</li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                ))}
        </section>
    );
}
