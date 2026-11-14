import { FlaskConicalIcon } from '@/components/ui/icons';
import { router, usePage } from '@inertiajs/react';

/**
 * Global Test Mode bar: full width, one compact line. Detail (what is simulated,
 * which modules exist) lives on the Test Mode home, not here.
 */
export default function SandboxBanner() {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    return (
        <div
            role="status"
            className="sticky top-0 z-40 flex min-h-8 shrink-0 flex-wrap items-center gap-x-3 gap-y-0.5 border-b border-warning/25 bg-warning-soft px-3 py-1 text-[13px] text-ink sm:px-4 lg:-ms-[5.625rem]"
        >
            <FlaskConicalIcon size={14} className="shrink-0 text-warning" />
            <p className="min-w-0 flex-1 font-medium">Mode test actif — aucune donnée de Production n’est modifiée.</p>
            <button
                type="button"
                onClick={() => router.post('/test-mode/leave')}
                className="shrink-0 cursor-pointer rounded-md px-2 py-0.5 text-[13px] font-medium text-ink-muted underline-offset-4 transition-soft hover:text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
                Quitter le mode test
            </button>
        </div>
    );
}
