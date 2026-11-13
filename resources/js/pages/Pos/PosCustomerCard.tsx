import { Button, IconButton } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { useI18n } from '@/i18n';
import type { Customer } from './types';

/**
 * Always-visible customer summary for the sale. `compact` sits in the cart
 * footer next to the totals; the full variant heads the checkout panel.
 * A sale without a customer is a valid walk-in ("Vente au comptoir").
 */
export default function PosCustomerCard({
    customer,
    compact = false,
    attaching,
    canUpdate,
    recommended = false,
    onOpen,
    onEdit,
    onRemove,
}: {
    customer: Customer | null;
    compact?: boolean;
    attaching: boolean;
    canUpdate: boolean;
    /** Show the non-blocking "a customer is advised" hint (partial/deferred/delivery). */
    recommended?: boolean;
    onOpen: () => void;
    onEdit: () => void;
    onRemove: () => void;
}) {
    const { t } = useI18n();
    const details = customer ? [customer.company_name !== customer.display_name ? customer.company_name : null, customer.phone, customer.email].filter(Boolean).join(' · ') : '';

    if (compact) {
        return (
            <div className="mb-3 flex flex-wrap items-center gap-x-2.5 gap-y-2 rounded-field border border-line bg-surface px-3 py-2">
                <div className="flex min-w-[10rem] flex-1 items-center gap-2.5">
                    <Avatar customer={customer} />
                    <div className="min-w-0 flex-1">
                        <p className="text-[12px] font-medium text-ink-muted">{t('common.customer')}</p>
                        {/* Wraps (max 2 lines) rather than truncating at intermediate widths. */}
                        <p className="line-clamp-2 text-sm leading-5 font-semibold break-words text-ink">{customer?.display_name ?? t('pos.walkInCustomer')}</p>
                    </div>
                </div>
                {attaching && <Spinner size="sm" className="text-ink-muted" />}
                <Button size="sm" variant={customer ? 'secondary' : 'outline'} onClick={onOpen} disabled={attaching} className="ms-auto">
                    {customer ? t('pos.changeCustomer') : t('pos.selectCustomer')}
                </Button>
            </div>
        );
    }

    return (
        <section aria-labelledby="pos-customer-heading" className="border-b border-line px-4 py-3.5">
            <div className="mb-2 flex items-center justify-between gap-2">
                <h3 id="pos-customer-heading" className="text-[13px] font-semibold text-ink-muted">
                    {t('common.customer')} <span className="font-normal">({t('pos.customerOptional')})</span>
                </h3>
                {attaching && <Spinner size="sm" className="text-ink-muted" />}
            </div>
            {customer ? (
                <div className="flex items-center gap-3 rounded-field border border-line bg-raised p-3">
                    <Avatar customer={customer} />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-ink">{customer.display_name}</p>
                        <p className="truncate text-[13px] text-ink-muted">{details || t('pos.counterCustomerFallback')}</p>
                    </div>
                    <div className="flex shrink-0 items-center gap-1">
                        <Button size="sm" variant="secondary" onClick={onOpen} disabled={attaching}>
                            {t('pos.changeCustomer')}
                        </Button>
                        {canUpdate && (
                            <IconButton label={t('pos.editCustomerTitle')} size="sm" onClick={onEdit} disabled={attaching}>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4Z" />
                                </svg>
                            </IconButton>
                        )}
                        <IconButton label={t('pos.removeCustomer')} size="sm" onClick={onRemove} disabled={attaching} className="hover:text-danger">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                                <path d="M18 6 6 18M6 6l12 12" />
                            </svg>
                        </IconButton>
                    </div>
                </div>
            ) : (
                <>
                    <Button variant="outline" block onClick={onOpen} disabled={attaching} className="min-h-11">
                        <svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round">
                            <circle cx="10" cy="8" r="3.5" />
                            <path d="M3.5 20c0-3.3 2.9-5.5 6.5-5.5 1.2 0 2.3.2 3.3.7M18 14v6M15 17h6" />
                        </svg>
                        {t('pos.selectCustomer')}
                    </Button>
                    <p className={`mt-2 text-[13px] leading-5 ${recommended ? 'font-medium text-warning' : 'text-ink-muted'}`}>
                        {recommended ? t('pos.customerRecommended') : t('pos.walkInHelp')}
                    </p>
                </>
            )}
        </section>
    );
}

function Avatar({ customer }: { customer: Customer | null }) {
    return (
        <span aria-hidden="true" className="grid size-9 shrink-0 place-items-center rounded-full bg-sage-deep text-sm font-semibold text-ink">
            {customer ? customer.display_name.trim().charAt(0).toUpperCase() || '?' : (
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9">
                    <circle cx="12" cy="8" r="3.5" />
                    <path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6" />
                </svg>
            )}
        </span>
    );
}
