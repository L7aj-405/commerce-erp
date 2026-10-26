import { useI18n } from '@/i18n';

/** One team member's (or Unassigned) workload, as computed server-side by TeamWorkQuery. */
export type TeamRow = {
    id: number;
    name: string;
    active: boolean;
    open: number;
    overdue: number;
    today: number;
    upcoming: number;
    completed_recent: number;
    attention: number;
    critical: number;
    escalated: number;
    warning: number;
};

/** Compact workload chips; zeros stay quiet, late / critical work stands out. */
export function TeamCounts({ row, showAttention }: { row: TeamRow; showAttention: boolean }) {
    const { t } = useI18n();
    const chips: { key: string; label: string; value: number; tone: string }[] = [
        { key: 'open', label: t('team.colOpen'), value: row.open, tone: 'text-ink' },
        { key: 'overdue', label: t('team.colOverdue'), value: row.overdue, tone: 'text-warning' },
        { key: 'today', label: t('team.colToday'), value: row.today, tone: 'text-ink' },
        { key: 'upcoming', label: t('team.colUpcoming'), value: row.upcoming, tone: 'text-ink' },
        { key: 'completed', label: t('team.colCompleted'), value: row.completed_recent, tone: 'text-success' },
        ...(showAttention ? [
            { key: 'critical', label: t('team.colCritical'), value: row.critical, tone: 'text-danger' },
            { key: 'escalated', label: t('team.colEscalated'), value: row.escalated, tone: 'text-danger' },
        ] : []),
    ];

    return (
        <span className="flex flex-wrap gap-1.5">
            {chips.map((chip) => (
                <span key={chip.key} className={`inline-flex items-center gap-1 rounded-full border border-line px-2 py-0.5 text-[11px] ${chip.value > 0 ? 'bg-raised' : 'opacity-60'}`}>
                    <span className="text-ink-muted">{chip.label}</span>
                    <span className={`font-semibold tabular-nums ${chip.value > 0 ? chip.tone : 'text-ink-faint'}`} dir="ltr">{chip.value}</span>
                </span>
            ))}
        </span>
    );
}
