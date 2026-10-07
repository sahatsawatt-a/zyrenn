import { whatIsHere } from '@/lib/maps';
import * as linkPreview from '@/routes/link-preview';

// Links in a note: which may be followed at all, how a long one is written
// short, what one points at (asked of the server, which reads the page), and
// the two blocks a link can become -- a ```map of the place a map link names,
// or a ```link card. Both blocks are fences of "key: value" lines, like a
// ```trip, so they travel through Markdown as they are.

/** Schemes a link may have; anything else -- javascript:, data: -- is refused. */
export const SAFE_PROTOCOLS = ['http', 'https', 'mailto', 'tel'];

/** Whether a link may be followed: one of the safe schemes, or no scheme at all. */
export const isSafeUrl = (url: string) => {
    const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(url.trim())?.[1];

    return !scheme || SAFE_PROTOCOLS.includes(scheme.toLowerCase());
};

/**
 * A link as typed made whole: "www.example.com" and "example.com/page" are
 * https, an address with an @ is mail. Null when it is not a link to follow.
 */
export const normaliseUrl = (typed: string): string | null => {
    const url = typed.trim();

    if (!url || /\s/.test(url)) {
        return null;
    }

    if (/^[a-z][a-z0-9+.-]*:/i.test(url)) {
        return isSafeUrl(url) ? url : null;
    }

    if (/^[^@/]+@[^@/]+\.[a-z]{2,}$/i.test(url)) {
        return `mailto:${url}`;
    }

    return /^[\w-]+(\.[\w-]+)+/.test(url) ? `https://${url}` : null;
};

/** The host a link goes to, without www. */
export const hostOf = (url: string) => {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return '';
    }
};

/**
 * A long link written short, as it reads in a sentence: the site, then as
 * much of the path as fits -- "example.com/blog/a-long…".
 */
export const shortLabel = (url: string, most = 40) => {
    let parsed: URL;

    try {
        parsed = new URL(url);
    } catch {
        return url;
    }

    if (!['http:', 'https:'].includes(parsed.protocol)) {
        return url.replace(/^(mailto|tel):/i, '');
    }

    const path = decodeURIComponent(parsed.pathname + parsed.search).replace(
        /\/$/,
        '',
    );
    const whole = hostOf(url) + path;

    return whole.length <= most ? whole : `${whole.slice(0, most - 1)}…`;
};

/** A bare link on its own: what was pasted, when it is all a link. */
export const pastedUrl = (text: string): string | null => {
    const url = text.trim();

    return /^https?:\/\/\S+$/i.test(url) || /^geo:\S+$/i.test(url) ? url : null;
};

/**
 * Whether a link is to a map -- Google, OpenStreetMap or Apple Maps, or a
 * short link to one -- by its address alone, without asking. The server
 * (MapLink) says where it points.
 */
export const looksLikeMap = (url: string) => {
    try {
        const { hostname, pathname } = new URL(url);
        const host = hostname.replace(/^www\./, '');

        return (
            (/^google\.[a-z.]+$/.test(host) && pathname.startsWith('/maps')) ||
            /^maps\.google\.[a-z.]+$/.test(host) ||
            host.endsWith('openstreetmap.org') ||
            host === 'maps.apple.com' ||
            host === 'maps.app.goo.gl' ||
            (host === 'goo.gl' && pathname.startsWith('/maps'))
        );
    } catch {
        return false;
    }
};

// ---- what a link points at ----

export interface MapPlace {
    name: string;
    lat: number;
    lng: number;
    zoom: number | null;
}

export interface LinkPreview {
    /** Where the link ended up, after redirects. */
    url: string;
    kind: 'page' | 'image' | 'video' | 'map';
    site: string;
    title: string | null;
    description: string | null;
    image: string | null;
    icon: string | null;
    place: MapPlace | null;
}

const asked = new Map<string, Promise<LinkPreview>>();

