/**
 * Browser checks: a few steps -- open a page, click, type, press a key, expect
 * to see something, take a picture -- done in a real Chrome, one after the
 * other, each answered with whether it worked, how long it took, and a picture
 * where one was asked for or a step failed.
 *
 * The page is anyone's, so it is kept to the public internet: everything the
 * browser fetches for it (the page, each redirect, what it loads, its
 * websockets) goes through a proxy of the check's own, which connects only to
 * public addresses -- never the app, the database or the other containers.
 * Each check has a context of its own, with no cookies, no service workers and
 * no downloads, always closed.
 *
 * Imports nothing but node's own and its neighbours here (the pointer, from
 * resources/js/features/browser/cursor.js, is mounted beside it as
 * cursor.mjs): the browser is handed in by server.mjs.
 */
import { execFile } from 'node:child_process';
import dns from 'node:dns';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import http from 'node:http';
import net from 'node:net';
import { randomUUID } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';
import ffmpeg from 'ffmpeg-static';
import { drawCursor } from './cursor.mjs';
import {
    caption,
    glideTo,
    scrollDown,
    scrollTo,
    titleCard,
    typeInto,
} from './pace.mjs';

export const KINDS = [
    'open',
    'click',
    'hover',
    'type',
    'choose',
    'upload',
    'press',
    'back',
    'scroll',
    'see',
    'gone',
    'address',
    'shot',
];

/**
 * How long a step's note stays on screen, at least (ms): long enough to see
 * and pause the film on, not to read it all -- 3 s to 8 s, the longer the
 * note the longer. The same sum is in BrowserCheck::noteHold.
 */
export const noteHold = (note) =>
    Math.min(8000, Math.max(3000, 1500 + 15 * String(note ?? '').length));

// Steps nothing is seen to happen in: with no note, a demo goes straight on
const SILENT = ['see', 'gone', 'address', 'shot'];

/**
 * How long a demo stays on a step after it is done, as its writer set it
 * (ms) -- in place of its note's hold, or its pause -- or null for those.
 */
const waitOf = (step) =>
    step.wait === undefined || step.wait === null || step.wait === ''
        ? null
        : Math.min(30_000, Math.max(0, Number(step.wait) * 1000 || 0));

const MOST_STEPS = 30;
// The most checks filmed one after another as one
const MOST_CHAPTERS = 50;
// How long each check's name is shown before it starts, in a film of several
const TITLE_HOLD = 2500;
const STEP_MAX = 10_000;
// How long a check may take at full speed; a slowed one is given longer
const CHECK_MAX = 90_000;
// A film bigger than this is left out of the answer rather than sent
const MOST_VIDEO = 80 * 1024 * 1024;

const run = promisify(execFile);

/**
 * A film as MP4 (H.264), which plays everywhere it is taken -- a phone, a
 * Mac, a deck, a chat app -- where the WebM the browser records does not.
 * Should the converting fail, the WebM it was.
 */
async function asMp4(webm) {
    const mp4 = webm.replace(/\.webm$/, '') + '.mp4';

    try {
        await run(
            ffmpeg,
            [
                '-y',
                '-loglevel',
                'error',
                '-i',
                webm,
                // H.264 wants even sides
                '-vf',
                'scale=trunc(iw/2)*2:trunc(ih/2)*2',
                '-c:v',
                'libx264',
                '-preset',
                'veryfast',
                '-crf',
                '23',
                '-pix_fmt',
                'yuv420p',
                '-movflags',
                '+faststart',
                '-an',
                mp4,
            ],
            { timeout: 120_000 },
        );

        return { bytes: await readFile(mp4), type: 'video/mp4' };
    } catch (e) {
        console.error('[film] kept as webm:', e && e.message ? e.message : e);

        return { bytes: await readFile(webm), type: 'video/webm' };
    }
}
const AT_ONCE = 2;

let running = 0;

/** Whether an IPv4 address (as four numbers) is somewhere on the internet. */
function publicV4([a, b, c]) {
    return !(
        a === 0 ||
        a === 10 ||
        a === 127 ||
        a >= 224 ||
        (a === 100 && b >= 64 && b <= 127) ||
        (a === 169 && b === 254) ||
        (a === 172 && b >= 16 && b <= 31) ||
        (a === 192 && b === 168) ||
        (a === 192 && b === 0 && (c === 0 || c === 2)) ||
        (a === 198 && (b === 18 || b === 19))
    );
}

export function publicAddress(ip) {
    if (net.isIPv4(ip)) {
        return publicV4(ip.split('.').map(Number));
    }

    const v6 = ip.toLowerCase();
    // An IPv4 address written as IPv6 is that address
    const mapped = /^::ffff:(\d+\.\d+\.\d+\.\d+)$/.exec(v6);

    if (mapped) {
        return publicAddress(mapped[1]);
    }

    return !(
        v6 === '::' ||
        v6 === '::1' ||
        /^f[cd]/.test(v6) ||
        /^fe[89ab]/.test(v6) ||
        v6.startsWith('ff') ||
        v6.startsWith('64:ff9b:')
    );
}

/**
 * A lookup that only ever answers with public addresses: the connection is
 * made to the very address that was checked, so a name cannot answer one way
 * when asked and another when used.
 */
function publicLookup(refused) {
    return (hostname, options, callback) =>
        dns.lookup(hostname, { all: true }, (error, found) => {
            if (
                !error &&
                found.some(({ address }) => !publicAddress(address))
            ) {
                refused.add(hostname);
                error = Object.assign(new Error(`${hostname} is not public`), {
                    code: 'ENOTPUBLIC',
                });
            }

            if (error) {
                return callback(error);
            }

            // An IPv4 address first: this network may have no way out on IPv6
            const first = found.find(({ family }) => family === 4) ?? found[0];

            return options?.all
                ? callback(null, found)
                : callback(null, first.address, first.family);
        });
}

// How the proxy marks a page it would not fetch, for the step that opened it
const REFUSED = { 'X-Check-Refused': '1' };

/**
 * The proxy a check's browser goes through for everything it fetches -- the
 * page, every redirect, what the page loads, its websockets -- so nothing it
 * does reaches this network. Answers with its address and a way to close it.
 */
async function publicProxy(refused) {
    const lookup = publicLookup(refused);
    const literal = (host) => {
        const bare = host.replace(/^\[|\]$/g, '');

        if (net.isIP(bare) && !publicAddress(bare)) {
            refused.add(bare);

            return false;
        }

        return true;
    };

    const server = http.createServer((request, response) => {
        let target;

        try {
            target = new URL(request.url);
        } catch {
            return response.writeHead(400).end();
        }

        if (target.protocol !== 'http:' || !literal(target.hostname)) {
            return response.writeHead(403, REFUSED).end();
        }

        const onward = http.request(
            {
                host: target.hostname.replace(/^\[|\]$/g, ''),
                port: target.port || 80,
                path: `${target.pathname}${target.search}`,
                method: request.method,
                headers: request.headers,
                lookup,
                autoSelectFamily: false,
            },
            (answer) => {
                response.writeHead(answer.statusCode ?? 502, answer.headers);
                answer.pipe(response);
            },
        );
        onward.on('error', () => response.writeHead(403, REFUSED).end());
        request.pipe(onward);
    });

    // https and every websocket: a tunnel, to a public address only
    server.on('connect', (request, client, head) => {
        const [host, port] = request.url.split(/:(?=\d+$)/);

        if (!literal(host)) {
            return client.end('HTTP/1.1 403 Forbidden\r\n\r\n');
        }

        const onward = net.connect(
            {
                host: host.replace(/^\[|\]$/g, ''),
                port: Number(port) || 443,
                lookup,
                // One address, waited for: trying each in turn gives each only
                // a quarter of a second, too short for a server far away
                autoSelectFamily: false,
            },
            () => {
                client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
                onward.write(head);
                onward.pipe(client);
                client.pipe(onward);
            },
        );
        onward.on('error', () => client.end('HTTP/1.1 403 Forbidden\r\n\r\n'));
        client.on('error', () => onward.destroy());
    });

    // A plain ws:// upgrade the browser sends without a tunnel
    server.on('upgrade', (request, client) => client.destroy());

    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

    return {
        address: `http://127.0.0.1:${server.address().port}`,
        close: () =>
            new Promise((resolve) => {
                server.closeAllConnections?.();
                server.close(resolve);
            }),
    };
}

