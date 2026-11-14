import { useI18n } from '@/i18n';
import type { ReactNode } from 'react';

export type AlertTone = 'info' | 'success' | 'warning' | 'danger' | 'neutral';

const tones: Record<AlertTone, { box: string; icon: string }> = {
    info: { box: 'border-info/25 bg-info-soft', icon: 'text-info' },
    success: { box: 'border-success/30 bg-success-soft', icon: 'text-success' },
    warning: { box: 'border-warning/35 bg-warning-soft', icon: 'text-warning' },
    danger: { box: 'border-danger/30 bg-danger-soft', icon: 'text-danger' },
    neutral: { box: 'border-line bg-raised', icon: 'text-ink-muted' },
};

const paths: Record<AlertTone, ReactNode> = {
    info: <path d="M12 16v-5M12 8h.01" />,
    neutral: <path d="M12 16v-5M12 8h.01" />,
    success: <path d="m8 12.5 2.5 2.5L16 9.5" />,
    warning: <path d="M12 8v5M12 16.5h.01" />,
    danger: <path d="M12 7.5v5.5M12 16.5h.01" />,
};

/**
 * Inline message for a section or page. Title and body use ink colors for
 * readability; only the icon and border carry the tone, and the icon shape
 * differs per tone so meaning never depends on color alone.
 * danger/warning announce assertively (role="alert"); others politely.
 */
export function Alert({
    tone = 'info',
    title,
    children,
    actions,
    onDismiss,
    className = '',
}: {
    tone?: AlertTone;
    title?: ReactNode;
    children?: ReactNode;
    actions?: ReactNode;
    onDismiss?: () => void;
    className?: string;
}) {
    const { t } = useI18n();
    const style = tones[tone];
    const urgent = tone === 'danger' || tone === 'warning';
    return (
        <div role={urgent ? 'alert' : 'status'} className={`flex items-start gap-3 rounded-card border px-4 py-3 ${style.box} ${className}`}>
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" className={`mt-px shrink-0 ${style.icon}`}>
                {tone === 'warning' ? <path d="M10.3 3.9 2.4 17.6A2 2 0 0 0 4.1 20.6h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /> : <circle cx="12" cy="12" r="9" />}
                {paths[tone]}
            </svg>
            <div className="min-w-0 flex-1 text-sm leading-6">
                {title && <p className="font-semibold text-ink">{title}</p>}
                {children && <div className="text-ink-muted [&_a]:font-semibold [&_a]:text-ink [&_a]:underline">{children}</div>}
                {actions && <div className="mt-2.5 flex flex-wrap gap-2">{actions}</div>}
            </div>
            {onDismiss && (
                <button
                    type="button"
                    onClick={onDismiss}
                    aria-label={t('ui.dismiss')}
                    className="-me-1 -mt-0.5 flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg text-ink-muted transition-soft hover:bg-surface/70 hover:text-ink"
                >
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            )}
        </div>
    );
}
