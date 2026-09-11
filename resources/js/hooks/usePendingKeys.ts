import { useCallback, useRef, useState } from 'react';

/**
 * A scoped map of in-flight async actions, keyed by a string such as
 * `product:add:123` or `line:update:456`. Lets a screen show precise per-item
 * pending/disabled state instead of one global `isLoading` boolean.
 *
 * `run(key, fn)` is also a double-submit guard: a second call with a key that is
 * already pending resolves to `undefined` without invoking `fn` again.
 */
export function usePendingKeys() {
    const [keys, setKeys] = useState<ReadonlySet<string>>(() => new Set());
    const live = useRef<Set<string>>(new Set());

    const mutate = useCallback((mutateSet: (set: Set<string>) => void) => {
        const next = new Set(live.current);
        mutateSet(next);
        live.current = next;
        setKeys(next);
    }, []);

    const start = useCallback((key: string) => mutate((set) => set.add(key)), [mutate]);
    const stop = useCallback((key: string) => mutate((set) => set.delete(key)), [mutate]);

    const isPending = useCallback((key: string) => keys.has(key), [keys]);

    const anyPending = useCallback(
        (prefix?: string) => (prefix ? [...keys].some((key) => key.startsWith(prefix)) : keys.size > 0),
        [keys],
    );

    const run = useCallback(
        async <T>(key: string, fn: () => Promise<T>): Promise<T | undefined> => {
            if (live.current.has(key)) {
                return undefined;
            }
            start(key);
            try {
                return await fn();
            } finally {
                stop(key);
            }
        },
        [start, stop],
    );

    return { isPending, anyPending, run, start, stop };
}
