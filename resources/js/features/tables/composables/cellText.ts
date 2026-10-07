import type { LocationValue } from '@/types';

/**
 * A cell as words, for searching, filtering, sorting and measuring: a list's
 * items joined, a place by its label (or else where it is), anything else as
 * itself.
 */
export function cellText(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    if (Array.isArray(value)) {
        return value.join(', ');
    }

    if (typeof value === 'object') {
        const place = value as Partial<LocationValue>;

        return (
            place.label ||
            (place.lat !== undefined ? `${place.lat}, ${place.lng}` : '')
        );
    }

    return String(value as string | number | boolean);
}