/**
 * What a step's target names, found the way a person looks: a button first,
 * then a link, a field, and last any words on the page -- words written just
 * so before ones that only hold them. Something to type into is only ever a
 * field. Answers once one of them is showing, or throws when none turns up.
 */
function candidates(
    page,
    target,
    field = false,
    where = null,
    frame = null,
    nth = 1,
) {
    // Inside a frame of the page -- a card form, an embedded sign-up -- when
    // that is where it was picked from; or a frame itself, looked in
    const root =
        typeof frame === 'string' ? page.frameLocator(frame) : (frame ?? page);
    const fieldsOf = (exact) =>
        root
            .getByLabel(target, { exact })
            .and(root.locator('input, textarea, select, [contenteditable]'))
            .or(root.getByPlaceholder(target, { exact }))
            .or(root.getByRole('textbox', { name: target, exact }))
            .or(root.getByRole('searchbox', { name: target, exact }))
            .or(root.getByRole('combobox', { name: target, exact }))
            // A list nothing names, by the option it shows
            .or(
                root.locator('select').filter({
                    has: root.locator('option:checked', {
                        hasText: exact
                            ? new RegExp(`^\\s*${RegExp.escape(target)}\\s*$`)
                            : target,
                    }),
                }),
            );
    const kinds = (exact) =>
        field
            ? [fieldsOf(exact)]
            : [
                  root.getByRole('button', { name: target, exact }),
                  root.getByRole('link', { name: target, exact }),
                  root.getByRole('checkbox', { name: target, exact }),
                  root.getByRole('radio', { name: target, exact }),
                  root.getByRole('tab', { name: target, exact }),
                  root.getByRole('menuitem', { name: target, exact }),
                  root.getByRole('option', { name: target, exact }),
                  fieldsOf(exact),
                  root.getByText(target, { exact }),
              ];

    // Where it was picked from the page first, when it has no words to go
    // by; then by its words, should the page have changed
    // The 3rd "Add to cart", when said by its words alone
    const which = Math.max(0, (Number(nth) || 1) - 1);

    return [
        ...(where
            ? [root.locator(where).filter({ visible: true }).first()]
            : []),
        ...[...kinds(true), ...kinds(false)].map((one) =>
            one.filter({ visible: true }).nth(which),
        ),
    ];
}

/**
 * What a step's target names, found the way a person looks: a button first,
 * then a link, a field, and last any words on the page -- words written just
 * so before ones that only hold them. Something to type into is only ever a
 * field. Answers once one of them is showing, or throws when none turns up.
 */
async function locate(
    page,
    target,
    field = false,
    where = null,
    frame = null,
    nth = 1,
) {
    const order = candidates(page, target, field, where, frame, nth);
    const began = Date.now();
    const deadline = began + STEP_MAX;

    do {
        for (const one of order) {
            if (await one.count().catch(() => 0)) {
                return one;
            }
        }

        // Not on the page itself after a moment: in one of its frames, for
        // a step written without saying which
        if (!frame && Date.now() - began > 1500) {
            for (const inside of page
                .frames()
                .filter((one) => one.parentFrame() === page.mainFrame())
                .slice(0, 8)) {
                for (const one of candidates(
                    page,
                    target,
                    field,
                    null,
                    inside,
                    nth,
                )) {
                    if (await one.count().catch(() => 0)) {
                        return one;
                    }
                }
            }
        }

        await page.waitForTimeout(150);
    } while (Date.now() < deadline);

    throw new Error(`Timeout ${STEP_MAX}ms exceeded`);
}

/** Whether anything showing goes by `target` now, without waiting for it. */
async function showingNow(page, target, frame = null) {
    for (const one of candidates(page, target, false, null, frame)) {
        if (await one.count().catch(() => 0)) {
            return true;
        }
    }

    return false;
}

// The tallest picture of a page a look takes, in pixels
const LOOK_MOST = 2400;

/**
 * What can be gone by on the page, as a step names it -- each field by its
 * label or placeholder, each button and link by its words, and short pieces
 * of writing -- where each one is on the page, and how many share a name.
 * Run in the page.
 */
