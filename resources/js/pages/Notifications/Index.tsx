import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
import type { AppNotification } from '@/types/app';
import { formatDateTime } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';

type Pagination = { data: AppNotification[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null; total: number };
type Props = { notifications: Pagination; filters: { status: string; category: string; severity: string }; categories: string[]; severities: string[] };
const categoryLabels: Record<string, string> = { system: 'Système', security: 'Sécurité', woocommerce: 'WooCommerce', backup: 'Sauvegardes', inventory: 'Stock', finance: 'Finance', sales: 'Ventes', documents: 'Documents', account: 'Compte' };
const severityStyles: Record<string, string> = { info: 'bg-sage text-primary', success: 'bg-success-soft text-success', warning: 'bg-warning-soft text-warning', critical: 'bg-danger-soft text-danger' };

export default function NotificationIndex({ notifications, filters, categories, severities }: Props) {
    const apply = (patch: Partial<Props['filters']>) => router.get('/notifications', { ...filters, ...patch }, { preserveState: true, replace: true });
    const mark = (item: AppNotification, read: boolean) => router.patch(`/notifications/${item.id}/${read ? 'read' : 'unread'}`, {}, { preserveScroll: true });

    return <ApplicationShell wide><Head title="Notifications" /><PageHeader title="Notifications" description="Alertes opérationnelles et événements importants de votre organisation." />
        <div className="mb-5 flex flex-wrap items-center gap-2 rounded-card border border-line bg-surface p-4 shadow-soft">
            {['all', 'unread', 'read'].map((status) => <button key={status} type="button" onClick={() => apply({ status })} className={`rounded-field px-3 py-2 text-sm font-medium ${filters.status === status ? 'bg-primary text-primary-fg' : 'bg-raised text-ink-muted'}`}>{status === 'all' ? 'Toutes' : status === 'unread' ? 'Non lues' : 'Lues'}</button>)}
            <select value={filters.category} onChange={(event) => apply({ category: event.target.value })} className="rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"><option value="">Toutes les catégories</option>{categories.map((category) => <option key={category} value={category}>{categoryLabels[category] ?? category}</option>)}</select>
            <select value={filters.severity} onChange={(event) => apply({ severity: event.target.value })} className="rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"><option value="">Toutes les priorités</option>{severities.map((severity) => <option key={severity} value={severity}>{severity}</option>)}</select>
            <button type="button" onClick={() => router.post('/notifications/read-all')} className="ml-auto rounded-field border border-line-strong px-3 py-2 text-sm font-semibold text-ink">Tout marquer comme lu</button>
        </div>
        <section className="overflow-hidden rounded-card border border-line bg-surface shadow-soft">
            {notifications.data.length === 0 ? <div className="p-12 text-center"><p className="font-semibold text-ink">Aucune notification</p><p className="mt-1 text-sm text-ink-muted">Les événements correspondant à ces filtres apparaîtront ici.</p></div> : <div className="divide-y divide-line">{notifications.data.map((item) => <article key={item.id} className={`p-4 sm:p-5 ${item.read_at ? '' : 'bg-sage/25'}`}><div className="flex flex-col gap-3 sm:flex-row sm:items-start"><span className={`w-fit rounded-full px-2.5 py-1 text-xs font-semibold ${severityStyles[item.severity]}`}>{categoryLabels[item.category] ?? item.category}</span><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold text-ink">{item.title}</h2>{!item.read_at && <span className="size-2 rounded-full bg-primary" />}</div><p className="mt-1 text-sm text-ink-muted">{item.message}</p><p className="mt-2 text-xs text-ink-faint">{formatDateTime(item.created_at)}</p></div><div className="flex shrink-0 gap-2">{item.action_url && <Link href={item.action_url} onClick={(event) => { if (!item.read_at) { event.preventDefault(); router.patch(`/notifications/${item.id}/read`, {}, { onSuccess: () => router.visit(item.action_url!) }); } }} className="rounded-field border border-line-strong px-3 py-2 text-xs font-semibold text-ink">Ouvrir</Link>}<button type="button" onClick={() => mark(item, !item.read_at)} className="rounded-field px-3 py-2 text-xs font-semibold text-primary">{item.read_at ? 'Marquer non lue' : 'Marquer lue'}</button></div></div></article>)}</div>}
        </section>
        {notifications.last_page > 1 && <nav className="mt-5 flex items-center justify-between text-sm"><span className="text-ink-muted">Page {notifications.current_page} sur {notifications.last_page} · {notifications.total} notifications</span><div className="flex gap-2">{notifications.prev_page_url && <Link href={notifications.prev_page_url} className="rounded-field border border-line-strong px-3 py-2">Précédent</Link>}{notifications.next_page_url && <Link href={notifications.next_page_url} className="rounded-field border border-line-strong px-3 py-2">Suivant</Link>}</div></nav>}
    </ApplicationShell>;
}
