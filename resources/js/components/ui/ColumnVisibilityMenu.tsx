import type { ColumnDef } from '@/hooks/useColumnVisibility';
import { useI18n } from '@/i18n';
import { useEffect, useId, useRef, useState } from 'react';

/** "Colonnes" control: show/hide table columns (locked ones stay on) and reset to the defaults. Keyboard: Escape closes. */
export function ColumnVisibilityMenu({ columns, isVisible, onToggle, onReset, isDefault }: { columns: ColumnDef[]; isVisible: (key: string) => boolean; onToggle: (key: string) => void; onReset: () => void; isDefault: boolean }) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const panelId = useId();

    useEffect(() => {
        if (!open) return;
        const onDown = (e: MouseEvent) => {
            if (root.current && !root.current.contains(e.target as Node)) setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div ref={root} className="relative">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((v) => !v)}
                className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-field border border-line bg-surface px-3 text-sm font-medium text-ink transition-soft hover:bg-sage focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
            >
                {t('columns.button')}
            </button>
            {open && (
                <div id={panelId} role="group" aria-label={t('columns.button')} className="absolute end-0 z-30 mt-2 w-64 rounded-panel border border-line bg-surface p-3 shadow-lg">
                    <ul className="max-h-72 space-y-1 overflow-auto">
                        {columns.map((c) => (
                            <li key={c.key}>
                                <label className="flex cursor-pointer items-center gap-2 rounded-field px-2 py-1.5 text-sm text-ink hover:bg-sage">
                                    <input type="checkbox" checked={isVisible(c.key)} disabled={c.locked} onChange={() => onToggle(c.key)} />
                                    <span>{c.label}</span>
                                </label>
                            </li>
                        ))}
                    </ul>
                    <button type="button" onClick={onReset} disabled={isDefault} className="mt-2 w-full cursor-pointer rounded-field border border-line px-3 py-1.5 text-sm text-ink hover:bg-sage disabled:cursor-not-allowed disabled:opacity-50">
                        {t('columns.reset')}
                    </button>
                </div>
            )}
        </div>
    );
}
