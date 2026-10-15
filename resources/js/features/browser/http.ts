import { xsrfToken } from '@/lib/utils';

/**
 * Asks the app for JSON, and throws what it said when it says no: the first
 * thing wrong with what was sent, or its message.
 */
export async function ask<T>(
    route: { url: string; method: string },
    body?: unknown,
): Promise<T> {
    const response = await fetch(route.url, {
        method: route.method.toUpperCase(),
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const answer = await response.json().catch(() => ({}));

    if (!response.ok) {
        const first = Object.values(answer.errors ?? {})[0] as
            | string[]
            | undefined;

        throw new Error(
            first?.[0] ??
                answer.message ??
                `The app answered ${response.status}`,
        );
    }

    return answer as T;
}
