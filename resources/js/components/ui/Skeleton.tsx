/**
 * Content-shaped loading placeholders. Use for FIRST loads only — on a refetch,
 * keep the existing content visible and dim it instead (see §19 of the UX spec).
 */
export function Skeleton({ className = '' }: { className?: string }) {
    return <div className={`animate-pulse rounded-field bg-sage ${className}`} />;
}

/** A stack of text-line skeletons. */
export function SkeletonText({ lines = 3, className = '' }: { lines?: number; className?: string }) {
    return (
        <div className={`space-y-2 ${className}`} aria-hidden="true">
            {Array.from({ length: lines }).map((_, index) => (
                <Skeleton key={index} className={`h-3.5 ${index === lines - 1 ? 'w-2/3' : 'w-full'}`} />
            ))}
        </div>
    );
}

/** Table body placeholder matching a typical row height. */
export function SkeletonRows({ rows = 8, className = '' }: { rows?: number; className?: string }) {
    return (
        <div className={`space-y-1.5 ${className}`} aria-hidden="true">
            {Array.from({ length: rows }).map((_, index) => (
                <Skeleton key={index} className="h-11" />
            ))}
        </div>
    );
}
