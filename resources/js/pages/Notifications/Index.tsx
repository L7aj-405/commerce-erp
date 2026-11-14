import { notificationText } from '@/components/notifications/notificationText';
import { AdvancedFilterBar, ChoiceFilter } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { Badge } from '@/components/ui/StatusBadge';
import type { StatusVariant } from '@/components/ui/StatusBadge';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import type { AppNotification } from '@/types/app';
import { formatDateTime } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Pagination = { data: AppNotification[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null; total: number };
type Props = { notifications: Pagination; filters: { status: string; category: string; severity: string }; categories: string[]; severities: string[] };
const severityVariant: Record<string, StatusVariant> = { info: 'info', success: 'success', warning: 'warning', critical: 'danger' };

export default function NotificationIndex({ notifications, filters, categories, severities }: Props) {
    const { t } = useI18n();
    const [loading, setLoading] = useState(false);
    const categoryLabels: Record<string, string> = { system: t('notifications.system'), security: t('notifications.security'), woocommerce: 'WooCommerce', backup: t('notifications.backups'), inventory: t('notifications.stock'), finance: 'Finance', sales: t('notifications.sales'), documents: 'Documents', account: t('notifications.account') };
    const severityLabel = (severity: string) => t(`notifications.severity.${severity}` as TranslationKey) ?? severity;
    // NotificationController@index validates status (all|unread|read, default all), category and severity.
    const apply = (patch: Partial<Props['filters']>) =>
        router.get('/notifications', { ...filters, ...patch }, { preserveState: true, replace: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) });
    const mark = (item: AppNotification, read: boolean) => router.patch(`/notifications/${item.id}/${read ? 'read' : 'unread'}`, {}, { preserveScroll: true });
    const statuses = [
        { value: 'all', label: t('notifications.all') },
        { value: 'unread', label: t('notifications.unread') },
        { value: 'read', label: t('notifications.read') },
    ];
    const status = filters.status || 'all';

    const chips: FilterChip[] = [];
    if (status !== 'all') chips.push({ key: 'status', label: t('common.status'), value: statuses.find((s) => s.value === status)?.label ?? status, onRemove: () => apply({ status: 'all' }) });
    if (filters.category) chips.push({ key: 'category', label: t('notifications.category'), value: categoryLabels[filters.category] ?? filters.category, onRemove: () => apply({ category: '' }) });
    if (filters.severity) chips.push({ key: 'severity', label: t('notifications.priority'), value: severityLabel(filters.severity), onRemove: () => apply({ severity: '' }) });
    const reset = () => apply({ status: 'all', category: '', severity: '' });

    return (
        <ApplicationShell width="reading">
            <Head title={t('notifications.title')} />
            <PageHeader
                title={t('notifications.title')}
                description={t('notifications.description')}
                actions={
                    <Button variant="secondary" onClick={() => router.post('/notifications/read-all')}>
                        {t('notifications.markAllRead')}
                    </Button>
                }
            />

            <div className="mb-4">
                <AdvancedFilterBar label={t('notifications.title')} chips={chips} activeCount={chips.length} loading={loading} onReset={reset}>
                    <div role="group" aria-label={t('common.status')} className="inline-flex h-10 items-center gap-0.5 rounded-field border border-line-strong bg-surface p-0.5">
                        {statuses.map((item) => {
                            const active = status === item.value;
                            return (
                                <button
                                    key={item.value}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => apply({ status: item.value })}
                                    className={`h-full rounded-[calc(var(--radius-field)-3px)] px-3 text-sm font-medium transition-soft focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary ${
                                        active ? 'bg-primary text-primary-fg' : 'text-ink-muted hover:bg-sage hover:text-ink'
                                    }`}
                                >
                                    {item.label}
                                </button>
                            );
                        })}
                    </div>
                    <ChoiceFilter
                        label={t('notifications.category')}
                        allLabel={t('notifications.allCategories')}
                        options={categories.map((category) => ({ value: category, label: categoryLabels[category] ?? category }))}
                        value={filters.category || undefined}
                        onChange={(value) => apply({ category: value ?? '' })}
                    />
                    <ChoiceFilter
                        label={t('notifications.priority')}
                        allLabel={t('notifications.allPriorities')}
                        options={severities.map((severity) => ({ value: severity, label: severityLabel(severity) }))}
                        value={filters.severity || undefined}
                        onChange={(value) => apply({ severity: value ?? '' })}
                    />
                </AdvancedFilterBar>
            </div>

            {notifications.data.length === 0 ? (
                <EmptyState
                    title={chips.length > 0 ? t('filters.noMatch') : t('notifications.empty')}
                    description={chips.length > 0 ? t('filters.noMatchHelp') : t('notifications.emptyHelp')}
                    actions={
                        chips.length > 0 ? (
                            <Button variant="secondary" onClick={reset}>
                                {t('filters.reset')}
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <section className={`surface-card divide-y divide-line overflow-hidden p-0 transition-opacity ${loading ? 'opacity-55' : ''}`} aria-busy={loading || undefined}>
                    {notifications.data.map((item) => {
                        const text = notificationText(item, t);
                        return (
                            <article key={item.id} className={`p-4 sm:p-5 ${item.read_at ? '' : 'bg-sage/25'}`}>
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                                    <Badge variant={severityVariant[item.severity] ?? 'neutral'} className="w-fit">
                                        {categoryLabels[item.category] ?? item.category}
                                    </Badge>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 className="font-semibold text-ink">{text.title}</h2>
                                            {!item.read_at && (
                                                <span className="size-2 rounded-full bg-primary" aria-hidden="true" />
                                            )}
                                            {!item.read_at && <span className="sr-only">{t('notifications.unread')}</span>}
                                        </div>
                                        <p className="mt-1 text-sm text-ink-muted">{text.message}</p>
                                        <p className="mt-2 text-xs text-ink-faint">{formatDateTime(item.created_at)}</p>
                                    </div>
                                    <div className="flex shrink-0 flex-wrap gap-2">
                                        {item.action_url && (
                                            <Link
                                                href={item.action_url}
                                                onClick={(event) => {
                                                    if (!item.read_at) {
                                                        event.preventDefault();
                                                        router.patch(`/notifications/${item.id}/read`, {}, { onSuccess: () => router.visit(item.action_url!) });
                                                    }
                                                }}
                                                className="inline-flex min-h-9 items-center rounded-field border border-line-strong bg-surface px-3 text-sm font-medium text-ink transition-soft hover:bg-raised"
                                            >
                                                {t('common.open')}
                                            </Link>
                                        )}
                                        <Button type="button" size="sm" variant="ghost" onClick={() => mark(item, !item.read_at)}>
                                            {item.read_at ? t('notifications.markUnread') : t('notifications.markRead')}
                                        </Button>
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </section>
            )}

            {notifications.last_page > 1 && (
                <nav className="mt-5 flex flex-wrap items-center justify-between gap-3 text-sm" aria-label={t('notifications.title')}>
                    <span className="text-ink-muted tabular-nums">
                        {t('notifications.page')} {notifications.current_page} {t('notifications.of')} {notifications.last_page} · {notifications.total} {t('notifications.title').toLocaleLowerCase()}
                    </span>
                    <div className="flex gap-2">
                        {notifications.prev_page_url && (
                            <ButtonLink href={notifications.prev_page_url} variant="secondary">
                                {t('notifications.previous')}
                            </ButtonLink>
                        )}
                        {notifications.next_page_url && (
                            <ButtonLink href={notifications.next_page_url} variant="secondary">
                                {t('notifications.next')}
                            </ButtonLink>
                        )}
                    </div>
                </nav>
            )}
        </ApplicationShell>
    );
}
