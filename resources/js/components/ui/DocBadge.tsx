import type { ReactNode } from 'react';
import type { BadgeTone } from '@/utils/labels';
import { Badge, type StatusVariant } from './StatusBadge';

const tones: Record<BadgeTone, StatusVariant> = {
    neutral: 'neutral',
    positive: 'success',
    warning: 'warning',
    danger: 'danger',
    info: 'info',
};

/** Document status pill (Sales / Invoice UI) — same visual language as StatusBadge. */
export default function DocBadge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
    return <Badge variant={tones[tone]}>{children}</Badge>;
}