const thingsOnPage = () => {
    const clean = (text) => (text ?? '').replace(/\s+/g, ' ').trim();
    // The page and every open shadow root in it: a site built of web
    // components keeps its buttons and fields in those
    const roots = [document];

    for (let at = 0; at < roots.length && roots.length < 500; at++) {
        for (const element of roots[at].querySelectorAll('*')) {
            if (element.shadowRoot) {
                roots.push(element.shadowRoot);
            }
        }
    }

    const deepAll = (selector) =>
        roots.flatMap((root) => [...root.querySelectorAll(selector)]);
    // What holds it, out of a shadow root to its host
    const upOf = (node) =>
        node.parentElement ??
        (node.parentNode instanceof ShadowRoot ? node.parentNode.host : null);
    const showing = (element) => {
        const box = element.getBoundingClientRect();
        const style = getComputedStyle(element);

        // See-through still counts, as it does for a step: a tick box drawn
        // by the page over a real one is often an invisible input. A closed
        // menu -- slid off the side, or marked hidden -- is not showing.
        if (
            box.width <= 2 ||
            box.height <= 2 ||
            style.visibility === 'hidden' ||
            box.right <= 0 ||
            box.left >= innerWidth ||
            element.closest('[aria-hidden="true"], [inert]')
        ) {
            return false;
        }

        // Covered by something drawn over it -- a dropdown, a dialog -- is
        // not showing either: what is on top at its middle is it, inside it,
        // around it, or its label. Only what is on screen can be asked; what
        // is further down the page is taken as it is.
        const x = Math.min(
            innerWidth - 1,
            Math.max(0, box.left + box.width / 2),
        );
        const y = box.top + box.height / 2;

        if (y < 0 || y >= innerHeight) {
            return true;
        }

        // Asked of its own root, so a thing in a shadow root is not taken
        // as covered by the component it is in
        const root = element.getRootNode();
        const top = (root.elementFromPoint ? root : document).elementFromPoint(
            x,
            y,
        );

        return (
            !top ||
            element.contains(top) ||
            top.contains(element) ||
            [...(element.labels ?? [])].some((label) => label.contains(top))
        );
    };
    const labelOf = (element) => {
        const aria = element.getAttribute('aria-label');

        if (clean(aria)) {
            return clean(aria);
        }

        const by = element.getAttribute('aria-labelledby');
        const named = by
            ? clean(
                  by
                      .split(/\s+/)
                      .map(
                          (id) =>
                              (
                                  element.getRootNode().getElementById?.(id) ??
                                  document.getElementById(id)
                              )?.textContent,
                      )
                      .join(' '),
              )
            : '';

        if (named) {
            return named;
        }

        const home = element.getRootNode();
        const forIt = element.id
            ? (home.querySelector ? home : document).querySelector(
                  `label[for="${CSS.escape(element.id)}"]`,
              )
            : null;

        const label = forIt ?? element.closest('label');

        if (!label) {
            return '';
        }

        // A label around its list or box: its own words, not the options in it
        const words = label.cloneNode(true);
        words
            .querySelectorAll('select, textarea, datalist')
            .forEach((inner) => inner.remove());

        return clean(words.textContent);
    };
    const things = [];
    // Where something is in the page, as a CSS path -- for what has no
    // words to be found by: a picture, a dropdown nothing names
    const pathOf = (element) => {
        const parts = [];
        const root = element.getRootNode();

        for (
            let node = element;
            node && node !== document.body && node.parentNode !== null;
            node = node.parentElement
        ) {
            if (
                node.id &&
                /^[A-Za-z][\w-]*$/.test(node.id) &&
                root.querySelectorAll(`#${CSS.escape(node.id)}`).length === 1
            ) {
                parts.unshift(`#${CSS.escape(node.id)}`);
                break;
            }

            const tag = node.tagName.toLowerCase();
            const same = [...(node.parentNode?.children ?? [])].filter(
                (other) => other.tagName === node.tagName,
            );
            parts.unshift(
                same.length > 1
                    ? `${tag}:nth-of-type(${same.indexOf(node) + 1})`
                    : tag,
            );

            if (!node.parentElement) {
                break;
            }
        }

        const inside = parts.join(' > ');

        // In a shadow root: where its host is, then where it is in there
        // (a step's locator looks into shadow roots)
        if (root instanceof ShadowRoot) {
            return `${pathOf(root.host)} ${inside}`;
        }

        return parts[0]?.startsWith('#') ? inside : `body > ${inside}`;
    };

    // What a picture with no words of its own is near: the first words of
    // what holds it, e.g. a search result's title
    const besideOf = (element) => {
        for (
            let node = upOf(element);
            node && node !== document.body;
            node = upOf(node)
        ) {
            const words = clean(node.innerText).split('\n')[0];

            if (words) {
                return words.slice(0, 40);
            }
        }

        return '';
    };

    // Each thing, and the element it is, for those that share a name
    const elements = [];
    const add = (
        kind,
        element,
        name,
        where = null,
        drawn = element,
        more = {},
    ) => {
        name = clean(name).slice(0, 80);

        if (!name || things.length >= 400) {
            return;
        }

        // Up to fifty that share a name -- a list of results each with its
        // "comments" -- as many as a step can tell apart
        if (
            things.filter((thing) => thing.kind === kind && thing.name === name)
                .length >= 50
        ) {
            return;
        }

        const box = drawn.getBoundingClientRect();
        elements.push(element);
        things.push({
            kind,
            name,
            count: 1,
            ...(where ? { selector: where } : {}),
            ...more,
            box: {
                x: Math.round(box.left + scrollX),
                y: Math.round(box.top + scrollY),
                w: Math.round(box.width),
                h: Math.round(box.height),
            },
        });
    };

    // What a field is for, when nothing names it
    const FIELD_SAID = {
        range: 'Slider',
        date: 'Date',
        time: 'Time',
        'datetime-local': 'Date and time',
        month: 'Month',
        week: 'Week',
        color: 'Colour',
        file: 'File',
        number: 'Number',
        email: 'Email',
        password: 'Password',
        search: 'Search',
    };

    for (const element of deepAll(
        'input, textarea, select, [contenteditable="true"], [role="textbox"], [role="searchbox"], [role="combobox"]',
    )) {
        const type = (element.getAttribute('type') ?? '').toLowerCase();

        if (type === 'hidden') {
            continue;
        }

        if (!showing(element)) {
            // A file field hidden behind its own button or label: where
            // that is, as a person sees it
            const label = element.labels?.[0];

            if (type === 'file' && label && showing(label)) {
                add(
                    'file',
                    element,
                    labelOf(element) || 'Choose a file',
                    pathOf(element),
                    label,
                );
            }

            // A dropdown drawn by the site around a field too narrow to see
            // (a search-as-you-type one): the box it shows, by what is in it
            if (element.matches('[role="combobox"]')) {
                let box = element.parentElement;

                for (let up = 0; box && up < 4; up++, box = box.parentElement) {
                    const { width, height } = box.getBoundingClientRect();

                    if (width >= 40 && height >= 20) {
                        break;
                    }
                }

                if (box && showing(box)) {
                    add(
                        'button',
                        box,
                        labelOf(element) ||
                            clean(box.innerText).split('\n')[0] ||
                            'A dropdown',
                        pathOf(box),
                    );
                }
            }

            continue;
        }

        if (['submit', 'button', 'reset'].includes(type)) {
            add('button', element, element.value || labelOf(element));
        } else if (['checkbox', 'radio'].includes(type)) {
            add(type, element, labelOf(element));
        } else if (element instanceof HTMLSelectElement) {
            // A list the browser draws itself, outside the page: its options
            // are listed here to choose from, as no picture can show them
            const named = labelOf(element) || element.getAttribute('title');
            const chosen = clean(element.selectedOptions[0]?.textContent);
            add(
                'list',
                element,
                named || chosen,
                named ? null : pathOf(element),
                element,
                {
                    options: [...element.options]
                        .filter((option) => !option.disabled)
                        .map((option) => clean(option.textContent))
                        .filter(Boolean)
                        .slice(0, 500),
                    chosen,
                },
            );
        } else {
            const named =
                labelOf(element) ||
                element.getAttribute('placeholder') ||
                element.getAttribute('title');
            // A slider's ends, a date's form: what to type into it
            const more =
                element instanceof HTMLInputElement && FIELD_SAID[type]
                    ? {
                          input: type,
                          ...(type === 'range'
                              ? {
                                    min: element.min || '0',
                                    max: element.max || '100',
                                }
                              : {}),
                      }
                    : {};
            const kind = type === 'file' ? 'file' : 'field';

            if (named) {
                add(kind, element, named, null, element, more);
            } else if (
                element.matches('[role="combobox"]') &&
                clean(element.innerText)
            ) {
                // A dropdown the site draws, nothing names: by what it shows,
                // and where it is -- its menu opens on the page, to click on
                add('button', element, element.innerText, pathOf(element));
            } else {
                // Nothing names it: by what it is and what it is beside, and
                // found by where it is
                const beside = besideOf(element);
                const said =
                    FIELD_SAID[type] ??
                    (element.matches('[role="combobox"]') ? 'Dropdown' : 'Box');
                add(
                    kind,
                    element,
                    beside ? `${said} beside "${beside}"` : said,
                    pathOf(element),
                    element,
                    more,
                );
            }
        }
    }

    for (const element of deepAll(
        'button, [role="button"], a[href], [role="link"], [role="tab"], [role="menuitem"], [role="option"]',
    )) {
        if (!showing(element)) {
            continue;
        }

        // No words of its own -- a thumbnail, a placeholder, a bare icon:
        // a picture, named by what its picture says, its tooltip, or what
        // it is beside, and found by where it is
        if (!clean(element.innerText) && !labelOf(element)) {
            const box = element.getBoundingClientRect();
            const drawn = element.querySelector(
                'img, svg[role="img"], [role="img"]',
            );
            const said = clean(
                drawn?.getAttribute('alt') ||
                    drawn?.getAttribute('aria-label') ||
                    element.getAttribute('title'),
            );
            const beside = besideOf(element);
            const larger =
                drawn && drawn.getBoundingClientRect().height > box.height
                    ? drawn
                    : element;
            const shown = larger.getBoundingClientRect();

            if (shown.width >= 24 && shown.height >= 24) {
                add(
                    'picture',
                    element,
                    said ||
                        (beside ? `Picture beside "${beside}"` : 'A picture'),
                    pathOf(element),
                    // Drawn where the picture is, when that is larger
                    larger,
                );
            }

            continue;
        }

        {
            add(
                element.matches('a, [role="link"]')
                    ? 'link'
                    : element.matches(
                            '[role="tab"], [role="menuitem"], [role="option"]',
                        )
                      ? 'tab'
                      : 'button',
                element,
                // As it reads on screen, the gaps between its parts kept
                labelOf(element) ||
                    element.innerText ||
                    element.textContent ||
                    element.getAttribute('title'),
            );
        }
    }

    // Pictures standing on their own that say what they are: ones to
    // expect to see (one in a link or button is listed with it, above)
    for (const element of deepAll('img, svg[role="img"], [role="img"]')) {
        const box = element.getBoundingClientRect();
        const said = clean(
            element.getAttribute('alt') ||
                element.getAttribute('aria-label') ||
                element.getAttribute('title'),
        );

        if (
            box.width >= 24 &&
            box.height >= 24 &&
            said &&
            showing(element) &&
            !element.closest('a[href], button, [role="button"], [role="link"]')
        ) {
            add('picture', element, said, pathOf(element));
        }
    }

    // Writing of its own, not a button's or a link's
    for (const element of deepAll(
        'h1, h2, h3, h4, h5, h6, p, li, label, td, th, dt, dd, span, div, strong, em, b, i, u, small, mark, code, pre, blockquote, figcaption, caption, legend, output, time, section, article, main, header, footer, aside, nav, body',
    )) {
        if (
            !showing(element) ||
            element.closest('button, a, [role="button"], [role="link"]')
        ) {
            continue;
        }

        const own = clean(
            [...element.childNodes]
                .filter((node) => node.nodeType === Node.TEXT_NODE)
                .map((node) => node.textContent)
                .join(' '),
        );

        // Words, not only marks: "|" or "( )" between links is not writing
        if (own.length >= 2 && own.length <= 80 && /[\p{L}\p{N}]/u.test(own)) {
            add('words', element, own);
        }
    }

    // Those that share a name: which one each is, and where, so a step can
    // go to the one picked rather than the first
    things.forEach((thing, at) => {
        const same = things.filter(
            (other) => other.kind === thing.kind && other.name === thing.name,
        );

        if (same.length > 1) {
            thing.count = same.length;
            thing.nth = same.indexOf(thing) + 1;
            thing.selector ??= pathOf(elements[at]);
        }
    });

    return {
        things,
        // The frames showing on it, for what is in them to be listed too
        frames: [...document.querySelectorAll('iframe')]
            .map((iframe, index) => {
                const box = iframe.getBoundingClientRect();

                return box.width >= 40 && box.height >= 24 && showing(iframe)
                    ? {
                          index,
                          path: pathOf(iframe),
                          x: Math.round(box.left + scrollX + iframe.clientLeft),
                          y: Math.round(box.top + scrollY + iframe.clientTop),
                          w: iframe.clientWidth,
                          h: iframe.clientHeight,
                      }
                    : null;
            })
            .filter(Boolean),
        scroll: { x: scrollX, y: scrollY },
        height: document.documentElement.scrollHeight,
    };
};

