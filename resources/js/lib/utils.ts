import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

const relativeTimeUnits: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 60 * 60 * 24 * 365],
    ['month', 60 * 60 * 24 * 30],
    ['week', 60 * 60 * 24 * 7],
    ['day', 60 * 60 * 24],
    ['hour', 60 * 60],
    ['minute', 60],
];

export function formatRelativeTime(date: string | Date) {
    const seconds = (new Date(date).getTime() - Date.now()) / 1000;
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    for (const [unit, unitSeconds] of relativeTimeUnits) {
        if (Math.abs(seconds) >= unitSeconds) {
            return format.format(Math.round(seconds / unitSeconds), unit);
        }
    }

    return 'just now';
}

/**
 * Copies text to the clipboard. The Clipboard API only exists in secure
 * contexts (https or localhost), so plain-http LAN access falls back to a
 * hidden textarea + execCommand.
 */
export async function copyToClipboard(text: string): Promise<void> {
    if (window.isSecureContext && navigator.clipboard) {
        return navigator.clipboard.writeText(text);
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    try {
        if (!document.execCommand('copy')) {
            throw new Error('Copy command was rejected');
        }
    } finally {
        textarea.remove();
    }
}

/**
 * The CSRF token Laravel sets as a cookie, for fetch() calls outside Inertia.
 */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value < 10 ? 1 : 0)} ${units[unit]}`;
}
