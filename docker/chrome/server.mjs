#!/usr/bin/env node
/**
 * The PDF printer: a headless Chrome that prints a note as the app draws it.
 *
 * Nothing is rendered a second time. Chrome opens the real note page, signed in
 * with the caller's own session cookie (App\Support\NotePdf), and is asked for a
 * PDF of what it drew -- so Mermaid diagrams, KaTeX, boards and pictures are the
 * real ones, and the look is whatever resources/css/print.css makes of the page.
 *
 * One browser, kept warm for the life of the container; a fresh context per
 * request, always closed, so a leaked renderer cannot eat the machine.
 *
 *   GET  /healthz -> 200 "ok" while a browser is connected, 503 + why otherwise
 *   POST /pdf     -> {url, cookies:[{name,value,domain,path}], format, margin,
 *                    width, pageSize:{width,height}} -> application/pdf, plus
 *                    X-Pdf-* headers describing the page at the moment it
 *                    printed. A pageSize prints edge to edge on pages that
 *                    size (a board's frames, App\Support\BoardRender).
 *   POST /png     -> {url, cookies, width, height} -> image/png of what the
 *                    page drew at that size: a board, or one of its frames.
 *   Any failure is a JSON {"error"} with a 4xx/5xx -- never an empty 200.
 */
import http from 'node:http';
import { chromium } from 'playwright';

const PORT = Number(process.env.PDF_PORT || 3000);
const MAX_BODY = 1024 * 1024; // a URL and a cookie; anything bigger is a mistake
const NAV_TIMEOUT = 45_000;
const PDF_TIMEOUT = 60_000;
const READY_MAX = 10_000; // how long to wait for the page to say it has drawn the note
const PAINT_QUIET = 750; // how long "nothing happening" must last to count as finished
const PAINT_MAX = 12_000; // ...and when to print what is there anyway
const PAINT_POLL = 100;
const READY_ATTR = 'data-print-ready'; // resources/js/lib/printReady.ts

const DEFAULT_MARGIN = {
    top: '16mm',
    right: '14mm',
    bottom: '16mm',
    left: '14mm',
};

// A4 is 210mm across. The page is laid out at the width it will be printed at,
// so what measures itself on screen -- a board's canvas -- is drawn at the size
// it has on paper, rather than at a screen's width and then cut off.
const MM = 96 / 25.4;
const DEFAULT_WIDTH = Math.round((210 - 14 - 14) * MM);

// Held as the promise, so two requests arriving during a launch share one browser
let launching = null;

async function browser() {
    if (launching) {
        const warm = await launching.catch(() => null);
        if (warm && warm.isConnected()) return warm;
        launching = null; // it died -- start another
    }
    // --no-sandbox: already in a container, and the sandbox needs privileges.
    // --disable-dev-shm-usage: Docker's 64M /dev/shm crashes Chrome mid-render.
    launching = chromium.launch({
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    return launching;
}

function readBody(req) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        let size = 0;
        req.on('data', (chunk) => {
            size += chunk.length;
            if (size > MAX_BODY) {
                // Pause rather than destroy, so the 413 still reaches the sender
                req.pause();
                reject(
                    Object.assign(
                        new Error(`request body over ${MAX_BODY} bytes`),
                        { status: 413 },
                    ),
                );

                return;
            }
            chunks.push(chunk);
        });
        req.on('end', () => resolve(Buffer.concat(chunks)));
        req.on('error', reject);
    });
}

function fail(res, status, message) {
    const body = JSON.stringify({
        error: String(message || 'unknown error').slice(0, 2000),
    });
    res.writeHead(status, {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(body),
        Connection: 'close',
    });
    res.end(body, () => setTimeout(() => res.socket?.destroy(), 2000).unref());
}

/** Only the four fields Playwright needs, from entries that carry all of them. */
function cleanCookies(raw) {
    if (!Array.isArray(raw)) return [];

    return raw
        .filter(
            (c) =>
                c &&
                typeof c.name === 'string' &&
                typeof c.value === 'string' &&
                c.domain &&
                c.path,
        )
        .map((c) => ({
            name: c.name,
            value: c.value,
            domain: String(c.domain),
            path: String(c.path),
        }));
}

