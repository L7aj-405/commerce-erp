import DocBadge from '@/components/ui/DocBadge';
import { useI18n } from '@/i18n';
import { fr, type TranslationKey } from '@/i18n/translations';
import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

type Category = 'documents' | 'follow_ups' | 'payments' | 'attention';
type TimelineEntry = {
    id: string;
    /** UTC instant (ISO 8601). */
    occurred_at: string;
    category: Category;
    event: string;
    /** A person, or "system" when nobody acted (never a guessed user). */
    actor: { type: 'user'; name: string; channel?: 'mcp' } | { type: 'system' };
    params: Record<string, string | number | boolean>;
    subject: { type: string; id: number; label: string | null } | null;
    link: { method: 'get' | 'post'; href: string } | null;
};
type TimelinePage = { entries: TimelineEntry[]; next_cursor: string | null; timezone: string };

const isKey = (value: string): value is TranslationKey => Object.prototype.hasOwnProperty.call(fr, value);
const dot: Record<Category, string> = { documents: 'bg-primary', follow_ups: 'bg-info', payments: 'bg-success', attention: 'bg-warning' };
const attentionTypes: Record<string, TranslationKey> = {
    quotation_sent_undecided: 'attention.typeSent', quotation_expiring_soon: 'attention.typeExpiring', quotation_expired_undecided: 'attention.typeExpired',
    quotation_followup_backlog: 'attention.typeBacklog', sales_order_payment_due_soon: 'attention.typeOrderDueSoon', sales_order_unpaid_overdue: 'attention.typeOrderOverdue',
    sales_order_partial_overdue: 'attention.typeOrderPartial', sales_order_payment_followup_backlog: 'attention.typeOrderBacklog', sales_order_paid_not_invoiced: 'attention.typeOrderPaidNotInvoiced',
    invoice_due_soon: 'attention.typeInvoiceDueSoon', invoice_overdue_unpaid: 'attention.typeInvoiceOverdue', invoice_overdue_partial: 'attention.typeInvoicePartial',
    invoice_payment_followup_backlog: 'attention.typeInvoiceBacklog',
};
const documentLabels: Record<string, TranslationKey> = { quotation: 'timeline.docQuotation', sales_order: 'timeline.docOrder', invoice: 'timeline.docInvoice' };

/**
 * W5 business activity timeline (customer, quotation, sales order, invoice). The server decides what the
 * viewer may see and returns translated-key events; this component only renders and pages (cursor).
 */
