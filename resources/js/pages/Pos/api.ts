type JsonPrimitive = string | number | boolean | null;
type JsonValue = JsonPrimitive | JsonValue[] | { [key: string]: JsonValue };

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
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
        const error = new Error(payload.message ?? 'Validation failed.') as Error & { errors?: Record<string, string[]> };
        error.errors = payload.errors;
        throw error;
    }

    const payload = await response.text();
    throw new Error(payload || 'POS request failed.');
}
