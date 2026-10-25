import type { AppNotification, NotificationSummary } from '@/types/app';
import { useI18n } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { notificationText } from './notificationText';

export default function NotificationBell({ initial }: { initial: NotificationSummary }) {
    const { direction, t } = useI18n();
    const [summary, setSummary] = useState(initial);
    const polling = useRef(false);
    const seen = useRef(new Set(initial.recent.filter((item) => !item.read_at).map((item) => item.id)));

    useEffect(() => setSummary(initial), [initial]);

    const poll = useCallback(async () => {
        if (polling.current || document.visibilityState === 'hidden') return;
        polling.current = true;
        try {
            const response = await fetch('/notifications/feed', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            const next = await response.json() as NotificationSummary;
            const newUnread = next.recent.filter((item) => !item.read_at && !seen.current.has(item.id));
            next.recent.filter((item) => !item.read_at).forEach((item) => seen.current.add(item.id));
            if (newUnread.length > 0 && next.preferences.sound_enabled) playNotificationSound(next.preferences.sound_volume);
            setSummary(next);
        } finally {
            polling.current = false;
        }
    }, []);

    useEffect(() => {
        const timer = window.setInterval(() => void poll(), summary.poll_seconds * 1000);
        const visibility = () => document.visibilityState === 'visible' && void poll();
        document.addEventListener('visibilitychange', visibility);
        return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', visibility); };
    }, [poll, summary.poll_seconds]);

    const markAllRead = () => router.post('/notifications/read-all', {}, {
        preserveScroll: true,
        onSuccess: () => setSummary((current) => ({ ...current, unread_count: 0, recent: current.recent.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })) })),
    });
    const open = (notification: AppNotification) => {
        if (!notification.read_at) {
            router.patch(`/notifications/${notification.id}/read`, {}, { preserveScroll: true, onSuccess: () => notification.action_url && router.visit(notification.action_url) });
        } else if (notification.action_url) router.visit(notification.action_url);
    };

    return <details className="relative shrink-0" dir={direction}>
        <summary aria-label={t('notifications.title')} className="relative flex size-10 cursor-pointer list-none items-center justify-center rounded-field text-ink-muted transition-soft hover:bg-sage hover:text-ink">
            <BellIcon />
            {summary.unread_count > 0 && <span className="absolute -top-1 -end-1 min-w-5 rounded-full bg-danger px-1.5 py-0.5 text-center text-[10px] font-bold leading-4 text-white">{summary.unread_count > 99 ? '99+' : summary.unread_count}</span>}
        </summary>
        <div className="absolute end-0 top-12 z-40 w-[min(24rem,calc(100vw-1.5rem))] rounded-card border border-line bg-surface text-start shadow-pop">
            <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3"><div><p className="font-semibold text-ink">{t('notifications.title')}</p><p className="text-xs text-ink-muted">{summary.unread_count} {t('notifications.unreadCount')}</p></div>{summary.unread_count > 0 && <button type="button" onClick={markAllRead} className="text-xs font-semibold text-primary">{t('notifications.markAllRead')}</button>}</div>
            <div className="max-h-96 overflow-y-auto">
                {summary.recent.length === 0 ? <p className="p-6 text-center text-sm text-ink-muted">{t('notifications.empty')}</p> : summary.recent.map((item) => <button key={item.id} type="button" onClick={() => open(item)} className={`flex w-full gap-3 border-b border-line px-4 py-3 text-start transition-soft hover:bg-sage/50 ${item.read_at ? '' : 'bg-sage/30'}`}><span className={`mt-1 size-2 shrink-0 rounded-full ${severityDot[item.severity]}`} /><span className="min-w-0"><span className="block text-sm font-semibold text-ink">{notificationText(item, t).title}</span><span className="mt-0.5 line-clamp-2 block text-xs text-ink-muted">{notificationText(item, t).message}</span><span className="mt-1 block text-[11px] text-ink-faint">{relativeTime(item.created_at, t)}</span></span></button>)}
            </div>
            <div className="flex items-center justify-between p-3"><Link href="/notifications" className="text-sm font-semibold text-primary">{t('notifications.viewAll')}</Link><Link href="/account/notifications" className="text-xs text-ink-muted hover:text-ink">{t('notifications.preferences')}</Link></div>
        </div>
    </details>;
}

const severityDot = { info: 'bg-primary', success: 'bg-success', warning: 'bg-warning', critical: 'bg-danger' };
function relativeTime(value: string, t: ReturnType<typeof useI18n>['t']) { const seconds = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 1000)); if (seconds < 60) return t('notifications.justNow'); const key = seconds < 3600 ? 'notifications.minutesAgo' : seconds < 86400 ? 'notifications.hoursAgo' : 'notifications.daysAgo'; const count = seconds < 3600 ? Math.floor(seconds / 60) : seconds < 86400 ? Math.floor(seconds / 3600) : Math.floor(seconds / 86400); return t(key).replace('{count}', String(count)); }
function playNotificationSound(volume: number) { try { const AudioContextClass = window.AudioContext ?? (window as typeof window & { webkitAudioContext?: typeof AudioContext }).webkitAudioContext; if (!AudioContextClass) return; const context = new AudioContextClass(); const oscillator = context.createOscillator(); const gain = context.createGain(); oscillator.frequency.value = 740; gain.gain.value = Math.max(0, Math.min(1, volume)) * 0.12; oscillator.connect(gain); gain.connect(context.destination); oscillator.start(); gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + 0.18); oscillator.stop(context.currentTime + 0.18); oscillator.addEventListener('ended', () => void context.close()); } catch { /* Browser autoplay policy may block sound until interaction. */ } }
function BellIcon() { return <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /><path d="M10 21h4" /></svg>; }