const picture = async (page) =>
    `data:image/jpeg;base64,${(
        await page.screenshot({ type: 'jpeg', quality: 70, timeout: STEP_MAX })
    ).toString('base64')}`;

/** Plain words for what Playwright says went wrong. */
function plainly(error, step) {
    const message = String(error?.message ?? error).split('\n')[0];

    if (message === 'BOT_WALL') {
        return 'The site stopped the check at a page asking whether a person is there (a bot check), so it went no further. Ask whoever runs the site to let checks in, or check a copy of it without that page.';
    }

    if (message === 'SIGN_IN_FIRST') {
        return 'The site asks for a name and password before it shows the page. Put them in the address, as https://{{user}}:{{password}}@the-site, with both kept as hidden values.';
    }

    if (/Download is starting/i.test(message)) {
        return 'That address downloads a file (a PDF, say) rather than showing a page, so there is nothing on it to check.';
    }

    if (message === 'NO_FILE') {
        return 'Say which file to add.';
    }

    if (message === 'NO_BACK') {
        return 'There was no page before this one to go back to.';
    }

    if (message === 'STILL_THERE') {
        return `"${step.target}" was still showing after ${STEP_MAX / 1000} seconds.`;
    }

    if (message.startsWith('NOT_AT ')) {
        return `The page is at ${message.slice(7)}, which does not have "${step.text}" in it.`;
    }

    if (message === 'NO_OPTION') {
        return `There is no "${step.text}" to choose in "${step.target}".`;
    }

    if (/Timeout .* exceeded/i.test(message)) {
        return step.do === 'open'
            ? 'The page did not load in time.'
            : `Nothing showing "${step.target}" turned up in ${STEP_MAX / 1000} seconds.`;
    }

    if (
        /ERR_TUNNEL_CONNECTION_FAILED|ERR_BLOCKED_BY_CLIENT|ERR_HTTP_RESPONSE_CODE_FAILURE|ERR_PROXY/.test(
            message,
        )
    ) {
        return 'That address is not on the public internet, so it was not opened.';
    }

    if (/ERR_NAME_NOT_RESOLVED/.test(message)) {
        return 'No site has that name.';
    }

    return message.replace(/^\w+\.\w+: /, '').slice(0, 300);
}

/**
 * How fast a check goes, as asked: how long the pointer glides to a thing
 * before pressing it (ms), how long each key takes (ms), and the pause after
 * each step (ms) -- all 0 for as fast as the browser goes. Kept within reason.
 */
function paceOf(asked) {
    const within = (value, most) =>
        Math.min(most, Math.max(0, Math.round(Number(value) || 0)));

    return {
        glide: within(asked?.glide, 2000),
        typing: within(asked?.typing, 400),
        pause: within(asked?.pause, 5000),
    };
}

// The drawn pointer, following the real mouse at the check's glide: the same
// one a watched browser suite shows (resources/js/features/browser/cursor.js)
const cursorScript = (glide) =>
    `(${String((draw, glide) => {
        const follow = () => {
            if (!document.body || document.getElementById('e2e-cursor')) {
                return;
            }

            const cursor = draw();
            addEventListener(
                'mousemove',
                (event) => cursor.moveTo(event.clientX, event.clientY, glide),
                true,
            );
            addEventListener(
                'mousedown',
                (event) => cursor.press(event.clientX, event.clientY),
                true,
            );
        };

        if (document.readyState === 'loading') {
            addEventListener('DOMContentLoaded', follow);
        } else {
            follow();
        }
    })})(${String(drawCursor)}, ${glide})`;

