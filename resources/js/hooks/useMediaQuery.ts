import { useEffect, useState } from 'react';

/**
 * Tracks a CSS media query in JS, for the rare cases where a layout branch
 * genuinely needs to render different component trees per breakpoint rather
 * than just hiding/showing with CSS (e.g. POS catalogue+cart vs. a mobile
 * full-screen cart). Prefer Tailwind responsive classes when CSS alone can
 * express the difference — this is for structural, not cosmetic, branching.
 */
export function useMediaQuery(query: string): boolean {
    const [matches, setMatches] = useState(() => (typeof window !== 'undefined' ? window.matchMedia(query).matches : false));

    useEffect(() => {
        const mql = window.matchMedia(query);
        const handler = () => setMatches(mql.matches);
        handler();
        mql.addEventListener('change', handler);
        return () => mql.removeEventListener('change', handler);
    }, [query]);

    return matches;
}
