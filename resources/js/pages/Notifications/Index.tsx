import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import type { AppNotification } from '@/types/app';
import { formatDateTime } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';

type Pagination = { data: AppNotification[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null; total: number };
type Props = { notifications: Pagination; filters: { status: string; category: string; severity: string }; categories: string[]; severities: string[] };
const severityStyles: Record<string, string> = { info: 'bg-sage text-primary', success: 'bg-success-soft text-success', warning: 'bg-warning-soft text-warning', critical: 'bg-danger-soft text-danger' };

export default function NotificationIndex({ notifications, filters, categories, severities }: Props) {
    const { t } = useI18n();
    const categoryLabels: Record<string, string> = { system: t('notifications.system'), security: t('notifications.security'), woocommerce: 'WooCommerce', backup: t('notifications.backups'), inventory: t('notifications.stock'), finance: 'Finance', sales: t('notifications.sales'), documents: 'Documents', account: t('notifications.account') };
    const apply = (patch: Partial<Props['filters']>) => router.get('/notifications', { ...filters, ...patch }, { preserveState: true, replace: true });
    const mark = (item: AppNotification, read: boolean) => router.patch(`/notifications/${item.id}/${read ? 'read' : 'unread'}`, {}, { preserveScroll: true });

    return <ApplicationShell wide><Head title={t('notifications.title')} /><PageHeader title={t('notifications.title')} description={t('notifications.description')} />
        <div className="mb-5 flex flex-wrap items-center gap-2 rounded-card border border-line bg-surface p-4 shadow-soft">
            {['all', 'unread', 'read'].map((status) => <button key={status} type="button" onClick={() => apply({ status })} className={`rounded-field px-3 py-2 text-sm font-medium ${filters.status === status ? 'bg-primary text-primary-fg' : 'bg-raised text-ink-muted'}`}>{status === 'all' ? t('notifications.all') : status === 'unread' ? t('notifications.unread') : t('notifications.read')}</button>)}
            <select value={filters.category} onChange={(event) => apply({ category: event.target.value })} className="control-select"><option value="">{t('notifications.allCategories')}</option>{categories.map((category) => <option key={category} value={category}>{categoryLabels[category] ?? category}</option>)}</select>
            <select value={filters.severity} onChange={(event) => apply({ severity: event.target.value })} className="control-select"><option value="">{t('notifications.allPriorities')}</option>{severities.map((severity) => <option key={severity} value={severity}>{severity}</option>)}</select>
            <button type="button" onClick={() => router.post('/notifications/read-all')} className="ms-auto rounded-field border border-line-strong px-3 py-2 text-sm font-semibold text-ink">{t('notifications.markAllRead')}</button>
        </div>
        <section className="overflow-hidden rounded-card border border-line bg-surface shadow-soft">
            {notifications.data.length === 0 ? <div className="p-12 text-center"><p className="font-semibold text-ink">{t('notifications.empty')}</p><p className="mt-1 text-sm text-ink-muted">{t('notifications.emptyHelp')}</p></div> : <div className="divide-y divide-line">{notifications.data.map((item) => <article key={item.id} className={`p-4 sm:p-5 ${item.read_at ? '' : 'bg-sage/25'}`}><div className="flex flex-col gap-3 sm:flex-row sm:items-start"><span className={`w-fit rounded-full px-2.5 py-1 text-xs font-semibold ${severityStyles[item.severity]}`}>{categoryLabels[item.category] ?? item.category}</span><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold text-ink">{item.title}</h2>{!item.read_at && <span className="size-2 rounded-full bg-primary" />}</div><p className="mt-1 text-sm text-ink-muted">{item.message}</p><p className="mt-2 text-xs text-ink-faint">{formatDateTime(item.created_at)}</p></div><div className="flex shrink-0 gap-2">{item.action_url && <Link href={item.action_url} onClick={(event) => { if (!item.read_at) { event.preventDefault(); router.patch(`/notifications/${item.id}/read`, {}, { onSuccess: () => router.visit(item.action_url!) }); } }} className="rounded-field border border-line-strong px-3 py-2 text-xs font-semibold text-ink">{t('common.open')}</Link>}<button type="button" onClick={() => mark(item, !item.read_at)} className="rounded-field px-3 py-2 text-xs font-semibold text-primary">{item.read_at ? t('notifications.markUnread') : t('notifications.markRead')}</button></div></div></article>)}</div>}
        </section>
        {notifications.last_page > 1 && <nav className="mt-5 flex items-center justify-between text-sm"><span className="text-ink-muted">{t('notifications.page')} {notifications.current_page} {t('notifications.of')} {notifications.last_page} · {notifications.total} {t('notifications.title').toLocaleLowerCase()}</span><div className="flex gap-2">{notifications.prev_page_url && <Link href={notifications.prev_page_url} className="rounded-field border border-line-strong px-3 py-2">{t('notifications.previous')}</Link>}{notifications.next_page_url && <Link href={notifications.next_page_url} className="rounded-field border border-line-strong px-3 py-2">{t('notifications.next')}</Link>}</div></nav>}
    </ApplicationShell>;
}