async function act(page, step, pace) {
    const watched = pace.glide > 0;
    const find = (field = false) =>
        locate(page, step.target, field, step.selector, step.frame, step.nth);

    switch (step.do) {
        case 'open': {
            const answer = await page.goto(step.url, {
                waitUntil: 'domcontentloaded',
                timeout: STEP_MAX * 2,
            });

            if (answer?.headers()['x-check-refused']) {
                throw new Error('net::ERR_BLOCKED_BY_CLIENT');
            }

            // Asking for a name and password before the page, as the
            // browser's own box does
            if (
                answer?.status() === 401 &&
                answer.headers()['www-authenticate']
            ) {
                throw new Error('SIGN_IN_FIRST');
            }

            if (answer && answer.status() >= 400) {
                // A page asking whether this is a person, not the site
                const wall = await page
                    .evaluate(() =>
                        /just a moment|attention required|verify you are (a )?human|are you a robot|captcha|access denied|checking your browser/i.test(
                            `${document.title} ${(document.body?.innerText ?? '').slice(0, 3000)}`,
                        ),
                    )
                    .catch(() => false);

                const said = {
                    401: 'not allowed without signing in',
                    403: 'not allowed',
                    404: 'no such page',
                    410: 'gone',
                    429: 'asked too often',
                    500: 'the site went wrong',
                    502: 'the site is down',
                    503: 'the site is down for now',
                    504: 'the site took too long',
                }[answer.status()];

                throw new Error(
                    wall
                        ? 'BOT_WALL'
                        : `The page answered ${answer.status()}${said ? ` (${said})` : ''}.`,
                );
            }

            // Its pictures and styles too, as far as they come in a few
            // seconds: a page with a tracker that never finishes is still open
            await page
                .waitForLoadState('load', { timeout: 5000 })
                .catch(() => {});
            break;
        }
        case 'click': {
            const thing = await find();
            if (watched) await glideTo(thing, pace.glide);
            await thing.click({ timeout: STEP_MAX });
            break;
        }
        case 'hover': {
            // Pointed at and left there: a menu that opens on its own, a
            // picture's caption that shows only then
            const thing = await find();
            if (watched) await glideTo(thing, pace.glide);
            await thing.hover({ timeout: STEP_MAX });
            break;
        }
        case 'type': {
            const thing = await find(true);
            const input = await thing
                .evaluate((element) => element.type ?? '')
                .catch(() => '');
            if (watched) await glideTo(thing, pace.glide);

            if (['range', 'color'].includes(input)) {
                // A slider is set, as dragging it would
                await thing.evaluate((element, value) => {
                    // Through the browser's own setter, so a site that
                    // watches its fields (React) hears of it
                    Reflect.set(
                        HTMLInputElement.prototype,
                        'value',
                        value,
                        element,
                    );
                    element.dispatchEvent(
                        new Event('input', { bubbles: true }),
                    );
                    element.dispatchEvent(
                        new Event('change', { bubbles: true }),
                    );
                }, step.text ?? '');
            } else if (
                (watched || pace.typing > 0) &&
                !['date', 'time', 'datetime-local', 'month', 'week'].includes(
                    input,
                )
            ) {
                // A date is filled whole: typed key by key, its parts are
                // read in the order the browser's language puts them
                await typeInto(thing, step.text ?? '', pace.typing, {
                    options: { timeout: STEP_MAX },
                });
            } else {
                await thing.fill(step.text ?? '', { timeout: STEP_MAX });
            }
            break;
        }
        case 'choose': {
            const thing = await find(true);
            if (watched) await glideTo(thing, pace.glide);

            // By the words an option shows, or else its value -- whichever
            // the list has, looked for without waiting on the one it lacks,
            // as the options may still be coming in
            const text = step.text ?? '';
            const deadline = Date.now() + STEP_MAX;
            let by = null;

            while (!by) {
                by = await thing
                    .evaluate((element, text) => {
                        // Not a list of the browser's own: said so below
                        if (element.tagName !== 'SELECT') {
                            return 'label';
                        }

                        const options = [...element.options];

                        if (options.some((one) => one.label.trim() === text)) {
                            return 'label';
                        }

                        return options.some((one) => one.value === text)
                            ? 'value'
                            : null;
                    }, text)
                    .catch(() => null);

                if (!by) {
                    if (Date.now() > deadline) {
                        throw new Error('NO_OPTION');
                    }

                    await page.waitForTimeout(150);
                }
            }

            await thing
                .selectOption(
                    by === 'label' ? { label: text } : { value: text },
                    {
                        timeout: STEP_MAX,
                    },
                )
                .catch(() => {
                    throw new Error('NO_OPTION');
                });
            break;
        }
        case 'upload': {
            if (!step.file?.data) {
                throw new Error('NO_FILE');
            }

            const file = {
                name: step.file.name || 'file',
                mimeType: step.file.type || 'application/octet-stream',
                buffer: Buffer.from(step.file.data, 'base64'),
            };
            const root = step.frame ? page.frameLocator(step.frame) : page;
            // The file field itself, though a site often hides it behind a
            // button of its own
            const field = step.selector
                ? root.locator(step.selector).first()
                : root
                      .getByLabel(step.target ?? '')
                      .and(root.locator('input[type=file]'))
                      .first();

            // Looked for, not waited for: a missing one would hold the step
            // for Playwright's own 30 s before the button was tried
            if (
                (await field.count().catch(() => 0)) &&
                (await field
                    .evaluate((element) => element.type === 'file', null, {
                        timeout: 1000,
                    })
                    .catch(() => false))
            ) {
                await field.setInputFiles(file, { timeout: STEP_MAX });
                break;
            }

            // A button that asks for a file: the file given when it asks
            const thing = await find();
            if (watched) await glideTo(thing, pace.glide);
            const [chooser] = await Promise.all([
                page.waitForEvent('filechooser', { timeout: STEP_MAX }),
                thing.click({ timeout: STEP_MAX }),
            ]);
            await chooser.setFiles(file);
            break;
        }
        case 'press':
            await page.keyboard.press(step.key);
            break;
        case 'back': {
            const back = await page.goBack({
                waitUntil: 'domcontentloaded',
                timeout: STEP_MAX * 2,
            });

            if (!back) {
                throw new Error('NO_BACK');
            }
            break;
        }
        case 'scroll': {
            // To a thing further down, or else a screen further down: for
            // showing a long page, gently
            if (step.target) {
                const thing = await find();
                await scrollTo(thing);
                await thing.scrollIntoViewIfNeeded({ timeout: STEP_MAX });
            } else {
                await scrollDown(page);
            }
            break;
        }
        case 'see': {
            const thing = await find();
            // Pointed at, for whoever watches the film
            if (watched) await glideTo(thing, pace.glide);
            break;
        }
        case 'gone': {
            // Gone, or never there: given the time a step has to go
            const deadline = Date.now() + STEP_MAX;

            while (await showingNow(page, step.target, step.frame)) {
                if (Date.now() > deadline) {
                    throw new Error('STILL_THERE');
                }

                await page.waitForTimeout(150);
            }
            break;
        }
        case 'address': {
            // The address the page is at has these words in it, as it reads
            const has = () => {
                const at = page.url();
                let read = at;

                try {
                    read = decodeURI(at);
                } catch {
                    // Left as it is
                }

                return [at, read].some((one) =>
                    one.toLowerCase().includes(String(step.text).toLowerCase()),
                );
            };
            const deadline = Date.now() + STEP_MAX;

            while (!has()) {
                if (Date.now() > deadline) {
                    throw new Error(`NOT_AT ${page.url()}`);
                }

                await page.waitForTimeout(150);
            }
            break;
        }
        case 'shot':
            break;
    }
}

// The sizes a page is drawn at: a desktop's window, a tablet held upright, a
// phone -- the last two as touch screens that say they are, so a site lays
// itself out as it would there
export const SIZES = {
    desktop: { viewport: { width: 1280, height: 800 } },
    tablet: {
        viewport: { width: 820, height: 1180 },
        isMobile: true,
        hasTouch: true,
        userAgent:
            'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
    },
    phone: {
        viewport: { width: 390, height: 844 },
        isMobile: true,
        hasTouch: true,
        userAgent:
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
    },
};

const sizeOf = (name) => SIZES[name] ?? SIZES.desktop;

// Adverts and what counts visitors: asked for by a page on and on, so never
// waited for -- a page is settled once its own requests are answered
// A site's own line kept open for news -- held until there is some, so
// never waited for either
const HELD_OPEN =
    /socket\.io\/|sockjs|transport=(polling|longpoll)|\/signalr\/|\/longpoll|[?&]poll(ing)?=|\/realtime\/|\/pusher\//i;
