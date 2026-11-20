import InfoTip from '@/components/ui/InfoTip';
import { useI18n } from '@/i18n';

export type Reliability = 'estimated' | 'incomplete';

/**
 * Reliability state of a displayed figure (U-1). Only render it from a REAL
 * condition (hidden figure, estimate that is not final yet) -- never decoratively.
 * The explanation is mandatory so the label is never a bare warning.
 */
export default function ReliabilityBadge({ state, explanation }: { state: Reliability; explanation: string }) {
    const { t } = useI18n();
    const label = state === 'estimated' ? t('reliability.estimated') : t('reliability.incomplete');

    return (
        <span className="inline-flex items-center gap-0.5">
            <span className="rounded-full border border-warning/40 bg-warning-soft px-2 py-0.5 text-[11px] font-semibold text-warning">{label}</span>
            <InfoTip label={`${label} : ${t('reliability.why')}`}>{explanation}</InfoTip>
        </span>
    );
}
