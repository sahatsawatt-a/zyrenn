/**
 * Doing things the way someone watching can follow: the mouse goes to what it
 * presses and the drawn pointer is let get there first, so the press is seen
 * where and when it happens; what is filled in is typed key by key; and what
 * is happening can be written across the foot of the page.
 *
 * Shared by the browser checks (check.mjs) and the app's browser suites
 * (tests/e2e/harness.mjs, on a watched run). Imports nothing: what it acts on
 * is handed in.
 */

/**
 * Moves the mouse to the middle of `locator` and waits `glide` ms for the
 * pointer -- the page first scrolled to it, gently, when it is out of sight.
 */
export async function glideTo(locator, glide) {
    try {
        await scrollTo(locator);
        // What easing could not bring in (sideways, a stuck header): at once
        await locator.scrollIntoViewIfNeeded({ timeout: 5000 });
        const box = await locator.boundingBox({ timeout: 5000 });

        if (box) {
            await locator
                .page()
                .mouse.move(box.x + box.width / 2, box.y + box.height / 2);
            await new Promise((resolve) => setTimeout(resolve, glide + 150));
        }
    } catch {
        // Not there yet: what comes next waits for it, as it always does
    }
}

/**
 * Scrolls whatever holds `locator` -- the page, and any box of its own that
 * scrolls -- so it ends near the middle of the screen, eased over 600-900 ms
 * (the further, the longer), as a person would. Nothing when it is in sight.
 */
export async function scrollTo(locator) {
    const element = await locator.elementHandle({ timeout: 5000 });

    await element.evaluate(ease, { by: null }).catch(() => {});
    await element.dispose();
}

/**
 * Scrolls the page a screen further down, eased: for showing a long page --
 * the page's own scroll, or the biggest box that scrolls when the page itself
 * does not.
 */
export const scrollDown = (page) =>
    page
        .locator('html')
        .evaluate(ease, { by: 'screen' })
        .catch(() => {});

// Runs in the page: brings `thing` into sight, or with `by`, goes a screen
// further down
function ease(thing, { by }) {
    const scrolls = (box) => {
        if (box === document.scrollingElement) {
            // A page kept still by its own styles (an app in a box) stays so
            return (
                box.scrollHeight > box.clientHeight &&
                ![document.documentElement, document.body].some((one) =>
                    /hidden|clip/.test(getComputedStyle(one).overflowY),
                )
            );
        }

        const { overflowY } = getComputedStyle(box);

        return (
            /(auto|scroll|overlay)/.test(overflowY) &&
            box.scrollHeight > box.clientHeight
        );
    };
    const moves = [];

    if (by === 'screen') {
        const page = document.scrollingElement;
        // An app drawn in a box of its own: the biggest that scrolls
        const box = scrolls(page)
            ? page
            : [...document.querySelectorAll('body *')]
                  .filter(scrolls)
                  .sort((a, b) => b.clientHeight - a.clientHeight)[0];

        if (!box) {
            return;
        }

        const height =
            box === page ? innerHeight : box.getBoundingClientRect().height;
        const most = box.scrollHeight - box.clientHeight;
        const to = Math.min(most, box.scrollTop + Math.round(height * 0.8));

        if (to > box.scrollTop) {
            moves.push({ box, from: box.scrollTop, to });
        }
    } else {
        const rect = thing.getBoundingClientRect();

        // In sight already: left where it is
        if (rect.top >= 0 && rect.bottom <= innerHeight) {
            let hidden = false;

            for (let box = thing.parentElement; box; box = box.parentElement) {
                if (box !== document.body && scrolls(box)) {
                    const { top, bottom } = box.getBoundingClientRect();
                    hidden ||= rect.top < top || rect.bottom > bottom;
                }
            }

            if (!hidden) {
                return;
            }
        }

        // Where its middle will be, as each box from the innermost out moves
        let middle = rect.top + rect.height / 2;

        for (let box = thing.parentElement; box; box = box.parentElement) {
            const page = box === document.scrollingElement;

            if ((box === document.body && !page) || !scrolls(box)) {
                continue;
            }

            const { top, height } = page
                ? { top: 0, height: innerHeight }
                : box.getBoundingClientRect();
            const most = box.scrollHeight - box.clientHeight;
            const to = Math.max(
                0,
                Math.min(
                    most,
                    Math.round(box.scrollTop + middle - (top + height / 2)),
                ),
            );

            if (to !== box.scrollTop) {
                moves.push({ box, from: box.scrollTop, to });
                middle -= to - box.scrollTop;
            }
        }
    }

    if (!moves.length) {
        return;
    }

    const far = Math.max(...moves.map(({ from, to }) => Math.abs(to - from)));
    const took = Math.min(900, 600 + far / 4);
    // A site's own smooth scrolling would chase each frame's setting: off
    const behaviour = moves.map(({ box }) => box.style.scrollBehavior);
    moves.forEach(({ box }) => (box.style.scrollBehavior = 'auto'));

    return new Promise((done) => {
        const began = performance.now();
        const frame = (now) => {
            const t = Math.min(1, (now - began) / took);
            // Slow at each end, as a hand on a wheel
            const eased = t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2;

            for (const { box, from, to } of moves) {
                box.scrollTop = from + (to - from) * eased;
            }

            if (t < 1) {
                requestAnimationFrame(frame);
            } else {
                moves.forEach(
                    ({ box }, at) => (box.style.scrollBehavior = behaviour[at]),
                );
                done();
            }
        };

        requestAnimationFrame(frame);
    });
}

