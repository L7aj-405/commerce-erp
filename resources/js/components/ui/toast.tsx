import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { PropsWithChildren, ReactNode } from 'react';

export type ToastLevel = 'success' | 'error' | 'warning' | 'info';

type Toast = { id: number; level: ToastLevel; message: ReactNode; duration: number };

type ToastApi = {
    push: (level: ToastLevel, message: ReactNode, options?: { duration?: number }) => number;
    success: (message: ReactNode, options?: { duration?: number }) => number;
    error: (message: ReactNode, options?: { duration?: number }) => number;
    warning: (message: ReactNode, options?: { duration?: number }) => number;
    info: (message: ReactNode, options?: { duration?: number }) => number;
    dismiss: (id: number) => void;
};

const ToastContext = createContext<ToastApi | null>(null);

const DEFAULTS: Record<ToastLevel, number> = { success: 3200, info: 3600, warning: 5000, error: 6500 };

/**
 * One coherent toast system for action outcomes. Levels map to the app's feedback
 * tokens. Not for high-frequency events (quantity +1) — those get inline feedback.
 */
export function ToastProvider({ children }: PropsWithChildren) {
    const [toasts, setToasts] = useState<Toast[]>([]);
    const seq = useRef(0);
    const timers = useRef(new Map<number, number>());

    const dismiss = useCallback((id: number) => {
        setToasts((current) => current.filter((toast) => toast.id !== id));
        const timer = timers.current.get(id);
        if (timer) {
            window.clearTimeout(timer);
            timers.current.delete(id);
        }
    }, []);

    const push = useCallback<ToastApi['push']>(
        (level, message, options) => {
            const id = ++seq.current;
            const duration = options?.duration ?? DEFAULTS[level];
            setToasts((current) => [...current.slice(-3), { id, level, message, duration }]);
            if (duration > 0) {
                timers.current.set(id, window.setTimeout(() => dismiss(id), duration));
            }
            return id;
        },
        [dismiss],
    );

    useEffect(() => {
        const map = timers.current;
        return () => map.forEach((timer) => window.clearTimeout(timer));
    }, []);

    const api = useMemo<ToastApi>(
        () => ({
            push,
            dismiss,
            success: (message, options) => push('success', message, options),
            error: (message, options) => push('error', message, options),
            warning: (message, options) => push('warning', message, options),
            info: (message, options) => push('info', message, options),
        }),
        [push, dismiss],
    );

    return (
        <ToastContext.Provider value={api}>
            {children}
            <Toaster toasts={toasts} onDismiss={dismiss} />
        </ToastContext.Provider>
    );
}

export function useToast(): ToastApi {
    const context = useContext(ToastContext);
    if (!context) {
        throw new Error('useToast must be used inside <ToastProvider>.');
    }
    return context;
}

const toneClass: Record<ToastLevel, string> = {
    success: 'border-success/25 bg-success-soft text-success',
    error: 'border-danger/25 bg-danger-soft text-danger',
    warning: 'border-warning/25 bg-warning-soft text-warning',
    info: 'border-line-strong bg-surface text-ink',
};

function Icon({ level }: { level: ToastLevel }) {
    const path =
        level === 'success'
            ? 'm5 13 4 4L19 7'
            : level === 'error'
              ? 'M12 8v5M12 17h.01M10.3 4.3 2.5 18a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z'
              : level === 'warning'
                ? 'M12 9v4M12 17h.01M12 3l9 16H3l9-16Z'
                : 'M12 8h.01M11 12h1v5h1';
    return (
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className="mt-0.5 shrink-0">
            <path d={path} />
        </svg>
    );
}

function Toaster({ toasts, onDismiss }: { toasts: Toast[]; onDismiss: (id: number) => void }) {
    if (toasts.length === 0) return null;
    return (
        <div className="pointer-events-none fixed inset-x-0 top-3 z-[70] flex flex-col items-center gap-2 px-4 print:hidden" role="region" aria-label="Notifications">
            {toasts.map((toast) => (
                <div
                    key={toast.id}
                    role="status"
                    aria-live={toast.level === 'error' ? 'assertive' : 'polite'}
                    className={`pointer-events-auto flex w-full max-w-sm items-start gap-2 rounded-card border px-3.5 py-2.5 text-[13px] shadow-pop transition-soft ${toneClass[toast.level]}`}
                >
                    <Icon level={toast.level} />
                    <div className="min-w-0 flex-1">{toast.message}</div>
                    <button
                        type="button"
                        onClick={() => onDismiss(toast.id)}
                        aria-label="Fermer"
                        className="shrink-0 opacity-60 transition-soft hover:opacity-100"
                    >
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" aria-hidden="true">
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            ))}
        </div>
    );
}
