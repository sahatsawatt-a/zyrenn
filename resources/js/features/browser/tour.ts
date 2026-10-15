import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { drawCursor } from './cursor.js';

// A tour: the app showing how something is done by doing it, in the person's
// own page -- a cursor glides to each thing, presses it, types, and a caption
// says what is happening. It plays across pages (the player lives in the
// layout, which stays as the pages change), waiting for each thing to turn up
// rather than for a while, as a browser suite does.

export type TourStep = {
    say: string;
    // point (the default): only shown; wait: until it turns up, with no cursor
    do?: 'point' | 'click' | 'type' | 'press' | 'drag' | 'wait';
    // What it is about: a CSS selector, words it holds, or both
    at?: string;
    text?: string;
    // What is typed, or the key pressed
    value?: string;
    key?: string;
    // For a drag: where from and to, as parts of the thing's width and height
    from?: [number, number];
    to?: [number, number];
    // Only on a narrow screen (where the menu is folded away), or a wide one
    narrow?: boolean;
    wide?: boolean;
};

export type Tour = {
    id: string;
    // The Guide topic it is offered on (resources/guide, by slug)
    topic: string;
    title: string;
    about: string;
    // What it leaves behind, said before it starts; nothing when left out
    makes?: string;
    steps: TourStep[];
};

export const player = reactive({
    tour: null as Tour | null,
    // Which step is going, of the ones played on this screen
    at: 0,
    steps: [] as TourStep[],
    paused: false,
    done: false,
    problem: null as string | null,
    // The caption goes to the top while what it is about is low on the screen
    high: false,
});

// How long the cursor takes to get somewhere, and a caption stays up: for
// someone to follow, not for the app, which is waited for by what it shows.
// A browser suite plays tours quicker (sessionStorage zyrenn:tour-pace).
const pace = () => {
    try {
        return Number(sessionStorage.getItem('zyrenn:tour-pace') ?? 1) || 1;
    } catch {
        return 1;
    }
};
const glide = () => 700 * pace();
const typing = () => 45 * pace();
const reading = (words: string) => (900 + words.length * 28) * pace();
const FIND_MAX = 10_000;

let playing = 0;

// Whether the app is on its way to another page: a step after a click that
// goes somewhere looks on the page it arrives at, not the one it leaves
let visiting = false;

if (typeof document !== 'undefined') {
    router.on('start', () => {
        visiting = true;
    });
    router.on('finish', () => {
        visiting = false;
    });
}

const pause = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/** Holds while paused; answers false once the tour is stopped or replaced. */
async function going(run: number) {
    while (player.paused && run === playing) {
        await pause(100);
    }

    return run === playing;
}

const narrow = () => window.matchMedia('(max-width: 767px)').matches;

function visible(element: Element) {
    const box = element.getBoundingClientRect();

    return (
        box.width > 0 &&
        box.height > 0 &&
        getComputedStyle(element).visibility !== 'hidden' &&
        !element.closest('[data-tour-player]')
    );
}

/** Ready to be pressed or written in: not off, not only for reading. */
function usable(element: HTMLElement) {
    if (element.matches(':disabled, [aria-disabled="true"]')) {
        return false;
    }

    if (
        element instanceof HTMLInputElement ||
        element instanceof HTMLTextAreaElement
    ) {
        return !element.readOnly;
    }

    return (
        !element.hasAttribute('contenteditable') || element.isContentEditable
    );
}

const words = (element: Element) =>
    (
        (element as HTMLElement).innerText ||
        element.getAttribute('aria-label') ||
        (element as HTMLInputElement).placeholder ||
        ''
    ).toLowerCase();

/** The thing a step is about: the innermost one that fits, when there is one. */
function find(step: TourStep): HTMLElement | null {
    const pool = [
        ...document.querySelectorAll<HTMLElement>(
            step.at ??
                'a, button, [role="button"], [role="menuitem"], [role="tab"], input, textarea, label, h1, h2, h3, p, li, span',
        ),
    ];
    const wanted = step.text?.toLowerCase();
    const fits = pool.filter(
        (element) =>
            visible(element) &&
            (!wanted || words(element).includes(wanted)) &&
            (!['click', 'type'].includes(step.do ?? '') || usable(element)),
    );

    return (
        fits.find(
            (element) =>
                !fits.some(
                    (other) => other !== element && element.contains(other),
                ),
        ) ?? null
    );
}

async function until<T>(read: () => T | null, run: number) {
    const began = Date.now();

    while (Date.now() - began < FIND_MAX && run === playing) {
        const found = read();

        if (found) {
            return found;
        }

        await pause(100);
    }

    return null;
}

function centre(element: Element, at: [number, number] = [0.5, 0.5]) {
    const box = element.getBoundingClientRect();

    return {
        x: box.left + box.width * at[0],
        y: box.top + box.height * at[1],
    };
}

