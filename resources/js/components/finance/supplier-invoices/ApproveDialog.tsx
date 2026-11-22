import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Checkbox, TextAreaField, TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { formatMoney } from '@/utils/format';
import { useState } from 'react';
import { MatchingStatusBadge } from './Badges';
import { approvalProblems, canApprove } from './rules';
import { SoftWarningList } from './SoftWarningList';
import type { SupplierInvoiceMatchStatus, SupplierInvoiceWarning } from './types';

export type ApprovePayload = {
    control_total_ttc: string;
    acknowledge_unmatched: boolean;
    match_note: string;
    acknowledged_warnings: string[];
};

/**
 * Approval confirmation. Requires an exact control total, an acknowledgement + note
 * when the PO match is not `po_matched`, and every soft warning ticked (sent as `acknowledged_warnings` codes).
 * `errors` are server validation messages keyed by payload field. Mount it only
 * while open (or pass a changing `key`) so internal state resets between uses.
 */
export function ApproveDialog({
    open,
    onClose,
    onConfirm,
    totalTtc,
    currencyCode,
    initialControlTotal = '',
    matchStatus,
    warnings,
    processing = false,
    errors = {},
}: {
    open: boolean;
    onClose: () => void;
    onConfirm: (payload: ApprovePayload) => void;
    totalTtc: string;
    currencyCode: string;
    initialControlTotal?: string | null;
    matchStatus: SupplierInvoiceMatchStatus;
    warnings: SupplierInvoiceWarning[];
    processing?: boolean;
    errors?: Partial<Record<keyof ApprovePayload, string>>;
}) {
    const { t } = useI18n();
    const [controlTotal, setControlTotal] = useState(initialControlTotal ?? '');
    const [ackUnmatched, setAckUnmatched] = useState(false);
    const [note, setNote] = useState('');
    const [ackWarnings, setAckWarnings] = useState<string[]>([]);
    const [touched, setTouched] = useState(false);

    const input = { controlTotal, totalTtc, matchStatus, acknowledgeUnmatched: ackUnmatched, matchNote: note, warningCodes: warnings.map((warning) => warning.code), acknowledgedWarnings: ackWarnings };
    const problems = approvalProblems(input);
    const needsMatchAck = matchStatus !== 'po_matched';

    const submit = () => {
        setTouched(true);
        if (!canApprove(input)) return;
        onConfirm({ control_total_ttc: controlTotal.trim(), acknowledge_unmatched: ackUnmatched, match_note: note.trim(), acknowledged_warnings: ackWarnings });
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={t('supplierInvoice.approve.title')}
            description={t('supplierInvoice.approve.description')}
            size="md"
            dismissible={!processing}
            footer={
                <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose} disabled={processing}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="button" onClick={submit} loading={processing} loadingText={t('supplierInvoice.approve.confirming')} disabled={touched && !canApprove(input)}>
                        {t('supplierInvoice.approve.confirm')}
                    </Button>
                </div>
            }
        >
            <div className="space-y-4 px-4 py-4 sm:px-6">
                <div className="flex items-baseline justify-between gap-3 text-sm">
                    <span className="text-ink-muted">{t('supplierInvoice.totals.ttc')}</span>
                    <span className="text-base font-bold tabular-nums text-ink">{formatMoney(totalTtc, currencyCode)}</span>
                </div>
                <TextField
                    label={t('supplierInvoice.approve.controlTotal')}
                    inputMode="decimal"
                    dir="ltr"
                    required
                    value={controlTotal}
                    onChange={(event) => setControlTotal(event.target.value)}
                    error={errors.control_total_ttc ?? (touched && problems.controlTotal ? t('supplierInvoice.approve.controlMismatch') : undefined)}
                />
                {needsMatchAck && (
                    <div className="space-y-3 rounded-card border border-warning/35 bg-warning-soft p-3">
                        <div className="flex items-center gap-2 text-sm">
                            <MatchingStatusBadge status={matchStatus} />
                        </div>
                        <Checkbox label={t('supplierInvoice.approve.matchAck')} checked={ackUnmatched} onChange={(event) => setAckUnmatched(event.target.checked)} />
                        {errors.acknowledge_unmatched && <p className="text-[13px] font-medium text-danger">{errors.acknowledge_unmatched}</p>}
                        <TextAreaField
                            label={t('supplierInvoice.approve.matchNote')}
                            required
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            error={errors.match_note ?? (touched && problems.matchNote ? t('supplierInvoice.approve.matchNoteRequired') : undefined)}
                        />
                    </div>
                )}
                <SoftWarningList warnings={warnings} acknowledged={ackWarnings} onAcknowledgedChange={setAckWarnings} error={errors.acknowledged_warnings} />
            </div>
        </Dialog>
    );
}