export default function ActivityTimeline({ subject, id }: { subject: 'customer' | 'quotation' | 'sales_order' | 'invoice'; id: number }) {
    const { t, locale } = useI18n();
    const [entries, setEntries] = useState<TimelineEntry[]>([]);
    const [cursor, setCursor] = useState<string | null>(null);
    const [timezone, setTimezone] = useState('UTC');
    const [category, setCategory] = useState<Category | null>(null);
    const [state, setState] = useState<'loading' | 'ready' | 'error' | 'hidden'>('loading');
    const [loadingMore, setLoadingMore] = useState(false);

    const load = useCallback(async (next: string | null, filter: Category | null) => {
        const query = new URLSearchParams({ ...(filter ? { category: filter } : {}), ...(next ? { cursor: next } : {}) });
        const response = await fetch(`/timeline/${subject}/${id}?${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (response.status === 403 || response.status === 404) return 'hidden' as const;
        if (!response.ok) return 'error' as const;
        return await response.json() as TimelinePage;
    }, [subject, id]);

    useEffect(() => {
        let cancelled = false;
        setState('loading');
        load(null, category).then((page) => {
            if (cancelled) return;
            if (typeof page === 'string') return setState(page);
            setEntries(page.entries);
            setCursor(page.next_cursor);
            setTimezone(page.timezone);
            setState('ready');
        }).catch(() => !cancelled && setState('error'));
        return () => { cancelled = true; };
    }, [load, category]);

    const more = async () => {
        if (!cursor) return;
        setLoadingMore(true);
        try {
            const page = await load(cursor, category);
            if (typeof page === 'string') return setState(page);
            setEntries((current) => [...current, ...page.entries]);
            setCursor(page.next_cursor);
        } catch {
            setState('error');
        } finally {
            setLoadingMore(false);
        }
    };

    if (state === 'hidden') return null;

    const intl = locale === 'ar' ? 'ar-MA' : 'fr-FR';
    const when = (iso: string) => new Intl.DateTimeFormat(intl, { timeZone: timezone, dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
    const relative = (iso: string) => {
        const minutes = Math.round((new Date(iso).getTime() - Date.now()) / 60000);
        const format = new Intl.RelativeTimeFormat(intl, { numeric: 'auto' });
        if (Math.abs(minutes) < 60) return format.format(minutes, 'minute');
        if (Math.abs(minutes) < 24 * 60) return format.format(Math.round(minutes / 60), 'hour');
        if (Math.abs(minutes) < 7 * 24 * 60) return format.format(Math.round(minutes / 1440), 'day');
        return when(iso);
    };
    const day = (date: string) => new Intl.DateTimeFormat(intl, { timeZone: 'UTC', dateStyle: 'medium' }).format(new Date(`${date}T00:00:00Z`));
    const money = (amount: string, currency: string) => {
        try {
            return new Intl.NumberFormat(intl, { style: 'currency', currency }).format(Number(amount));
        } catch {
            return `${amount} ${currency}`;
        }
    };
    const text = (key: string, params: Record<string, string> = {}) => (isKey(key) ? t(key) : key).replace(/\{(\w+)\}/g, (_, name: string) => params[name] ?? '');

    const describe = (entry: TimelineEntry): { label: string; context: string[]; chip: string | null } => {
        const p = entry.params;
        const number = typeof p.number === 'string' ? p.number : t('timeline.draft');
        const context: string[] = [];
        if (p.assignee !== undefined || ['follow_up.reassigned', 'attention.reassigned', 'attention.claimed'].includes(entry.event)) {
            context.push(text('timeline.assignedTo', { name: typeof p.assignee === 'string' ? p.assignee : t('timeline.unassigned') }));
        }
        if (entry.event === 'follow_up.rescheduled') {
            const due = typeof p.due_at === 'string' ? when(p.due_at) : typeof p.due_date === 'string' ? day(p.due_date) : t('work.noDueDate');
            context.push(text('timeline.rescheduledTo', { date: due }));
        }
        if (p.recurring) context.push(t('timeline.recurring'));
        if (typeof p.reason === 'string') context.push(text('timeline.reason', { reason: p.reason }));
        if (typeof p.source_number === 'string') context.push(text('timeline.fromRevision', { number: p.source_number }));
        if (typeof p.order_number === 'string') context.push(text('timeline.toOrder', { number: p.order_number }));
        if (typeof p.original_number === 'string') context.push(text('timeline.replaces', { number: p.original_number }));
        if (typeof p.until === 'string') context.push(text('timeline.snoozedUntil', { date: when(p.until) }));
        if (typeof p.name === 'string') context.push(p.name);
        if (entry.category === 'payments') {
            const reference = typeof p.payment_number === 'string' ? p.payment_number : typeof p.refund_number === 'string' ? p.refund_number : null;
            if (reference) context.push(reference);
            if (typeof p.amount === 'string' && typeof p.currency === 'string') context.push(money(p.amount, p.currency));
        }
        if (entry.event.startsWith('email.')) {
            context.push(typeof p.recipient === 'string' ? p.recipient : text('timeline.recipients', { count: String(p.recipients ?? 0) }));
        }

        let chip: string | null = null;
        if (entry.subject?.type === 'follow_up') chip = entry.subject.label ?? t('timeline.followUp');
        else if (entry.subject?.type === 'attention') chip = typeof p.attention_type === 'string' && attentionTypes[p.attention_type] ? t(attentionTypes[p.attention_type]) : t('timeline.attention');
        else if (entry.link && entry.subject && documentLabels[entry.subject.type]) chip = `${t(documentLabels[entry.subject.type])} ${number}`;
        else if (entry.link && typeof p.order_number === 'string') chip = `${t('timeline.docOrder')} ${p.order_number}`;

        return { label: text(`timeline.ev.${entry.event}`), context, chip };
    };

    const filters: [Category | null, string][] = [
        [null, t('timeline.filterAll')], ['documents', t('timeline.filterDocuments')], ['follow_ups', t('timeline.filterFollowUps')],
        ['payments', t('timeline.filterPayments')], ['attention', t('timeline.filterAttention')],
    ];

    return (
        <section className="rounded-card border border-line bg-surface p-5 shadow-soft">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold text-ink">{t('timeline.title')}</h2>
                    <p className="text-xs text-ink-muted">{t('timeline.help')}</p>
                </div>
                <div className="flex flex-wrap gap-1" role="group" aria-label={t('timeline.title')}>
                    {filters.map(([key, label]) => (
                        <button key={key ?? 'all'} type="button" onClick={() => setCategory(key)} aria-pressed={category === key}
                            className={`rounded-full px-2.5 py-1 text-xs font-medium transition-soft ${category === key ? 'bg-sage text-ink' : 'text-ink-muted hover:bg-sage/60 hover:text-ink'}`}>
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            {state === 'loading' && <p className="py-4 text-center text-sm text-ink-muted">{t('timeline.loading')}</p>}
            {state === 'error' && <p className="py-4 text-center text-sm text-danger">{t('timeline.error')}</p>}
            {state === 'ready' && entries.length === 0 && <p className="rounded-field border border-dashed border-line px-3 py-4 text-center text-sm text-ink-muted">{t('timeline.empty')}</p>}
            {state === 'ready' && entries.length > 0 && (
                <ol className="relative border-s border-line ps-5">
                    {entries.map((entry) => {
                        const { label, context, chip } = describe(entry);
                        const system = entry.actor.type === 'system';
                        return (
                            <li key={entry.id} className="relative pb-4 last:pb-0">
                                <span aria-hidden="true" className={`absolute -start-[1.6rem] top-1.5 size-2.5 rounded-full ring-2 ring-surface ${system ? 'bg-line-strong' : dot[entry.category]}`} />
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium text-ink">{label}</span>
                                    {typeof entry.params.severity === 'string' && entry.category === 'attention' && entry.params.severity !== 'info' && (
                                        <DocBadge tone={entry.params.severity === 'critical' ? 'danger' : 'warning'}>
                                            {t(entry.params.severity === 'critical' ? 'attention.severityCritical' : 'attention.severityWarning')}
                                        </DocBadge>
                                    )}
                                    {chip && entry.link?.method === 'get' && <Link href={entry.link.href} className="text-xs text-primary hover:underline">{chip}</Link>}
                                    {chip && entry.link?.method === 'post' && (
                                        <button type="button" onClick={() => entry.link && router.post(entry.link.href)} className="text-xs text-primary hover:underline">{chip}</button>
                                    )}
                                    {chip && !entry.link && <span className="text-xs text-ink-muted">{chip}</span>}
                                </div>
                                {context.length > 0 && <p className="mt-0.5 text-[13px] text-ink-muted">{context.join(' · ')}</p>}
                                <p className="mt-0.5 text-[12px] text-ink-faint">
                                    {entry.actor.type === 'user'
                                        ? text(entry.actor.channel === 'mcp' ? 'timeline.byViaMcp' : 'timeline.by', { name: entry.actor.name })
                                        : t('timeline.system')}
                                    {' · '}
                                    <time dateTime={entry.occurred_at} title={when(entry.occurred_at)}>{relative(entry.occurred_at)}</time>
                                </p>
                            </li>
                        );
                    })}
                </ol>
            )}
            {state === 'ready' && cursor && (
                <div className="mt-3 text-center">
                    <button type="button" disabled={loadingMore} onClick={more} className="rounded-field px-3 py-1.5 text-xs font-medium text-primary transition-soft hover:bg-sage disabled:opacity-50">
                        {loadingMore ? t('timeline.loading') : t('timeline.loadMore')}
                    </button>
                </div>
            )}
        </section>
    );
}