/** The events a real press makes, in the order it makes them. */
function pointer(
    element: Element,
    kind: 'down' | 'move' | 'up',
    { x, y }: { x: number; y: number },
) {
    const options = {
        bubbles: true,
        cancelable: true,
        composed: true,
        clientX: x,
        clientY: y,
        button: 0,
        buttons: kind === 'up' ? 0 : 1,
        view: window,
    };
    // What is under the point, beneath the tour's own layer
    const target =
        document
            .elementsFromPoint(x, y)
            .find((under) => !under.closest('[data-tour-player]')) ?? element;
    target.dispatchEvent(
        new PointerEvent(`pointer${kind}`, {
            ...options,
            pointerId: 1,
            pointerType: 'mouse',
            isPrimary: true,
        }),
    );
    target.dispatchEvent(new MouseEvent(`mouse${kind}`, options));

    return { target, options };
}

function click(element: HTMLElement) {
    const at = centre(element);
    pointer(element, 'down', at);
    element.focus?.();
    const { options } = pointer(element, 'up', at);
    element.dispatchEvent(new MouseEvent('click', options));
}

async function typeInto(element: HTMLElement, text: string, run: number) {
    element.focus();

    if (
        element instanceof HTMLInputElement ||
        element instanceof HTMLTextAreaElement
    ) {
        // Through the browser's own setter, so Vue's v-model hears of it
        const prototype = Object.getPrototypeOf(element);

        for (const letter of text) {
            if (!(await going(run))) {
                return;
            }

            Object.getOwnPropertyDescriptor(prototype, 'value')?.set?.call(
                element,
                element.value + letter,
            );
            element.dispatchEvent(
                new InputEvent('input', {
                    bubbles: true,
                    data: letter,
                    inputType: 'insertText',
                }),
            );
            await pause(typing());
        }

        return;
    }

    // An editor (a note's): as if typed, which the editor reads back
    for (const letter of text) {
        if (!(await going(run))) {
            return;
        }

        document.execCommand('insertText', false, letter);
        await pause(typing());
    }
}

function press(key: string) {
    const target = (document.activeElement as HTMLElement) ?? document.body;

    for (const type of ['keydown', 'keyup']) {
        target.dispatchEvent(
            new KeyboardEvent(type, {
                key,
                code: key,
                bubbles: true,
                cancelable: true,
            }),
        );
    }
}

/** Plays `tour` from the start, stopping any other that is going. */
export async function play(tour: Tour) {
    const run = ++playing;
    const steps = tour.steps.filter(
        (step) => !(step.narrow && !narrow()) && !(step.wide && narrow()),
    );
    Object.assign(player, {
        tour,
        steps,
        at: 0,
        paused: false,
        done: false,
        problem: null,
        high: false,
    });
    const cursor = drawCursor();
    cursor.moveTo(window.innerWidth / 2, window.innerHeight / 2, 0);

    try {
        for (const [at, step] of steps.entries()) {
            if (!(await going(run))) {
                return;
            }

            player.at = at;
            const element = await until(() => find(step), run);

            if (run !== playing) {
                return;
            }

            if (!element) {
                player.problem = `Couldn't find ${step.text ? `"${step.text}"` : 'what comes next'} on this page.`;

                return;
            }

            if (step.do === 'wait') {
                continue;
            }

            element.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            const start = centre(element, step.from);
            player.high =
                centre(element, step.to ?? step.from).y >
                window.innerHeight * 0.55;
            cursor.moveTo(start.x, start.y, glide());
            await pause(glide() + 100);

            if (!(await going(run))) {
                return;
            }

            switch (step.do) {
                case 'click':
                    cursor.press(start.x, start.y);
                    click(element);
                    // A visit begins a moment after the click, if it does
                    await pause(150);
                    await until(() => (visiting ? null : true), run);
                    break;
                case 'type':
                    cursor.press(start.x, start.y);
                    click(element);
                    await typeInto(element, step.value ?? '', run);
                    break;
                case 'press':
                    press(step.key ?? 'Enter');
                    break;
                case 'drag': {
                    const end = centre(element, step.to);
                    cursor.press(start.x, start.y);
                    pointer(element, 'down', start);

                    for (let move = 1; move <= 8; move++) {
                        const point = {
                            x: start.x + ((end.x - start.x) * move) / 8,
                            y: start.y + ((end.y - start.y) * move) / 8,
                        };
                        cursor.moveTo(point.x, point.y, 60);
                        pointer(element, 'move', point);
                        await pause(60);
                    }

                    pointer(element, 'up', end);
                    break;
                }
            }

            await pause(reading(step.say));
        }

        if (run === playing) {
            player.done = true;
        }
    } finally {
        // A tour that is replaced leaves the cursor to the next one
        if (run === playing || !player.tour) {
            cursor.remove();
        }
    }
}

export function stop() {
    playing++;
    player.tour = null;
    document.getElementById('e2e-cursor')?.remove();
}