// A request of its data still waiting this long is taken as one of those
const LONG_HELD = 5000;
const COUNTING =
    /^https?:\/\/([^/]+\.)?(doubleclick\.net|googlesyndication\.com|googleadservices\.com|google-analytics\.com|googletagmanager\.com|googletagservices\.com|adservice\.google\.[a-z.]+|pagead2\.googlesyndication\.com|fundingchoicesmessages\.google\.com|facebook\.(net|com)\/tr|connect\.facebook\.net|analytics\.tiktok\.com|ads\.linkedin\.com|px\.ads\.linkedin\.com|bat\.bing\.com|clarity\.ms|hotjar\.(com|io)|segment\.(io|com)|mixpanel\.com|amplitude\.com|amazon-adsystem\.com|adnxs\.com|criteo\.(com|net)|taboola\.com|outbrain\.com|pubmatic\.com|rubiconproject\.com|scorecardresearch\.com|quantserve\.com|moatads\.com|2mdn\.net|adsrvr\.org|sentry\.io|nr-data\.net|newrelic\.com|cloudflareinsights\.com|adtrafficquality\.google|plausible\.io|stats\.wp\.com|yandex\.ru\/(metrika|watch)|mc\.yandex\.ru)/i;

/**
 * A page of its own, at `size`, behind the check's own public-only proxy:
 * no cookies, no service workers, no downloads; ngrok's warning page passed;
 * "Are you sure?" answered yes; and what it is still waiting for followed,
 * so `settled()` can wait for what a click set going. `close()` always.
 */
async function openPage(
    browser,
    { size = 'desktop', glide = 0, films = null, state = null } = {},
) {
    const refused = new Set();
    const proxy = await publicProxy(refused);
    const { viewport, ...device } = sizeOf(size);
    let context;

    try {
        context = await browser.newContext({
            viewport,
            ...device,
            serviceWorkers: 'block',
            acceptDownloads: false,
            // Even this machine's own names go through it
            proxy: { server: proxy.address, bypass: '<-loopback>' },
            ...(films ? { recordVideo: { dir: films, size: viewport } } : {}),
            // Signed in already: the cookies and storage a sign-in left
            ...(state ? { storageState: state } : {}),
        });
    } catch (error) {
        await proxy.close();
        throw error;
    }

    if (glide > 0 || films) {
        await context.addInitScript(cursorScript(glide || 160));
    }

    // A free ngrok address shows a warning page before the site: told not
    // to, on those addresses alone -- sent anywhere else, the header is
    // refused by some (a payment form, a font service)
    await context.route(
        /^https?:\/\/[^/]+\.ngrok(-free)?\.(app|dev|io)\//,
        (route) =>
            route.continue({
                headers: {
                    ...route.request().headers(),
                    'ngrok-skip-browser-warning': '1',
                },
            }),
    );

    const page = await context.newPage();

    // What the page is still waiting for -- itself, and what it asks for
    // its data -- to tell when what a click set going has arrived
    const busy = new Map();
    let lastBusy = Date.now();
    // The page's own -- not an advert's frame, nor what it sends to be
    // counted, which can go on for ever
    page.on('request', (request) => {
        let own = true;

        try {
            own = request.frame() === page.mainFrame();
        } catch {
            // A request no frame made
        }

        if (
            own &&
            ['document', 'xhr', 'fetch'].includes(request.resourceType()) &&
            !COUNTING.test(request.url()) &&
            !HELD_OPEN.test(request.url())
        ) {
            busy.set(request, Date.now());
            lastBusy = Date.now();
        }
    });
    const answered = (request) => {
        if (busy.delete(request)) {
            lastBusy = Date.now();
        }
    };
    page.on('requestfinished', answered);
    page.on('requestfailed', answered);
    // "Are you sure?" is answered yes, as the person clicking meant it
    page.on('dialog', (dialog) => dialog.accept().catch(() => {}));

    // What opens in a new tab is followed in this one: the check goes on on
    // the page a person would now be looking at
    let following = Promise.resolve();
    context.on('page', (opened) => {
        if (opened === page) {
            return;
        }

        lastBusy = Date.now();
        following = (async () => {
            await opened
                .waitForURL((url) => url.href !== 'about:blank', {
                    timeout: STEP_MAX,
                    waitUntil: 'commit',
                })
                .catch(() => {});
            const url = opened.url();
            await opened.close().catch(() => {});

            if (url && url !== 'about:blank') {
                await page
                    .goto(url, {
                        waitUntil: 'domcontentloaded',
                        timeout: STEP_MAX * 2,
                    })
                    .catch(() => {});
            }
        })();
    });

    return {
        page,
        context,
        refused,
        viewport,
        // Until the page has stopped asking for things, a while at most: a
        // sign-in through a slow tunnel is still on its way when the click
        // is done, and the next step looks on the page it leads to
        // `quiet`: how long nothing asked for counts as done
        async settled(most = 8000, quiet = 400) {
            const until = Date.now() + most;
            // A visit begins a moment after the click, if it does
            await page.waitForTimeout(100);
            await following;

            // Waiting for: the page itself coming, and what it asked for in
            // the last few seconds
            const waiting = () =>
                [...busy].some(
                    ([request, since]) =>
                        request.resourceType() === 'document' ||
                        Date.now() - since < LONG_HELD,
                );

            while (
                (waiting() || Date.now() - lastBusy < quiet) &&
                Date.now() < until
            ) {
                await page.waitForTimeout(100);
            }
        },
        async close() {
            await context.close().catch(() => {});
            await proxy.close();
        },
    };
}

/**
 * Does one step on an open page and answers with what came of it: ok, took,
 * and an error -- with a picture -- when it did not; a picture when it was
 * one. `pictures` false for a step done only to get somewhere.
 */
async function doStep(
    opened,
    step,
    { pace = paceOf(null), captions = false, pictures = true } = {},
) {
    const { page, refused } = opened;
    const began = Date.now();

    // What the check's writer tells whoever watches, if anything: the only
    // caption, besides a step that fails
    const note = captions && step.note ? String(step.note) : null;
    let shown = null;

    try {
        if (captions) {
            // Written before the step, so it is read while it happens; a
            // page about to open would wipe it, so that one waits till after
            if (note && step.do !== 'open') {
                await caption(page, note);
                shown = Date.now();
            } else {
                await caption(page, null);
            }
        }

        await act(page, step, pace);

        if (
            ['click', 'hover', 'choose', 'upload', 'press', 'back'].includes(
                step.do,
            )
        ) {
            await opened.settled();
        }

        // Somewhere it could not go: a refused tunnel, or no such site
        if (page.url().startsWith('chrome-error://')) {
            throw new Error(
                refused.size
                    ? 'net::ERR_BLOCKED_BY_CLIENT'
                    : 'The page could not be opened.',
            );
        }

        if (note) {
            // Again: a page the step changed has lost it
            await caption(page, note);
            const left =
                (waitOf(step) ?? noteHold(note)) -
                (shown ? Date.now() - shown : 0);

            if (left > 0) {
                await page.waitForTimeout(left);
            }
        }

        return {
            ok: true,
            took: Date.now() - began,
            ...(step.do === 'shot' && pictures
                ? { shot: await picture(page) }
                : {}),
        };
    } catch (error) {
        const why = plainly(error, step);

        if (captions) {
            await caption(page, `${step.say ?? ''} -- ${why}`, 'failed');
        }

        return {
            ok: false,
            took: Date.now() - began,
            error: why,
            shot: pictures ? await picture(page).catch(() => null) : null,
        };
    }
}

