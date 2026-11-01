import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { useI18n } from '@/i18n';
import { SourcingPanel, type SourcingTransport } from '@/pages/Quotations/Sourcing';
import type { ActiveSale } from './types';

type Props = {
    open: boolean;
    sale: ActiveSale;
    transport: SourcingTransport;
    can: { request: boolean; record: boolean; convert: boolean };
    converting: boolean;
    onConvert: () => void;
    onClose: () => void;
};

/**
 * "Articles à sourcer" — the one POS sourcing modal. It lists every cart line
 * current stock no longer covers and reuses the Devis sourcing UI as-is
 * (SourcingPanel → supplier request dialog with recommendations → supplier
 * answer dialog) over the cart's JSON routes. Every figure and state comes from
 * the server (PosCartSourcing); after a request or an answer the cart is
 * re-synced and the operator stays here to continue with the next article.
 */
export default function PosSourcingModal({ open, sale, transport, can, converting, onConvert, onClose }: Props) {
    const { t } = useI18n();
    const shortages = sale.lines
        .map((line) => line.sourcing)
        .filter((sourcing): sourcing is NonNullable<typeof sourcing> => Boolean(sourcing?.requires_sourcing));
    const summary = sale.sourcing_summary;
    const unresolved = summary?.unresolved_line_count ?? 0;

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={t('pos.sourcingTitle')}
            description={t('pos.sourcingHelp')}
            footer={
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p className={`text-xs ${unresolved > 0 ? 'text-warning' : 'text-success'}`} aria-live="polite">
                        {unresolved > 0
                            ? t('pos.sourcingRemaining').replace('{count}', String(unresolved))
                            : t('pos.sourcingReady')}
                    </p>
                    <div className="flex shrink-0 justify-end gap-2">
                        <Button variant="secondary" onClick={onClose}>
                            {t('common.close')}
                        </Button>
                        {can.convert && (
                            <Button onClick={onConvert} loading={converting} disabled={!summary?.can_convert}>
                                {t('pos.createOrder')}
                            </Button>
                        )}
                    </div>
                </div>
            }
        >
            <div className="max-h-[60vh] space-y-3 overflow-y-auto px-4 py-4 sm:px-6">
                {shortages.length === 0 && <p className="text-sm text-ink-muted">{t('sourcing.inStockAvailable')}</p>}
                {shortages.map((sourcing) => (
                    <div key={sourcing.line_id} className="space-y-1.5">
                        <p className="text-sm font-semibold text-ink">
                            {sourcing.article}
                            {sourcing.reference && <span className="ms-2 text-xs font-normal text-ink-faint">{sourcing.reference}</span>}
                        </p>
                        <SourcingPanel
                            quotationId={sale.id}
                            sourcing={sourcing}
                            can={{ request: can.request, record: can.record }}
                            transport={transport}
                            createLabel={t('pos.askSupplier')}
                        />
                    </div>
                ))}
            </div>
        </Dialog>
    );
}
