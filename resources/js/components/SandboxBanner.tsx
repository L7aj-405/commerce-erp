import { FlaskConicalIcon } from '@/components/ui/icons';
import { router, usePage } from '@inertiajs/react';

/**
 * Global Test Mode bar: full width, compact, pale teal. Three regions (Test mark ·
 * reassurance · exit); on small screens the reassurance drops to its own centered
 * line under the mark and the exit link. Detail lives on the Test Mode home.
 */
export default function SandboxBanner() {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    return (
        <div
            role="status"
            className="sticky top-0 z-40 grid min-h-9 shrink-0 grid-cols-[1fr_auto] items-center gap-x-3 gap-y-0.5 border-b border-accent/25 bg-accent-soft px-3 py-1 text-[13px] text-ink sm:grid-cols-[1fr_auto_1fr] sm:px-4 lg:-ms-[5.625rem]"
        >
            <span className="inline-flex items-center gap-2 font-semibold text-accent">
                <FlaskConicalIcon size={15} className="shrink-0" />
                Mode test
            </span>
            <p className="order-last col-span-2 text-center font-medium sm:order-none sm:col-span-1">Aucune donnée de Production n’est modifiée.</p>
            <button
                type="button"
                onClick={() => router.post('/test-mode/leave')}
                className="cursor-pointer justify-self-end rounded-md px-2 py-0.5 text-[13px] font-medium text-ink-muted underline-offset-4 transition-soft hover:text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
                Quitter le mode test
            </button>
        </div>
    );
}