/**
 * What is on an open page, once it has arrived and stopped changing: each
 * thing a step can go by, where it is, and a picture of the page.
 */
async function lookAt(opened) {
    const { page, viewport } = opened;
    // Longer quiet than a step waits for: a site may wait a moment after
    // the typing stops before it asks for suggestions to show
    await opened.settled(8000, 800);
    await page.waitForLoadState('load', { timeout: 5000 }).catch(() => {});
    let before = -1;

    // Drawn a moment after it arrives: its words stop changing
    for (let read = 0; read < 16; read++) {
        const now = await page
            .evaluate(() => document.body?.innerText.length ?? 0)
            .catch(() => -1);

        if (now > 0 && now === before) {
            break;
        }

        before = now;
        await page.waitForTimeout(250);
    }

    const read = () =>
        page
            .evaluate(thingsOnPage)
            .catch(() => ({ things: [], frames: [], height: viewport.height }));
    let seen = await read();
    const height = Math.min(LOOK_MOST, Math.max(viewport.height, seen.height));
    let shot = null;

    // The page drawn whole, as far down as the picture goes: the window
    // made that tall for a moment, rather than a picture of the whole page
    // cut down -- on a long one that takes minutes and comes back half drawn
    try {
        if (height > viewport.height) {
            await page.setViewportSize({ width: viewport.width, height });
        }

        await page.evaluate(() => scrollTo(0, 0)).catch(() => {});
        // Where things are, as the picture shows them
        seen = await read();
        seen.things.push(...(await inFrames(page, seen.frames)));
        shot = await page
            .screenshot({ type: 'jpeg', quality: 70, timeout: STEP_MAX })
            .catch(() => null);
    } finally {
        if (height > viewport.height) {
            await page.setViewportSize(viewport).catch(() => {});
        }
    }

    return {
        url: page.url(),
        title: await page.title().catch(() => ''),
        refused: [...opened.refused],
        // All of them: those further down than the picture are listed too
        things: seen.things,
        below: seen.things.filter((thing) => thing.box.y >= height).length,
        shot: shot ? `data:image/jpeg;base64,${shot.toString('base64')}` : null,
        size: { width: viewport.width, height },
    };
}

/**
 * What is in the frames a page holds -- a card form, an embedded sign-up --
 * each thing placed where its frame is on the page, and marked with the
 * frame's path so a step goes into it. Frames inside frames are left out.
 */
async function inFrames(page, frames) {
    const found = [];
    const children = page
        .frames()
        .filter((frame) => frame.parentFrame() === page.mainFrame());

    for (const frame of children.slice(0, 8)) {
        const at = await frame
            .frameElement()
            .then((element) =>
                element.evaluate((iframe) =>
                    [...document.querySelectorAll('iframe')].indexOf(iframe),
                ),
            )
            .catch(() => -1);
        const holder = frames.find((one) => one.index === at);

        if (!holder) {
            continue;
        }

        const inside = await frame.evaluate(thingsOnPage).catch(() => null);

        for (const thing of inside?.things ?? []) {
            const y = thing.box.y - inside.scroll.y;

            // Only what shows within the frame's own window
            if (y + thing.box.h <= 0 || y >= holder.h) {
                continue;
            }

            found.push({
                ...thing,
                frame: holder.path,
                box: {
                    ...thing.box,
                    x: thing.box.x - inside.scroll.x + holder.x,
                    y: thing.box.y - inside.scroll.y + holder.y,
                },
            });
        }
    }

    return found;
}

function stepsOf(body) {
    const steps = Array.isArray(body?.steps)
        ? body.steps.slice(0, MOST_STEPS)
        : [];

    if (!steps.every((step) => KINDS.includes(step?.do))) {
        throw Object.assign(
            new Error('steps must each do one of: ' + KINDS.join(', ')),
            { status: 400 },
        );
    }

    return steps;
}

// Runs asked to stop, by the token each was started with, and when: each
// stops before its next step. Kept a while, so a stop asked for just before a
// check starts still reaches it.
const stops = new Map();
const STOP_KEPT = 3 * 60 * 60 * 1000;

// The tokens of the checks running just now
const inHand = new Set();

/**
 * Asks the check (or film) started with `token` to stop after the step it is
 * doing: it closes its browser and answers with what was done, `halted`.
 * Answers whether such a check is running now.
 */
export function stopCheck(token) {
    const now = Date.now();

    for (const [old, at] of stops) {
        if (now - at > STOP_KEPT) {
            stops.delete(old);
        }
    }

    stops.set(token, now);

    return inHand.has(token);
}

/** The token a check names itself by, for stopCheck(), when it has one. */
export const tokenOf = (body) =>
    typeof body?.token === 'string' && /^[\w-]{1,64}$/.test(body.token)
        ? body.token
        : null;

/**
 * Runs `steps` in a page of its own and answers with what each one did.
 * Stops at the first that fails; the ones after it are left unrun -- and
 * at the next step, once it is asked to (stopCheck), answering `halted`.
 *
 * `size` is the screen it is drawn on (desktop, tablet, phone); `pace` sets
 * how fast it goes; `captions` writes each step's `note` across the foot of
 * the page while it is done, held long enough to read, and says which step
 * failed and why; `record` films the whole check, answered as
 * `video`, a webm data URL; `look` answers with what is on the page the
 * steps lead to instead (lookAt). The pointer is drawn whenever the check is
 * slowed or filmed.
 */
/**
 * A browser's saved state as a sign-in left it (Playwright's storageState:
 * its cookies, and each site's localStorage), or null for none or for one
 * not of that shape.
 */
const stateOf = (given) =>
    given &&
    typeof given === 'object' &&
    Array.isArray(given.cookies) &&
    Array.isArray(given.origins)
        ? { cookies: given.cookies, origins: given.origins }
        : null;

/**
 * Moves the page's browser from one saved state to another -- signed in as
 * someone else, or signed out -- between the checks of one film: its
 * cookies swapped, and each site's localStorage either one knew emptied and
 * filled as the other has it.
 */
async function switchState(opened, from, to) {
    await opened.context.clearCookies();

    if (to?.cookies.length) {
        await opened.context.addCookies(to.cookies);
    }

    const sites = new Map();

    for (const site of [...(from?.origins ?? []), ...(to?.origins ?? [])]) {
        sites.set(site.origin, []);
    }

    for (const site of to?.origins ?? []) {
        sites.set(site.origin, site.localStorage ?? []);
    }

    for (const [origin, items] of sites) {
        try {
            await opened.page.goto(origin, {
                waitUntil: 'domcontentloaded',
                timeout: 15_000,
            });
            await opened.page.evaluate((items) => {
                localStorage.clear();

                for (const { name, value } of items) {
                    localStorage.setItem(name, value);
                }
            }, items);
        } catch {
            // A site that cannot be reached keeps what it had; the steps say so
        }
    }
}

