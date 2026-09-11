import { useCallback, useRef } from 'react';

/**
 * Serialises async work per key, latest-wins. While a call for `key` is running,
 * further calls only record their thunk as "the next one to run" (overwriting any
 * earlier queued one). When the running call settles, the most recent queued
 * thunk — and only that one — runs.
 *
 * This is the safe answer to POS rapid quantity / discount edits (spec §10/§11):
 * requests can never land out of order and an intermediate value (2 in 1→2→3)
 * can never be the last write.
 */
export function useSerializedByKey() {
    const running = useRef(new Map<string, boolean>());
    const queued = useRef(new Map<string, () => Promise<unknown>>());

    const drain = useCallback(async (key: string) => {
        const next = queued.current.get(key);
        if (!next) {
            running.current.delete(key);
            return;
        }
        queued.current.delete(key);
        try {
            await next();
        } finally {
            void drain(key);
        }
    }, []);

    const enqueue = useCallback(
        (key: string, thunk: () => Promise<unknown>) => {
            queued.current.set(key, thunk);
            if (running.current.get(key)) {
                return;
            }
            running.current.set(key, true);
            void drain(key);
        },
        [drain],
    );

    const isBusy = useCallback((key: string) => running.current.get(key) === true || queued.current.has(key), []);

    return { enqueue, isBusy };
}