/** What a link points at, asked once a page load per link. */
export const previewOf = (url: string): Promise<LinkPreview> => {
    if (!asked.has(url)) {
        asked.set(
            url,
            fetch(linkPreview.show.url({ query: { url } }), {
                headers: { Accept: 'application/json' },
            }).then((response) => {
                if (!response.ok) {
                    asked.delete(url);
                    throw new Error('That link could not be read.');
                }

                return response.json();
            }),
        );
    }

    return asked.get(url)!;
};

/** A picture a preview found, served from here rather than from its site. */
export const pictureUrl = (url: string) =>
    linkPreview.image.url({ query: { url } });

/** A site's icon, served from here. */
export const iconUrl = (host: string) =>
    linkPreview.icon.url({ query: { host } });

// ---- the fences a link can become ----

/** A fence's "key: value" lines, read; a value runs to the end of its line. */
export const readFence = (source: string): Record<string, string> =>
    Object.fromEntries(
        source
            .split('\n')
            .map((line) => /^[ \t]*([a-z]+):[ \t]*(.*?)[ \t]*$/i.exec(line))
            .filter((match): match is RegExpExecArray => match !== null)
            .map((match) => [match[1].toLowerCase(), match[2]]),
    );

/** "key: value" lines, leaving out what is empty; a newline can't get in. */
export const writeFence = (values: Record<string, string | number | null>) =>
    Object.entries(values)
        .filter(([, value]) => value !== null && value !== '')
        .map(([key, value]) => `${key}: ${String(value).replace(/\s+/g, ' ')}`)
        .join('\n');

/** One place, as a ```map fence holds it. */
export interface MapBlockPlace {
    name: string;
    address: string;
    lat: number;
    lng: number;
    zoom: number;
    /** A saved place's ref_id: it is shown as that place is now. */
    saved: string;
    /** The link it came from, if it came from one. */
    link: string;
}

export const readMapFence = (source: string): MapBlockPlace | null => {
    const values = readFence(source);
    const lat = Number(values.lat);
    const lng = Number(values.lng);

    if (
        values.lat === undefined ||
        values.lng === undefined ||
        !Number.isFinite(lat) ||
        !Number.isFinite(lng) ||
        Math.abs(lat) > 90 ||
        Math.abs(lng) > 180
    ) {
        return null;
    }

    const zoom = Number(values.zoom);

    return {
        name: values.name ?? '',
        address: values.address ?? '',
        lat,
        lng,
        zoom: Number.isFinite(zoom) && zoom >= 1 && zoom <= 20 ? zoom : 15,
        saved: values.saved ?? '',
        link: values.link ?? '',
    };
};

export const writeMapFence = (place: Partial<MapBlockPlace>) =>
    writeFence({
        name: place.name ?? '',
        address: place.address ?? '',
        lat: place.lat === undefined ? null : Number(place.lat.toFixed(6)),
        lng: place.lng === undefined ? null : Number(place.lng.toFixed(6)),
        zoom: place.zoom ?? null,
        saved: place.saved ?? '',
        link: place.link ?? '',
    });

// ---- the blocks themselves ----

/** A link as a card, its title kept for whoever reads the Markdown. */
export const cardBlock = (url: string, title: string) => ({
    type: 'codeBlock',
    attrs: { language: 'link' },
    content: [{ type: 'text', text: writeFence({ url, title }) }],
});

/**
 * The place a map link names, as a map. A link rarely carries the address,
 * so what is there is asked for; failing that, the pin is still the place.
 */
export const mapBlock = async (place: MapPlace, link: string) => {
    let found = { name: place.name, address: '' };

    try {
        const here = await whatIsHere(place.lat, place.lng);
        found = {
            name: place.name || here?.name || '',
            address: here?.address ?? '',
        };
    } catch {
        // Named by the link alone
    }

    return {
        type: 'codeBlock',
        attrs: { language: 'map' },
        content: [
            {
                type: 'text',
                text: writeMapFence({
                    ...found,
                    lat: place.lat,
                    lng: place.lng,
                    zoom: place.zoom ?? 15,
                    link,
                }),
            },
        ],
    };
};