export async function runCheck(browser, body) {
    // Several checks one after another in the one page, filmed as one: what
    // one leaves -- signed in, a file added -- the next finds. Each may name
    // whom it starts signed in as (`as`, one of `states`), switched to as it
    // begins
    // Each named once, so two checks as the same person need no switch
    const states = Object.fromEntries(
        Object.entries(
            body?.states && typeof body.states === 'object' ? body.states : {},
        ).map(([name, state]) => [name, stateOf(state)]),
    );
    const chapters = Array.isArray(body?.chapters)
        ? body.chapters.slice(0, MOST_CHAPTERS).map((chapter) => ({
              title: String(chapter?.title ?? ''),
              steps: stepsOf(chapter),
              state:
                  typeof chapter?.as === 'string'
                      ? (states[chapter.as] ?? null)
                      : null,
          }))
        : [{ title: '', steps: stepsOf(body), state: stateOf(body?.state) }];
    const steps = chapters.flatMap((chapter) => chapter.steps);

    if (!steps.length) {
        throw Object.assign(new Error('A check needs a step.'), {
            status: 400,
        });
    }

    if (running >= AT_ONCE) {
        throw Object.assign(
            new Error('Two checks are running already: try again in a moment.'),
            { status: 429 },
        );
    }

    running++;
    const token = tokenOf(body);

    if (token) {
        inHand.add(token);
    }

    const look = Boolean(body?.look);
    const pace = paceOf(look ? null : body?.pace);
    const captions = !look && Boolean(body?.captions);
    const record = !look && Boolean(body?.record);
    const films = record ? await mkdtemp(join(tmpdir(), 'check-')) : null;
    let opened;

    try {
        opened = await openPage(browser, {
            size: body?.size,
            glide: pace.glide,
            films,
            state: chapters[0].state,
        });
        // Whom the browser is signed in as just now, to switch as needed
        let current = chapters[0].state;
        // A slowed check is given the time its slowing takes, on top
        const typed = steps.reduce(
            (sum, step) => sum + String(step.text ?? '').length,
            0,
        );
        const held = captions
            ? steps.reduce(
                  (sum, step) =>
                      sum +
                      (waitOf(step) ?? (step.note ? noteHold(step.note) : 0)),
                  0,
              )
            : 0;
        const titled = captions && chapters.length > 1;
        const allowed =
            CHECK_MAX +
            steps.length * (pace.glide + 150 + pace.pause) +
            typed * pace.typing +
            held +
            (titled ? chapters.length * TITLE_HOLD : 0);
        const deadline = Date.now() + allowed;
        const done = chapters.map(() => []);
        let stopped = false;
        // Asked to stop by whoever started it: the film so far is kept
        let halted = false;

        for (const [at, chapter] of chapters.entries()) {
            if (stopped) {
                break;
            }

            if (token && stops.has(token)) {
                halted = true;
                break;
            }

            // Signed in as someone else, or signed out, for this check
            if (chapter.state !== current) {
                await switchState(opened, current, chapter.state);
                current = chapter.state;
            }

            // Each check's name, across the page, as it starts
            if (titled && chapter.title) {
                await titleCard(
                    opened.page,
                    chapter.title,
                    `${at + 1} of ${chapters.length}`,
                );
                await opened.page.waitForTimeout(TITLE_HOLD);
                await titleCard(opened.page, null);
            }

            for (const step of chapter.steps) {
                if (token && stops.has(token)) {
                    halted = true;
                    stopped = true;
                    break;
                }

                if (Date.now() > deadline) {
                    done[at].push({
                        ok: false,
                        took: 0,
                        error: `The check ran out of its ${Math.round(allowed / 1000)} seconds.`,
                    });
                    stopped = true;
                    break;
                }

                const result = await doStep(opened, step, {
                    pace,
                    captions,
                    pictures: !look,
                });
                done[at].push(result);

                if (!result.ok) {
                    stopped = true;
                    break;
                }

                await linger(opened.page, step, pace, captions);
            }
        }

        if (look) {
            return { steps: done[0], ...(await lookAt(opened)) };
        }

        // A film ends a moment after the last step, on how it ended
        if (record) {
            await opened.page.waitForTimeout(Math.max(1000, pace.pause));
        }

        const answer = {
            steps: done.flat(),
            ...(Array.isArray(body?.chapters) ? { chapters: done } : {}),
            url: opened.page.url(),
            title: await opened.page.title().catch(() => ''),
            refused: [...opened.refused],
            ...(halted ? { halted: true } : {}),
            // A sign-in that went through: the state it leaves, for the
            // checks that start from it. Never kept here
            ...(body?.keepState &&
            !halted &&
            done.flat().length === steps.length &&
            done.flat().every((step) => step.ok)
                ? { state: await opened.context.storageState() }
                : {}),
        };
        let video = null;

        if (record) {
            await opened.context.close();
            const path = await opened.page.video()?.path();
            const film = path ? await asMp4(path) : null;
            video =
                film && film.bytes.length <= MOST_VIDEO
                    ? `data:${film.type};base64,${film.bytes.toString('base64')}`
                    : null;
        }

        return { ...answer, video };
    } finally {
        running--;
        inHand.delete(token);
        await opened?.close();

        if (films) {
            await rm(films, { recursive: true, force: true });
        }
    }
}

/**
 * The pause after a step: as long as its writer said, all told (a note's
 * hold being that already); in a demo, nothing on a still screen with no
 * note; else the pace's pause.
 */
async function linger(page, step, pace, captions) {
    const wait = captions ? waitOf(step) : null;
    const silent = captions && SILENT.includes(step.do) && !step.note;
    const pause =
        wait === null ? (silent ? 0 : pace.pause) : step.note ? 0 : wait;

    if (pause > 0) {
        await page.waitForTimeout(pause);
    }
}

// ------------------------------------------------------------ Live pages
// A page kept open while someone builds a check by clicking through it:
// each step is done once, on the page they are looking at, rather than every
// step so far again for each new one. Closed when they are done, or after
// a while of nothing.
const live = new Map();
const LIVE_MOST = 5;
// Left alone this long, a page gives way to one someone wants now
const LIVE_SPARE = 60_000;
const LIVE_IDLE = 5 * 60_000;

setInterval(() => {
    for (const [id, one] of live) {
        if (Date.now() - one.used > LIVE_IDLE) {
            void closeLive(id);
        }
    }
}, 30_000).unref();

/**
 * Opens a live page at `size`, does `steps` on it first (a check being
 * built on), and answers with its id and what is on the page.
 */
export async function openLive(browser, body) {
    const steps = stepsOf(body).filter((step) => step.do !== 'shot');

    if (live.size >= LIVE_MOST) {
        const [id, one] = [...live].sort(([, a], [, b]) => a.used - b.used)[0];

        if (Date.now() - one.used < LIVE_SPARE) {
            throw Object.assign(
                new Error(
                    'Five pages are being built on already: try again in a minute.',
                ),
                { status: 429 },
            );
        }

        await closeLive(id);
    }

    const opened = await openPage(browser, { size: body?.size });
    const id = randomUUID();
    const one = { opened, used: Date.now(), turn: Promise.resolve() };
    live.set(id, one);

    try {
        const done = [];

        for (const step of steps) {
            const result = await doStep(opened, step, { pictures: false });
            done.push(result);

            if (!result.ok) {
                break;
            }
        }

        return { id, steps: done, ...(await lookAt(opened)) };
    } catch (error) {
        await closeLive(id);
        throw error;
    }
}

/** Does one more step on a live page, and answers with what came of it and the page now. */
export function stepLive(id, body) {
    const one = live.get(id);

    if (!one) {
        return Promise.reject(
            Object.assign(
                new Error('That page has been closed: open it again.'),
                { status: 404 },
            ),
        );
    }

    const [step] = stepsOf({ steps: [body?.step] });
    one.used = Date.now();

    // One step at a time on a page, in the order they were asked
    const next = one.turn.then(async () => {
        const result = await doStep(one.opened, step, { pictures: false });
        one.used = Date.now();

        return { step: result, ...(await lookAt(one.opened)) };
    });
    one.turn = next.catch(() => {});

    return next;
}

export async function closeLive(id) {
    const one = live.get(id);
    live.delete(id);
    await one?.opened.close();
}
