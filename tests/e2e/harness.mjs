// What every board suite shares: signing in, reading the canvas back, and
// saying what passed. A suite should hold only what is particular to it.
//
// These drive the real app in a real browser, because a canvas can type-check
// perfectly and still refuse to draw. Run them against `composer dev`:
//
//     node tests/e2e/board.mjs          # watch it happen
//     HEADED=0 node tests/e2e/board.mjs # quietly
//     FULL=1 SLOWMO=600 node tests/e2e/inbox.mjs # full screen, slow enough to watch, a cursor drawn,
//                                                # and up for LINGER (5) seconds at the end
//     FULL=1 SLOWMO=800 READ=2 node ...          # and each check written on the page, held 2s to read
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';
import { caption, glideTo, typeInto } from '../../docker/chrome/pace.mjs';
import { drawCursor } from '../../resources/js/features/browser/cursor.js';

// tests/e2e/.env (git-ignored): values a run may need that stay on this
// machine -- the real-services account's password, say. What the shell sets
// wins over it.
try {
    for (const line of readFileSync(
        join(dirname(fileURLToPath(import.meta.url)), '.env'),
        'utf8',
    ).split('\n')) {
        const found = /^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/.exec(line);

        if (
            found &&
            !line.trim().startsWith('#') &&
            process.env[found[1]] === undefined
        ) {
            process.env[found[1]] = found[2].replace(/^(['"])(.*)\1$/, '$2');
        }
    }
} catch {
    // No such file: nothing more to read
}

// E2E_ACCOUNT=real: the account kept for real services (E2E_REAL_*), for
// this run alone -- never by itself, so no suite strays onto it
if (process.env.E2E_ACCOUNT === 'real') {
    if (!process.env.E2E_REAL_PASSWORD) {
        throw new Error(
            'E2E_ACCOUNT=real needs E2E_REAL_PASSWORD, in tests/e2e/.env',
        );
    }

    process.env.E2E_EMAIL =
        process.env.E2E_REAL_EMAIL ?? 'zyrenn.dev@gmail.com';
    process.env.E2E_PASSWORD = process.env.E2E_REAL_PASSWORD;
}

const APP = process.env.APP_URL ?? 'http://127.0.0.1:8001';

// The seeded user, so `php artisan db:seed` is all the setup there is
const WHO = {
    email: process.env.E2E_EMAIL ?? 'test@example.com',
    password: process.env.E2E_PASSWORD ?? 'password',
};

export const SHOTS = process.env.E2E_SHOTS ?? '/tmp/zyrenn-e2e';

// What one run leaves for the next -- who is signed in, how long each suite
// took -- kept with the app, where a restart of the machine doesn't lose it
export const KEPT =
    process.env.E2E_KEPT ??
    join(
        dirname(fileURLToPath(import.meta.url)),
        '../../storage/framework/testing/e2e',
    );

/**
 * Asks `read` again and again until it answers something true, and answers
 * with that -- or, when `timeout` runs out, with the last thing it said. For
 * whatever arrives when it arrives: a save, an upload, the other person's
 * change. A fixed wait is either too long or, on a busy machine, too short.
 */
export async function until(read, { timeout = 8000, every = 100 } = {}) {
    const end = Date.now() + timeout;

    for (;;) {
        let answer;

        try {
            answer = await read();
        } catch {
            // Not there to be read yet
        }

        if (answer || Date.now() >= end) {
            return answer;
        }

        await new Promise((resolve) => setTimeout(resolve, every));
    }
}

/**
 * Asks `read` until it answers the same thing twice running, and answers with
 * that: for something still finding its place -- a picture taking its size,
 * a board fitting itself to the window.
 */
export async function steady(read, { timeout = 8000, every = 150 } = {}) {
    let before;

    return until(
        async () => {
            const now = await read();
            const same =
                now != null && JSON.stringify(now) === JSON.stringify(before);
            before = now;

            return same ? now : null;
        },
        { timeout, every },
    );
}

/** The page's app is up: its first screen is drawn, and can be clicked. */
export const mounted = (page) =>
    page.waitForFunction(() => {
        const app = document.getElementById('app');

        // Drawn, and Vue's: a page drawn on the server takes no clicks until
        // Vue has taken it over
        return app?.childElementCount > 0 && Boolean(app.__vue_app__);
    });

/**
 * Opens `url` and waits for the app to be up -- not for the network to fall
 * quiet, which is half a second of nothing after every page.
 */
export async function open(page, url) {
    await page.goto(url, { waitUntil: 'load' });
    await mounted(page);
}

/** The same, for the page it is already on. */
export async function reopen(page) {
    await page.reload({ waitUntil: 'load' });
    await mounted(page);
}

/** A board's items as the app itself has them kept, by id. */
export const boardKept = (page, ref) =>
    page.evaluate(async (ref) => {
        const board = await (
            await fetch(`/boards/${ref}/content`, {
                headers: { Accept: 'application/json' },
            })
        ).json();

        return Object.fromEntries(
            (board.items ?? []).map((item) => [item.id, item]),
        );
    }, ref);

/**
 * Types a slash command into the note and takes what the menu finds for it,
 * once the menu has caught up with the typing. `title` is what the menu calls
 * it, where that is not the word typed ("pagebreak" finds "Page break").
 */
export async function slash(page, word, title = word) {
    await page.keyboard.type(`/${word}`);

    const found = page.locator(
        '.notion-dropdown .dropdown-item.is-active .item-title',
    );
    await found.waitFor();
    await until(
        async () =>
            (await found.innerText())
                .toLowerCase()
                .includes(title.toLowerCase()),
        { timeout: 600, every: 30 },
    );
    await page.keyboard.press('Enter');
}

/**
 * Waits for the note, board or table on the page to say it is saved: enough
 * before leaving the page or loading it again. Call it once the page shows
 * the change itself.
 *
 * A note or board the collaboration server keeps says "Saved" when that
 * server has the change, and the app is given it a couple of seconds later --
 * so whatever then reads what the app has kept (boardKept(), MCP, tinker, a
 * board shown in a note) asks until() it is there.
 */
export async function saved(page, timeout = 15000) {
    await page.evaluate(
        () =>
            new Promise((resolve) =>
                requestAnimationFrame(() => requestAnimationFrame(resolve)),
            ),
    );
    await page
        .locator(
            '[data-test="note-status"], [data-test="board-status"], [data-test="table-status"]',
        )
        .filter({ hasText: /^\s*Saved/ })
        .first()
        .waitFor({ timeout });
}

// What Chrome says of itself, never of the app: its graphics drawing without a
// GPU, headless, and the dev server's font preloads on a busy machine
const BROWSER_NOISE =
    /GL Driver Message|GPU stall due to ReadPixels|WebGL: CONTEXT_LOST_WEBGL: loseContext|__laravel_vite_plugin__\/fonts\/.* was preloaded using link preload but not used/;

// What the dev server's own client says when a file is edited while a suite
// runs -- someone else at work in the same checkout, not the app going wrong
const DEV_SERVER_NOISE = /\/@vite\/client|^\[vite\] /;

// A pointer drawn on the page where the mouse is, moving there rather than
// jumping, and a ring where it presses: so someone watching a headed run can
// follow what the suite does. On by itself whenever the browser is shown
// (CURSOR=0 leaves it out); never in a headless run. It takes no clicks.
const SHOW_CURSOR =
    process.env.HEADED !== '0' && (process.env.CURSOR ?? '1') !== '0';

// FULL=1 with the browser shown: Chrome opens maximized and the page fills
// the whole window, for someone watching, rather than a set 1500x950. Off
// by itself, since some suites go by where things are on a page that size.
const FULL = process.env.HEADED !== '0' && process.env.FULL === '1';

// How long a full-screen run leaves the window up after its last step, in
// seconds (LINGER), so whoever watches sees where it ended. Not a wait for
// anything: the suite is done by then.
const LINGER = FULL ? Number(process.env.LINGER ?? 5) : 0;

// READ=n on a full-screen run: each check is written across the foot of the
// page and held there n seconds, for whoever watches to read what was just
// checked and the screen it was checked on. Like LINGER, not a wait for
// anything, and off unless asked for.
const READ = FULL ? Number(process.env.READ ?? 0) : 0;

// Each check across the foot of the page (docker/chrome/pace.mjs, as the browser checks write theirs)
const showCheck = (page, label, passed) =>
    caption(page, label, passed ? 'ok' : 'failed');

/** What Chrome is started with: maximized, for a full-screen run. */
export const LAUNCH_ARGS = FULL ? ['--start-maximized'] : [];

// On a full-screen run, how long the drawn pointer takes to glide to where
// the mouse goes, in ms -- long enough to follow it from one thing to the next
const GLIDE = FULL ? 350 : 160;

// Drawn as the tours of the app draw it (resources/js/features/browser/cursor.js),
// following the real mouse
const CURSOR = `(${String((draw, glide) => {
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
})})(${String(drawCursor)}, ${GLIDE})`;

/**
 * A full-screen run is watched: the mouse goes to what it clicks or hovers,
 * and waits for the drawn pointer to get there, before it presses -- so the
 * press is seen where and when it happens, not after what it chose has
 * already changed; and what is filled in is typed, key by key. Like LINGER,
 * waits only for whoever watches, and only on a FULL run.
 */
function watched(page) {
    const Locator = Object.getPrototypeOf(page.locator('body'));

    if (Locator.watched) {
        return;
    }

    const { click, hover, fill } = Locator;

    Object.assign(Locator, {
        watched: true,
        async click(options) {
            await glideTo(this, GLIDE);

            return click.call(this, options);
        },
        async hover(options) {
            await glideTo(this, GLIDE);

            return hover.call(this, options);
        },
        async fill(value, options) {
            await glideTo(this, GLIDE);

            // Through the unpatched click and fill, so it glides only the once
            return typeInto(this, value, 45, {
                click: (o) => click.call(this, o),
                fill: (v, o) => fill.call(this, v, o),
                options,
            });
        },
    });
}

/**
 * A page signed in as `email`, by a session kept from the last time -- the
 * form is filled in only when there is none yet, or it has run out. Opens
 * `path` once signed in.
 */
async function signedIn(
    browser,
    base,
    email,
    password,
    path,
    viewport,
    watch = () => {},
    // Pixels drawn for each one of the page's: 2 for a picture worth keeping
    deviceScaleFactor = 1,
) {
    mkdirSync(SHOTS, { recursive: true });
    mkdirSync(KEPT, { recursive: true });
    const kept = `${KEPT}/session-${email}.json`;
    // A full-screen run takes the window's own size, which no pixel ratio can be set beside
    const screen = FULL ? { viewport: null } : { viewport, deviceScaleFactor };
    let context;

    try {
        context = await browser.newContext({ ...screen, storageState: kept });
    } catch {
        context = await browser.newContext(screen);
    }

    if (SHOW_CURSOR) {
        await context.addInitScript(CURSOR);
    }

    const page = await context.newPage();
    watch(page);

    if (FULL) {
        watched(page);
    }
    await open(page, `${base}${path}`);

    if (new URL(page.url()).pathname === '/login') {
        await page.getByLabel('Email address').fill(email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByRole('button', { name: /log in/i }).click();
        // Back to where it was going, or the dashboard
        await page.waitForURL((url) => url.pathname !== '/login');
        await context.storageState({ path: kept });
        await open(page, `${base}${path}`);
    }

    return { context, page };
}

/**
 * Opens the app at `path` with somebody signed in, and hands back the page
 * along with the helpers every board suite needs.
 */
export async function openBoard(path = '/demo/konva', size = {}) {
    const results = [];
    const problems = [];

    const browser = await chromium.launch({
        channel: 'chrome',
        headless: process.env.HEADED === '0',
        slowMo: Number(process.env.SLOWMO ?? 40),
        args: LAUNCH_ARGS,
    });
    const { page } = await signedIn(
        browser,
        APP,
        WHO.email,
        WHO.password,
        path,
        { width: size.width ?? 1500, height: size.height ?? 950 },
        (page) => {
            page.on('console', (message) => {
                if (
                    (message.type() === 'error' ||
                        message.type() === 'warning') &&
                    !BROWSER_NOISE.test(message.text()) &&
                    !DEV_SERVER_NOISE.test(message.text())
                ) {
                    // (a resource that failed to load is named: the message
                    // only ever says that one did)
                    const from = /Failed to load resource/.test(message.text())
                        ? ` (${message.location().url.slice(0, 140)})`
                        : '';

                    problems.push(
                        `console.${message.type()}: ${message.text().slice(0, 160)}${from}`,
                    );
                }
            });
            // (with where it was thrown, which the message alone never says)
            page.on('pageerror', (error) => {
                if (DEV_SERVER_NOISE.test(error.stack ?? '')) {
                    return;
                }

                problems.push(
                    `pageerror: ${error.message.slice(0, 160)}${
                        (error.stack ?? '').split('\n')[1]
                            ? ` (${error.stack.split('\n')[1].trim().slice(0, 140)})`
                            : ''
                    }`,
                );
            });
        },
        size.scale,
    );

    // How long each check took to get to, since the one before it
    const gaps = [];
    let last = Date.now();

    const check = (label, passed, detail = '') => {
        gaps.push({ label, took: Date.now() - last });
        last = Date.now();
        results.push(
            `${passed ? 'PASS' : 'FAIL'}  ${label}${detail ? ` — ${detail}` : ''}`,
        );

        // E2E_LIVE=1: each check said as it happens too, for run.mjs to follow
        if (process.env.E2E_LIVE === '1') {
            console.log(`LIVE ${results.at(-1)}`);
        }

        if (!passed) {
            problems.push(label);
        }

        if (READ > 0) {
            void showCheck(page, label, passed);
        }
    };

    /**
     * A check of something that arrives when it arrives: `read` is asked until
     * it answers true, and fails only once it has had long enough. `detail`
     * may be a function, asked once the waiting is over.
     */
    const soon = async (label, read, detail = '', options) => {
        const answer = await until(read, options);

        check(
            label,
            !!answer,
            typeof detail === 'function' ? await detail() : detail,
        );

        if (READ > 0) {
            await new Promise((resolve) => setTimeout(resolve, READ * 1000));
        }

        return answer;
    };

    const board = boardOn(page);

    if (size.canvas !== false) {
        await board.ready();
    }

    // Tidying a suite asks for, done even when it falls over part way
    const cleanups = [];
    const afterwards = (cleanup) => cleanups.push(cleanup);

    const done = async () => {
        // Left up a moment for someone watching, as the suite left it, before it is tidied away
        if (LINGER > 0) {
            await new Promise((resolve) => setTimeout(resolve, LINGER * 1000));
        }

        for (const cleanup of cleanups.reverse()) {
            try {
                await cleanup();
            } catch (error) {
                problems.push(
                    `cleanup: ${error.message.split('\n')[0].slice(0, 160)}`,
                );
            }
        }

        await browser.close();
        console.log(results.join('\n'));

        // E2E_TIMES=1: where this suite's time went, the slowest stretches first
        if (process.env.E2E_TIMES === '1') {
            for (const { label, took } of gaps
                .sort((a, b) => b.took - a.took)
                .slice(0, 6)) {
                console.log(
                    `TIME  ${(took / 1000).toFixed(1)}s  up to: ${label}`,
                );
            }
        }

        console.log(
            'problems:',
            problems.length ? problems.join('; ') : 'none',
        );

        return problems.length;
    };

    return {
        browser,
        page,
        results,
        problems,
        check,
        soon,
        ...board,
        afterwards,
        done,
    };
}

/**
 * The helpers for working a board on a page -- anyone's page, so a second
 * person in the same suite can draw too. Call ready() once a board is open.
 */
export function boardOn(page) {
    // Where the canvas sits on screen. A page that has no board on it yet --
    // the list, say -- says so, and calls ready() once one is open.
    const view = { box: null };

    const ready = async () => {
        await page.waitForSelector('canvas');

        // The board fits itself to the window a moment after it opens: it is
        // ready once the camera has stopped moving
        let seen = null;
        let since = Date.now();

        await until(
            async () => {
                const now = await page
                    .locator('[data-camera]')
                    .first()
                    .getAttribute('data-camera');

                if (now !== seen) {
                    seen = now;
                    since = Date.now();
                }

                return Date.now() - since >= 350;
            },
            { every: 50 },
        );
        view.box = await page.locator('[data-zoom]').boundingBox();

        return view.box;
    };

    /** A point a fraction of the way across the canvas, in screen coordinates. */
    const at = (across, down) => ({
        x: view.box.x + view.box.width * across,
        y: view.box.y + view.box.height * down,
    });

    /** Where the camera is, as the canvas reports it. */
    const camera = async () => {
        const [x, y, scale] = (
            await page.locator('[data-camera]').getAttribute('data-camera')
        )
            .split(',')
            .map(Number);

        return { x, y, scale };
    };

    /** A point on the board, in screen coordinates. */
    const screenOf = async (boardX, boardY) => {
        const at = await camera();

        return {
            x: view.box.x + boardX * at.scale + at.x,
            y: view.box.y + boardY * at.scale + at.y,
        };
    };

    const rows = (group) =>
        page.locator(
            group
                ? `[data-test="layers"] .layers-row[data-group="${group}"]`
                : '[data-test="layers"] .layers-row',
        );

    const layerNames = async (group) =>
        (await rows(group).allInnerTexts()).map((text) => text.trim());

    const inspectorTitle = async () =>
        (
            await page.locator('[data-test="inspector-title"]').innerText()
        ).trim();

    const link = async () =>
        (await page.locator('[data-test="connector-link"]').count())
            ? (
                  await page.locator('[data-test="connector-link"]').innerText()
              ).trim()
            : '(none)';

    /** How many things are on the board, as the inspector counts them. */
    const itemCount = async () =>
        Number(
            (await page.locator('[data-test="inspector"]').innerText()).match(
                /(\d+) items/,
            )?.[1] ?? 0,
        );

    /** The selection's x, y, width and height, as the inspector shows them. */
    // They are kept in a fold, shut unless somebody wants them: read, they
    // answer shut or open
    const fields = async () => {
        const [x, y, width, height] = await Promise.all(
            ['x', 'y', 'width', 'height'].map((name) =>
                page.locator(`[data-test="prop-${name}"]`).inputValue(),
            ),
        );

        return { x: +x, y: +y, w: +width, h: +height };
    };

    /** Types one of them -- "x", "y", "width", "height" -- opening the fold first. */
    const setField = async (name, value) => {
        const fold = page.locator('[data-test="geometry"]');

        if (!(await fold.evaluate((details) => details.open))) {
            await fold.locator('summary').click();
            // The panel is drawn again once it has opened, and what was
            // typed before that would be drawn over
            await page.evaluate(
                () =>
                    new Promise((resolve) =>
                        requestAnimationFrame(() =>
                            requestAnimationFrame(resolve),
                        ),
                    ),
            );
        }

        const input = page.locator(`[data-test="prop-${name}"]`);
        await input.fill(String(value));
        await input.press('Enter');
    };

    /** How much of the canvas is painted -- a cheap "did anything change". */
    const painted = () =>
        page.evaluate(() => {
            let count = 0;

            for (const canvas of document.querySelectorAll('canvas')) {
                const { data } = canvas
                    .getContext('2d')
                    .getImageData(0, 0, canvas.width, canvas.height);

                for (let index = 3; index < data.length; index += 4) {
                    if (data[index] > 0) {
                        count++;
                    }
                }
            }

            return count;
        });

    /** A picture of one board rectangle, for comparing what moved. */
    const shot = async ({ x, y, w, h }) => {
        const at = await camera();

        return page.screenshot({
            clip: {
                x: view.box.x + x * at.scale + at.x,
                y: view.box.y + y * at.scale + at.y,
                width: w * at.scale,
                height: h * at.scale,
            },
        });
    };

    /**
     * Picks a tool off the rail -- or, for one of the many shapes, out of the
     * flyout the rail's shapes button opens.
     */
    const pick = async (tool) => {
        const onRail = page.locator(
            `[data-test="shape-library"] > [data-test="tool-${tool}"]`,
        );

        if (await onRail.count()) {
            await onRail.click();

            return;
        }

        // A flyout still closing from the last pick is not one to click in
        const flyout = page.locator(
            '[data-test="shape-flyout"][data-state="open"]',
        );

        if (!(await flyout.count())) {
            await page.locator('[data-test="tool-shapes"]').click();
        }

        await flyout.waitFor();

        // One of a set kept folded until wanted (UML, architecture): unfold
        const wanted = flyout.locator(`[data-test="tool-${tool}"]`);

        if (!(await wanted.count())) {
            const folded = flyout.locator(
                '.library-fold[aria-expanded="false"]',
            );

            while (await folded.count()) {
                await folded.first().click();
            }
        }

        await wanted.click();
        await page
            .locator('[data-test="shape-flyout"]')
            .waitFor({ state: 'detached' });
    };

    /** Draws something with a tool, and reports the box it ended up with. */
    const draw = async (tool, fromX, fromY, across = 0.1, down = 0.12) => {
        await pick(tool);
        await page.mouse.move(at(fromX, fromY).x, at(fromX, fromY).y);
        await page.mouse.down();
        await page.mouse.move(
            at(fromX + across, fromY + down).x,
            at(fromX + across, fromY + down).y,
            { steps: 6 },
        );
        await page.mouse.up();
        await page.waitForTimeout(250);

        const drawn = await fields();
        await page.keyboard.press('Escape');

        return drawn;
    };

    /**
     * Draws something over a box given in board units, wherever the camera
     * has put it -- for a suite that needs to land on a particular frame.
     */
    const drawAt = async (tool, x, y, w, h) => {
        const at = await camera();
        const corner = await screenOf(x, y);

        return draw(
            tool,
            (corner.x - view.box.x) / view.box.width,
            (corner.y - view.box.y) / view.box.height,
            (w * at.scale) / view.box.width,
            (h * at.scale) / view.box.height,
        );
    };

    /** Draws a connector between the middles of two boxes. */
    const join = async (from, to, ends) => {
        // Nothing left selected: a selection's rotate handle can sit right
        // where the line is meant to start, zoomed out
        await page.keyboard.press('Escape');
        await page.locator('[data-test="tool-arrow"]').click();

        const start =
            ends?.from ??
            (await screenOf(from.x + from.w / 2, from.y + from.h / 2));
        const finish =
            ends?.to ?? (await screenOf(to.x + to.w / 2, to.y + to.h / 2));

        await page.mouse.move(start.x, start.y);
        await page.mouse.down();
        await page.mouse.move((start.x + finish.x) / 2, start.y, { steps: 6 });
        await page.mouse.move(finish.x, finish.y, { steps: 6 });
        await page.mouse.up();
        await page.waitForTimeout(400);
        await page.locator('[data-test="tool-select"]').click();
    };

    /** Writes on whatever is under the point, the way a person would. */
    const label = async (point, words) => {
        await page.mouse.dblclick(point.x, point.y);
        await page.waitForTimeout(350);
        await page.keyboard.type(words);
        // Escape throws the typing away; Enter is what commits it
        await page.keyboard.press('Enter');
        await page.waitForTimeout(350);
    };

    /** Which face a connector end is pinned to, as the inspector shows it. */
    const pinnedSide = async (end) =>
        (await page.locator(`[data-test="side-${end}"]`).inputValue()) ||
        'auto';

    return {
        view,
        ready,
        at,
        camera,
        screenOf,
        pick,
        rows,
        layerNames,
        inspectorTitle,
        link,
        itemCount,
        fields,
        setField,
        painted,
        shot,
        draw,
        drawAt,
        join,
        label,
        pinnedSide,
    };
}

/**
 * Runs a suite: opens the board, hands it the helpers, and always reports --
 * a suite that falls over still says what had passed before it did.
 */
export async function runBoard(path, suite, size) {
    const board = await openBoard(path, size);

    try {
        await suite(board);
    } catch (error) {
        board.problems.push(
            `threw: ${error.message.split('\n')[0].slice(0, 200)}`,
        );
        board.results.push('FAIL  the suite got this far and threw');
    }

    const failures = await board.done();

    process.exitCode = failures ? 1 : 0;

    return failures;
}

/**
 * A second person, signed in in a browser context of their own -- someone to
 * share a project with. Close the context when done.
 */
export async function signIn(
    browser,
    base,
    email,
    password = 'password',
    path = '/dashboard',
) {
    return signedIn(browser, base, email, password, path, {
        width: 1500,
        height: 950,
    });
}

/**
 * A new project of the signed-in account's, with `email` in it in `role`.
 * Answers with its ref_id and the app's origin; deleteProject() takes it away
 * again. Made behind the scenes, since for the suites that call this it is
 * only where they start: projects.mjs and members.mjs are the ones that make
 * a project and add to it the way a person does.
 */
export async function shareProject(page, name, email, role = 'editor') {
    const project = tinker(
        `$p = App\\Models\\Project::start(App\\Models\\User::where('email', '${WHO.email}')->firstOrFail(), '${name}'); $p->members()->attach(App\\Models\\User::where('email', '${email}')->firstOrFail(), ['role' => '${role}']); echo $p->getRouteKey();`,
    );

    return { project, base: new URL(page.url()).origin };
}

/** Deletes a project made by shareProject(). */
export async function deleteProject(page, base, project) {
    tinker(
        `App\\Models\\Project::where((new App\\Models\\Project)->getRouteKeyName(), '${project}')->first()?->delete(); echo 'ok';`,
    );
}

/**
 * Deletes something by its path (a board, a note, a Drive file), the way the
 * app's own forms would -- for tidying up what a suite made.
 */
export async function removeAt(page, path) {
    await page.evaluate(async (path) => {
        const token = decodeURIComponent(
            document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
        );
        // The app answers with a redirect, which is not for a script
        await fetch(path, {
            method: 'DELETE',
            headers: { 'X-XSRF-TOKEN': token },
            redirect: 'manual',
        });
    }, path);
}

const APP_CONTAINER = process.env.E2E_APP_CONTAINER ?? 'zyrenn-app-1';

/** Runs PHP in the app's container and answers with the last line it printed. */
export const tinker = (php) =>
    execFileSync(
        'docker',
        ['exec', APP_CONTAINER, 'php', 'artisan', 'tinker', '--execute', php],
        { encoding: 'utf8' },
    )
        .trim()
        .split('\n')
        .pop();

/**
 * A note for the test account, made over the app's own MCP endpoint
 * (/mcp/user) with a token of its own. The token and the note are taken away
 * again when the suite is done. Answers with the note's ref_id.
 */
export async function makeNote({ page, afterwards }, title, markdown) {
    const base = new URL(page.url()).origin;
    const tokenName = `e2e ${title}`;
    const user = `App\\Models\\User::where('email', '${WHO.email}')->first()`;

    const token = tinker(
        `echo ${user}->createToken('${tokenName}', ['mcp'])->plainTextToken;`,
    );
    afterwards(() =>
        tinker(`${user}->tokens()->where('name', '${tokenName}')->delete();`),
    );

    const response = await fetch(`${base}/mcp/user`, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
            Accept: 'application/json, text/event-stream',
        },
        body: JSON.stringify({
            jsonrpc: '2.0',
            id: 1,
            method: 'tools/call',
            params: {
                name: 'create-note',
                arguments: { title, markdown },
            },
        }),
    });
    const ref = (await response.json()).result.structuredContent.ref_id;
    afterwards(() =>
        tinker(
            `App\\Models\\Note\\Note::where('ref_id', '${ref}')->first()?->delete();`,
        ),
    );

    return ref;
}

// ---- The workflow editor's step palette ----

/**
 * One of the palette's groups opened, by its name ("GitHub", "Data"): its
 * steps are then in a section headed by that name, which is given back.
 */
export async function paletteGroup(page, title) {
    const palette = page.locator('[data-step-palette]');
    await palette.locator('[data-palette-search]').fill('');
    const back = palette.locator('[data-palette-back]');

    if (await back.count()) {
        await back.click();
    }

    await palette.locator(`[data-palette-title="${title}"]`).click();

    return palette.locator('[data-palette-open]');
}

/** A kind of step in the palette, found by its kind ("logic.if"), ready to click or drag. */
export async function paletteStep(page, kind) {
    const palette = page.locator('[data-step-palette]');
    await palette.locator('[data-palette-search]').fill(kind);

    return palette.locator(`[data-palette-step="${kind}"]`);
}

/** The names of the palette's groups, in order, as the list of them shows them. */
export async function paletteGroups(page) {
    const palette = page.locator('[data-step-palette]');
    await palette.locator('[data-palette-search]').fill('');
    const back = palette.locator('[data-palette-back]');

    if (await back.count()) {
        await back.click();
    }

    return palette
        .locator('[data-palette-title]')
        .evaluateAll((rows) => rows.map((row) => row.dataset.paletteTitle));
}
