// What every board suite shares: signing in, reading the canvas back, and
// saying what passed. A suite should hold only what is particular to it.
//
// These drive the real app in a real browser, because a canvas can type-check
// perfectly and still refuse to draw. Run them against `composer dev`:
//
//     node tests/e2e/board.mjs          # watch it happen
//     HEADED=0 node tests/e2e/board.mjs # quietly
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright-core';

const APP = process.env.APP_URL ?? 'http://127.0.0.1:8001';

// The seeded user, so `php artisan db:seed` is all the setup there is
const WHO = {
    email: process.env.E2E_EMAIL ?? 'test@example.com',
    password: process.env.E2E_PASSWORD ?? 'password',
};

export const SHOTS = process.env.E2E_SHOTS ?? '/tmp/zyrenn-e2e';

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
    });
    const page = await browser.newPage({
        viewport: { width: size.width ?? 1500, height: size.height ?? 950 },
    });

    page.on('console', (message) => {
        if (message.type() === 'error' || message.type() === 'warning') {
            problems.push(
                `console.${message.type()}: ${message.text().slice(0, 160)}`,
            );
        }
    });
    page.on('pageerror', (error) =>
        problems.push(`pageerror: ${error.message.slice(0, 160)}`),
    );

    await page.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await page.getByLabel('Email address').fill(WHO.email);
    await page.getByLabel('Password', { exact: true }).fill(WHO.password);
    await page.getByRole('button', { name: /log in/i }).click();
    await page.waitForURL(/dashboard/);

    await page.goto(`${APP}${path}`, { waitUntil: 'networkidle' });

    const check = (label, passed, detail = '') => {
        results.push(
            `${passed ? 'PASS' : 'FAIL'}  ${label}${detail ? ` — ${detail}` : ''}`,
        );

        if (!passed) {
            problems.push(label);
        }
    };

    const board = boardOn(page);

    if (size.canvas !== false) {
        await board.ready();
    }

    // Tidying a suite asks for, done even when it falls over part way
    const cleanups = [];
    const afterwards = (cleanup) => cleanups.push(cleanup);

    const done = async () => {
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
        await page.waitForTimeout(900);
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
    const fields = async () => {
        const inputs = page.locator(
            '[data-test="inspector"] input[type="number"]',
        );
        const [x, y, width, height] = await Promise.all(
            [0, 1, 2, 3].map((index) => inputs.nth(index).inputValue()),
        );

        return { x: +x, y: +y, w: +width, h: +height };
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

        await flyout.locator(`[data-test="tool-${tool}"]`).click();
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
export async function signIn(browser, base, email, password = 'password') {
    const context = await browser.newContext({
        viewport: { width: 1500, height: 950 },
    });
    const page = await context.newPage();

    await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: /log in/i }).click();
    await page.waitForURL(/dashboard/);

    return { context, page };
}

/**
 * A new project made from the switcher, with `email` added in `role`. Answers
 * with its ref_id and the app's origin; deleteProject() takes it away again.
 */
export async function shareProject(page, name, email, role = 'editor') {
    await page.locator('[data-test="project-switcher"]').click();
    await page.locator('[data-test="new-project"]').click();
    await page.getByPlaceholder('Name').fill(name);
    await page.getByRole('button', { name: 'Create' }).click();
    await page.waitForURL(/\/p\/\w+\/notes$/);

    const project = page.url().match(/\/p\/(\w+)\//)[1];
    const base = new URL(page.url()).origin;

    await page.goto(`${base}/p/${project}/settings`);
    const addForm = page.locator('[data-test="add-member"]');
    await addForm.getByPlaceholder('Their email address').fill(email);
    await addForm.locator('select').selectOption(role);
    await addForm.getByRole('button', { name: 'Add' }).click();
    await page.getByText(email).waitFor();

    return { project, base };
}

/** Deletes a project from its settings, as its owner. */
export async function deleteProject(page, base, project) {
    await page.goto(`${base}/p/${project}/settings`);
    await page.locator('[data-test="delete-project"]').click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Delete' })
        .click();
    await page.waitForURL((url) => new URL(url).pathname === '/notes');
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
