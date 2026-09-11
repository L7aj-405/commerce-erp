import type { ReactNode } from 'react';
import type { BadgeTone } from '@/utils/labels';

const tones: Record<BadgeTone, string> = {
    neutral: 'bg-sage text-ink-muted',
    positive: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
    info: 'bg-sage-deep text-ink',
};

/** Subtle, consistent status pill for the Sales / Invoice UI. */
export default function DocBadge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${tones[tone]}`}>
            {children}
        </span>
    );
}
