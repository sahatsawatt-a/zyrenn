// When something was, said the way a person would: "5 min ago", "yesterday"

const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
const STEPS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['second', 60],
    ['minute', 60],
    ['hour', 24],
    ['day', 7],
    ['week', 4.35],
    ['month', 12],
    ['year', Infinity],
];

export function ago(when: string | Date | null | undefined): string {
    if (!when) {
        return 'never';
    }

    let amount = (new Date(when).getTime() - Date.now()) / 1000;

    for (const [unit, size] of STEPS) {
        if (Math.abs(amount) < size) {
            return Math.abs(amount) < 45 && unit === 'second'
                ? 'just now'
                : relative.format(Math.round(amount), unit);
        }

        amount /= size;
    }

    return '';
}

/** How long something took: "840 ms", "12.3 s", "2 min 5 s". */
export function took(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) {
        return '—';
    }

    if (ms < 1000) {
        return `${Math.round(ms)} ms`;
    }

    if (ms < 60_000) {
        return `${(ms / 1000).toFixed(1)} s`;
    }

    return `${Math.floor(ms / 60_000)} min ${Math.round((ms % 60_000) / 1000)} s`;
}
