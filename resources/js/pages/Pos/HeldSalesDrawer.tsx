import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import { Sheet } from '@/components/ui/Sheet';
import { useI18n } from '@/i18n';
import { formatDateTime, formatInteger, formatMoney } from '@/utils/format';
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

/** Held (parked) sales, on the shared Sheet: Escape/backdrop close, focus trapped and restored. */
export default function HeldSalesDrawer({ open, sales, resumingId, deletingId, onClose, onResume, onDelete }: Props) {
    const { t } = useI18n();

    return (
        <Sheet open={open} onClose={onClose} side="right" title={`${t('pos.heldSales')} (${sales.length})`} description={t('pos.heldSalesHelp')}>
            <div className="space-y-3 p-4">
                {sales.length === 0 && <EmptyState compact title={t('pos.noHeldSales')} />}
                {sales.map((sale) => {
                    const resuming = resumingId(sale.id);
                    const deleting = deletingId(sale.id);
                    return (
                        <article key={sale.id} className={`surface-card p-4 transition-opacity ${deleting ? 'opacity-50' : ''}`} aria-busy={resuming || deleting || undefined}>
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-semibold text-ink">
                                        <bdi>{sale.order_number}</bdi>
                                    </p>
                                    <p className="text-[13px] text-ink-muted">{sale.held_at ? formatDateTime(sale.held_at) : t('pos.pending')}</p>
                                </div>
                                <p className="metric-value shrink-0 text-base font-semibold text-ink">{formatMoney(sale.subtotal, sale.currency_code)}</p>
                            </div>
                            <dl className="mt-3 space-y-0.5 text-sm text-ink-muted">
                                <div>
                                    {formatInteger(sale.product_count)} {t('pos.products')}
                                </div>
                                {sale.customer_name && (
                                    <div>
                                        {t('pos.customer')} : <span className="font-medium text-ink">{sale.customer_name}</span>
                                    </div>
                                )}
                                {sale.warehouse && (
                                    <div>
                                        {t('pos.warehouse')} : {sale.warehouse.name}
                                    </div>
                                )}
                            </dl>
                            <div className="mt-4 flex gap-2">
                                <Button type="button" disabled={deleting} loading={resuming} loadingText={t('pos.resuming')} onClick={() => onResume(sale)} className="flex-1">
                                    {t('pos.resume')}
                                </Button>
                                <Button type="button" variant="danger" disabled={resuming} loading={deleting} onClick={() => onDelete(sale)}>
                                    {t('pos.delete')}
                                </Button>
                            </div>
                        </article>
                    );
                })}
            </div>
        </Sheet>
    );
}
