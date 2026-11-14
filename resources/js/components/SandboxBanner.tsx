import { FlaskConicalIcon } from '@/components/ui/icons';
import { router, usePage } from '@inertiajs/react';

/**
 * Global Test Mode bar: full width, borderless, a mint/teal wash that is strongest at the
 * top and fades into the canvas below (opaque, so content scrolling underneath stays
 * readable in both themes). Three balanced zones: Test pill · reassurance · exit button.
 * On small screens the reassurance moves to its own centered line. Presentation only.
 */
export default function SandboxBanner() {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    return (
        <div
            role="status"
            className="sticky top-0 z-40 grid shrink-0 grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1.5 bg-[linear-gradient(to_bottom,color-mix(in_srgb,var(--color-accent)_26%,var(--color-canvas)),color-mix(in_srgb,var(--color-accent)_9%,var(--color-canvas))_55%,var(--color-canvas))] px-3 pb-3 pt-1.5 text-[13px] text-ink sm:grid-cols-[1fr_auto_1fr] sm:px-5 lg:-ms-[5.625rem]"
        >
            <a
                href="/test-mode"
                className="inline-flex h-7 items-center gap-1.5 justify-self-start rounded-full bg-surface/70 px-3 font-semibold text-accent shadow-xs ring-1 ring-accent/20 backdrop-blur-sm transition-soft hover:bg-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
                <FlaskConicalIcon size={14} className="shrink-0" />
                Mode test
            </a>
            <p className="order-last col-span-2 px-2 text-center font-medium sm:order-none sm:col-span-1">Aucune donnée de Production n’est modifiée.</p>
            <button
                type="button"
                onClick={() => router.post('/test-mode/leave')}
                className="inline-flex h-7 cursor-pointer items-center justify-self-end rounded-full bg-surface px-3.5 font-semibold text-ink shadow-xs ring-1 ring-line-strong transition-soft hover:bg-sage focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
                Quitter le mode test
            </button>
        </div>
    );
}