/**
 * Wait for the page to say the note is drawn: the editor is up and every
 * diagram and board in it has finished loading. Never fatal -- a page that
 * never says so still prints, on the paint check below.
 */
async function waitForReady(page) {
    try {
        await page.waitForSelector(`html[${READY_ATTR}="1"]`, {
            state: 'attached',
            timeout: READY_MAX,
        });

        return true;
    } catch {
        return false;
    }
}

/**
 * Wait until the page stops drawing: no request started or finished for
 * PAINT_QUIET, every <img> complete, and the number of images unchanged -- all
 * three at once, measured from now. A picture that failed still counts as
 * finished (waiting on a 404 would hang every export), but it is counted, so
 * the caller can say the PDF has a hole in it.
 */
async function waitForPaint(page) {
    const deadline = Date.now() + PAINT_MAX;
    let lastActivity = Date.now();
    const bump = () => {
        lastActivity = Date.now();
    };

    page.on('request', bump);
    page.on('requestfinished', bump);
    page.on('requestfailed', bump);

    let images = -1;
    try {
        while (Date.now() < deadline) {
            const now = await page
                .evaluate(() => {
                    const imgs = [...document.images];

                    return {
                        total: imgs.length,
                        pending: imgs.filter((i) => !i.complete).length,
                        broken: imgs.filter(
                            (i) =>
                                i.complete &&
                                i.naturalWidth === 0 &&
                                i.currentSrc !== '',
                        ).length,
                    };
                })
                .catch(() => null);

            if (!now) break;

            const stable = now.total === images && now.pending === 0;
            images = now.total;

            if (stable && Date.now() - lastActivity >= PAINT_QUIET) {
                return { settled: true, images: now.total, broken: now.broken };
            }
            await page.waitForTimeout(PAINT_POLL);
        }

        const last = await page
            .evaluate(() => ({
                total: document.images.length,
                broken: [...document.images].filter(
                    (i) =>
                        !i.complete ||
                        (i.naturalWidth === 0 && i.currentSrc !== ''),
                ).length,
            }))
            .catch(() => ({ total: 0, broken: 0 }));

        return { settled: false, images: last.total, broken: last.broken };
    } finally {
        page.off('request', bump);
        page.off('requestfinished', bump);
        page.off('requestfailed', bump);
    }
}

/**
 * Opens the page in a fresh context and waits until it has drawn; `use` then
 * makes what is wanted of it. The context is always closed.
 */
async function rendered(opts, media, use) {
    const b = await browser();
    const ctx = await b.newContext({
        viewport: { width: opts.width, height: opts.height ?? 1100 },
    });
    try {
        const cookies = cleanCookies(opts.cookies);
        if (cookies.length) await ctx.addCookies(cookies);

        const page = await ctx.newPage();
        // The medium from the first paint, so everything that measures itself
        // measures the page it ends up on (print for a PDF, screen for a PNG)
        await page.emulateMedia({ media });

        let status = null;
        try {
            const res = await page.goto(opts.url, {
                waitUntil: 'networkidle',
                timeout: NAV_TIMEOUT,
            });
            status = res ? res.status() : null;
        } catch (e) {
            // A page that never goes idle is still worth printing
            if (!/timeout/i.test(String(e && e.message))) throw e;
            await page.waitForLoadState('domcontentloaded', {
                timeout: 10_000,
            });
        }
        if (status !== null && status >= 400) {
            throw new Error(`${opts.url} answered HTTP ${status}`);
        }

        const ready = await waitForReady(page);
        const paint = await waitForPaint(page);
        paint.ready = ready;

        const buffer = await use(page);

        return { buffer, finalUrl: page.url(), status, paint };
    } finally {
        await ctx.close().catch(() => {});
    }
}

const renderPdf = (opts) =>
    rendered(opts, 'print', (page) =>
        page.pdf({
            ...(opts.pageSize
                ? {
                      width: opts.pageSize.width,
                      height: opts.pageSize.height,
                      margin: { top: 0, right: 0, bottom: 0, left: 0 },
                  }
                : { format: opts.format, margin: opts.margin }),
            printBackground: true,
            timeout: PDF_TIMEOUT,
        }),
    );

const renderPng = (opts) =>
    rendered(opts, 'screen', (page) =>
        page.screenshot({ type: 'png', timeout: PDF_TIMEOUT }),
    );

