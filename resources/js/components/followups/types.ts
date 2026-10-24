export type FollowUpPriority = 'low' | 'normal' | 'high';
export type FollowUpStatus = 'open' | 'completed' | 'cancelled';

export type FollowUpItem = {
    id: number;
    title: string;
    note: string | null;
    priority: FollowUpPriority;
    status: FollowUpStatus;
    /** Organization-local calendar date (YYYY-MM-DD). */
    due_date: string | null;
    /** Organization-local time (HH:MM); null for date-only work. */
    due_time: string | null;
    due_at: string | null;
    is_overdue: boolean;
    is_today: boolean;
    assignee: { id: number; name: string } | null;
    customer: { id: number; name: string } | null;
    quotation: { id: number; number: string | null; store_id: number } | null;
    completed_at: string | null;
    cancelled_at: string | null;
    outcome_note: string | null;
    version: number;
    can_manage: boolean;
};

export type FollowUpContext = {
    today: string;
    timezone: string;
    timezone_configured: boolean;
    can_create: boolean;
    can_manage_all: boolean;
    current_user_id: number;
    assignees: { id: number; name: string }[];
};

export type FollowUpPanelData = {
    items: FollowUpItem[];
    context: FollowUpContext;
    prefill: { customer_id: number | null; quotation_id: number | null };
} | null;

/** Calendar arithmetic on YYYY-MM-DD strings, independent of the browser timezone. */
export function addDays(date: string, days: number): string {
    const [y, m, d] = date.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}
