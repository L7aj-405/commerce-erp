type JsonPrimitive = string | number | boolean | null;
type JsonValue = JsonPrimitive | JsonValue[] | { [key: string]: JsonValue };

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

export type PosRequestError = Error & { errors?: Record<string, string[]>; status?: number };

/** True when the cart no longer exists / is no longer an open draft (expired, discarded, converted). */
export function isStaleDraftError(error: unknown): boolean {
    return (error as PosRequestError | null)?.status === 404;
}

export async function posJson<T>(url: string, options: { method?: 'POST' | 'PATCH' | 'DELETE'; body?: Record<string, JsonValue> } = {}): Promise<T> {
    const response = await fetch(url, {
        method: options.method ?? 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(xsrfToken() ? { 'X-XSRF-TOKEN': xsrfToken() as string } : {}),
        },
        body: options.body ? JSON.stringify(options.body) : undefined,
    });

    if (response.ok) {
        return response.json() as Promise<T>;
    }

    if (response.status === 422) {
        const payload = await response.json() as { errors?: Record<string, string[]>; message?: string };
        const error = new Error(payload.message ?? 'Validation failed.') as PosRequestError;
        error.errors = payload.errors;
        error.status = 422;
        throw error;
    }

    const payload = await response.text();
    const failure = new Error(payload || 'POS request failed.') as PosRequestError;
    failure.status = response.status;
    throw failure;
}

/**
 * Best-effort "the cashier left an EMPTY cart" signal that survives page unload (fetch keepalive).
 * The server removes the draft only if it is still empty and history-free under its row lock, so a
 * lost, duplicated or late call is harmless; the POS page-load sweep and the scheduled command are
 * the fallbacks. Never throws.
 */
export function abandonEmptyCartKeepalive(orderId: number): void {
    try {
        void fetch(`/pos/drafts/${orderId}/abandon`, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(xsrfToken() ? { 'X-XSRF-TOKEN': xsrfToken() as string } : {}),
            },
            body: '{}',
        }).catch(() => undefined);
    } catch {
        /* unload in progress: the server-side fallbacks take over */
    }
}