/**
 * Clears `locator` and types `text` into it, `typing` ms a key. `plain` gives
 * the click and fill to use, where the locator's own have been changed.
 */
export async function typeInto(locator, text, typing, plain = {}) {
    const {
        click = (o) => locator.click(o),
        fill = (v, o) => locator.fill(v, o),
        options = {},
    } = plain;
    await click(options);
    await fill('', options);

    return locator.pressSequentially(text, { delay: typing });
}

/**
 * Writes `text` across the foot of the page, green for what worked, red for
 * what did not, grey for what is happening now -- a few sentences wrapped in
 * a box, moved to the top while the pointer is under it, so what is pressed
 * is never hidden. Taken away with `null`.
 */
export const caption = (page, text, tone = 'now') =>
    page
        .evaluate(
            ([text, tone]) => {
                let foot = document.getElementById('e2e-caption');

                if (text === null) {
                    foot?.remove();

                    return;
                }

                if (!foot) {
                    foot = document.createElement('div');
                    foot.id = 'e2e-caption';
                    foot.style.cssText =
                        'position:fixed;left:50%;bottom:72px;transform:translateX(-50%);z-index:2147483647;box-sizing:border-box;width:max-content;max-width:max(70vw,min(92vw,340px));padding:12px 20px;border-radius:12px;font:500 17px/1.45 system-ui,sans-serif;color:#fff;white-space:pre-line;overflow-wrap:anywhere;box-shadow:0 6px 24px rgba(0,0,0,.25);pointer-events:none';
                    // Out of the pointer's way: to the top while it is under
                    // the box, back down once it is clear of where it was
                    addEventListener(
                        'mousemove',
                        (event) => {
                            const box = foot.getBoundingClientRect();
                            const near = (top, bottom) =>
                                event.clientY > top - 48 &&
                                event.clientY < bottom + 48;
                            const low = foot.style.top === '';

                            if (low && near(box.top, box.bottom)) {
                                foot.style.bottom = '';
                                foot.style.top = '24px';
                            } else if (
                                !low &&
                                !near(
                                    innerHeight - 72 - box.height,
                                    innerHeight - 72,
                                )
                            ) {
                                foot.style.top = '';
                                // Clear of a player's controls over the film's foot
                                foot.style.bottom = '72px';
                            }
                        },
                        true,
                    );
                    // Its words behind a closed shadow root: shown, but never
                    // found by a step looking for the page's own words
                    foot.words = document.createElement('span');
                    foot.attachShadow({ mode: 'closed' }).append(foot.words);
                    document.body.append(foot);
                }

                foot.style.background = {
                    ok: '#15803d',
                    failed: '#b91c1c',
                    now: '#1f2937',
                }[tone];
                foot.words.textContent =
                    { ok: '✓ ', failed: '✗ ', now: '' }[tone] + text;
            },
            [text, tone],
        )
        .catch(() => {});

/**
 * A card over the whole page naming what comes next -- a check's title, and
 * where it falls ("3 of 10") -- for a film of several. Taken away with `null`.
 */
export const titleCard = (page, title, where = '') =>
    page
        .evaluate(
            ([title, where]) => {
                document.getElementById('e2e-title')?.remove();

                if (title === null) {
                    return;
                }

                const card = document.createElement('div');
                card.id = 'e2e-title';
                card.style.cssText =
                    'position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.92);pointer-events:none';
                // Behind a closed shadow root: shown, never found by a step
                const inside = card.attachShadow({ mode: 'closed' });
                const words = document.createElement('div');
                words.style.cssText =
                    'max-width:80vw;text-align:center;color:#fff;font:600 clamp(24px,4vw,44px)/1.25 system-ui,sans-serif';
                words.textContent = title;
                const small = document.createElement('div');
                small.style.cssText =
                    'margin-top:14px;color:#cbd5e1;font:500 clamp(14px,1.6vw,18px)/1.4 system-ui,sans-serif;letter-spacing:.04em';
                small.textContent = where;
                const box = document.createElement('div');
                box.append(words, small);
                inside.append(box);
                (document.body ?? document.documentElement).append(card);
            },
            [title, where],
        )
        .catch(() => {});