/** A size the page may be drawn at: a whole number of pixels, within reason. */
const pixels = (value, fallback, most) =>
    Number.isFinite(+value) && +value > 0
        ? Math.min(most, Math.round(+value))
        : fallback;

const server = http.createServer(async (req, res) => {
    const path = (req.url || '').split('?')[0];

    if (req.method === 'GET' && path === '/healthz') {
        const warm = await browser().catch((e) => e);
        if (warm instanceof Error || !warm.isConnected()) {
            return fail(
                res,
                503,
                `chromium unavailable: ${warm instanceof Error ? warm.message : 'disconnected'}`,
            );
        }
        res.writeHead(200, {
            'Content-Type': 'text/plain',
            'Content-Length': 2,
        });

        return res.end('ok');
    }

    if (req.method !== 'POST' || (path !== '/pdf' && path !== '/png')) {
        return fail(res, 404, `no route for ${req.method} ${path}`);
    }

    try {
        let body;
        try {
            body = JSON.parse((await readBody(req)).toString('utf8') || '{}');
        } catch (e) {
            if (e && e.status) throw e;

            return fail(res, 400, 'body is not valid JSON');
        }
        if (typeof body.url !== 'string' || !/^https?:\/\//.test(body.url)) {
            return fail(res, 400, 'url is required and must be http(s)');
        }

        if (path === '/png') {
            const out = await renderPng({
                url: body.url,
                cookies: body.cookies,
                width: pixels(body.width, 1600, 4000),
                height: pixels(body.height, 900, 4000),
            });

            res.writeHead(200, {
                'Content-Type': 'image/png',
                'Content-Length': out.buffer.length,
                'X-Pdf-Final-Url': out.finalUrl,
                'X-Pdf-Painted': out.paint.settled ? '1' : '0',
            });

            return res.end(out.buffer);
        }

        const width =
            Number.isFinite(+body.width) && +body.width > 0
                ? Math.min(2000, +body.width)
                : DEFAULT_WIDTH;
        const pageSize =
            body.pageSize &&
            typeof body.pageSize.width === 'string' &&
            typeof body.pageSize.height === 'string'
                ? { width: body.pageSize.width, height: body.pageSize.height }
                : null;
        const out = await renderPdf({
            pageSize,
            // Laid out at the size of the paper, so a page fills its sheet
            height: pageSize ? pixels(body.height, 1100, 4000) : 1100,
            url: body.url,
            cookies: body.cookies,
            format: typeof body.format === 'string' ? body.format : 'A4',
            margin:
                body.margin && typeof body.margin === 'object'
                    ? body.margin
                    : DEFAULT_MARGIN,
            width,
        });

        if (!out.buffer || !out.buffer.length) {
            return fail(res, 500, 'chromium produced an empty PDF');
        }

        res.writeHead(200, {
            'Content-Type': 'application/pdf',
            'Content-Length': out.buffer.length,
            // Where Chrome ended up: a session that did not sign in lands on
            // /login, and a PDF of the sign-in form is not the user's note
            'X-Pdf-Final-Url': out.finalUrl,
            'X-Pdf-Ready': out.paint.ready ? '1' : '0',
            'X-Pdf-Painted': out.paint.settled ? '1' : '0',
            'X-Pdf-Images': String(out.paint.images),
            'X-Pdf-Broken': String(out.paint.broken),
        });
        res.end(out.buffer);
    } catch (e) {
        console.error('[pdf]', e && e.stack ? e.stack : e);
        fail(
            res,
            e && e.status ? e.status : 500,
            e && e.message ? e.message : e,
        );
    }
});

server.requestTimeout = 120_000;
server.headersTimeout = 60_000;

// Warm the browser before the first export, without dying if it fails:
// /healthz reports the truth and the next request retries the launch
browser()
    .then(() => console.log('[pdf] chromium warm'))
    .catch((e) =>
        console.error(
            '[pdf] chromium failed to launch:',
            e && e.message ? e.message : e,
        ),
    );

for (const sig of ['SIGINT', 'SIGTERM']) {
    process.on(sig, async () => {
        server.close();
        const warm = await launching?.catch(() => null);
        await warm?.close().catch(() => {});
        process.exit(0);
    });
}

server.listen(PORT, '0.0.0.0', () => console.log(`[pdf] listening on ${PORT}`));
