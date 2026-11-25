export type ContactRequestError = Error & { errors?: Record<string, string[]>; status?: number };

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

/** JSON request used by the overlay forms (dialog / drawer). 422 carries the field errors; the caller owns the page state. */
export async function contactJson<T>(url: string, method: 'POST' | 'PATCH', body: unknown): Promise<T> {
    const token = xsrfToken();
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { 'X-XSRF-TOKEN': token } : {}) },
        body: JSON.stringify(body),
    });
    if (response.ok) return (await response.json()) as T;

    const payload = (await response.json().catch(() => ({}))) as { errors?: Record<string, string[]>; message?: string };
    const error = new Error(payload.message ?? 'Request failed.') as ContactRequestError;
    error.status = response.status;
    error.errors = payload.errors;
    throw error;
}

/** First message per field, ready for the form components. */
export function firstErrors(errors: Record<string, string[] | string> | undefined): Record<string, string> {
    return Object.fromEntries(Object.entries(errors ?? {}).map(([key, value]) => [key, Array.isArray(value) ? (value[0] ?? '') : value]));
}
