import { Button } from '@/components/ui/Button';
import { router, usePage } from '@inertiajs/react';

/**
 * Global Test Mode bar: full width, no border. A mint/teal tint at the very top fades to fully
 * transparent below (the layer is blurred and masked with the same fade, so page content
 * scrolling under the sticky bar never clashes with its text and there is no visible cutoff).
 * Three zones: Test pill · reassurance · exit button. Below `sm` the pill and the exit button
 * are icon-only (accessible names kept). Presentation only.
 */
export default function SandboxBanner() {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    return (
        <div role="status" className="sticky top-0 z-40 w-full shrink-0 lg:-ms-[5.625rem]">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 bg-[linear-gradient(180deg,color-mix(in_srgb,var(--color-accent)_34%,transparent)_0%,color-mix(in_srgb,var(--color-accent)_22%,transparent)_32%,color-mix(in_srgb,var(--color-accent)_10%,transparent)_62%,transparent_100%)] backdrop-blur-md [mask-image:linear-gradient(to_bottom,black_55%,transparent)]"
            />
            <div className="relative grid min-h-[66px] grid-cols-[auto_1fr_auto] items-center gap-3 px-3 pb-4 pt-2.5 sm:gap-4 sm:px-5 md:grid-cols-[1fr_minmax(20rem,1.25fr)_1fr] md:gap-6 md:px-7">
                <div className="flex items-center justify-start">
                    <a
                        href="/test-mode"
                        aria-label="Mode test"
                        className="inline-flex h-[38px] items-center gap-2 rounded-full border border-accent/20 bg-surface/75 px-3 text-sm font-bold text-accent shadow-sm backdrop-blur-md transition-soft hover:bg-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:px-3.5"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="shrink-0">
                            <path d="M9 3h6" />
                            <path d="M10 3v5.2L5.7 16a3 3 0 0 0 2.6 4.5h7.4a3 3 0 0 0 2.6-4.5L14 8.2V3" />
                            <path d="M8 14h8" />
                        </svg>
                        <span className="max-sm:sr-only">Mode test</span>
                    </a>
                </div>
                <p className="text-center text-sm font-semibold text-ink">Aucune donnée de Production n’est modifiée.</p>
                <div className="flex items-center justify-end">
                    <Button variant="primary" onClick={() => router.post('/test-mode/leave')} aria-label="Quitter le mode test" className="rounded-xl max-sm:size-10 max-sm:px-0 sm:px-4">
                        <svg aria-hidden="true" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M10 17l5-5-5-5" />
                            <path d="M15 12H3" />
                            <path d="M14 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                        </svg>
                        <span className="max-sm:sr-only">Quitter le mode test</span>
                    </Button>
                </div>
            </div>
        </div>
    );
}
