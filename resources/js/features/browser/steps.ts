// The steps a browser check is made of -- the same few a browser suite takes
// (tests/e2e) and a tour of the app plays: open a page, click, type, press a
// key, expect to see something, take a picture. A target is whatever a person
// would go by: a button's or link's words, a field's label or placeholder, or
// words on the page.

export type StepKind =
    | 'open'
    | 'click'
    | 'hover'
    | 'type'
    | 'choose'
    | 'upload'
    | 'press'
    | 'back'
    | 'scroll'
    | 'see'
    | 'gone'
    | 'address'
    | 'shot';

export type Step = {
    do: StepKind;
    url?: string;
    target?: string;
    text?: string;
    key?: string;
    // Where a thing with no words was picked from on the page, to find it by
    selector?: string;
    // Which of those sharing its name it is: the 3rd "Add to cart"
    nth?: number | string;
    // The frame of the page it is in -- a card form, an embedded sign-up
    frame?: string;
    // The Drive file a step adds to the page (its ref_id); `text` its name
    file?: string;
    // What its writer tells whoever watches it: a demo video's caption
    note?: string;
    // How long a demo video stays on it, in seconds: its note's hold, or its pause
    wait?: number | string | null;
};

export type StepField = 'url' | 'target' | 'text' | 'key' | 'file';

/** What a step is for: doing something on the page, or checking it. */
export type StepGroup = 'do' | 'expect' | 'other';

export const KINDS: Record<
    StepKind,
    {
        label: string;
        fields: StepField[];
        placeholder?: string;
        group: StepGroup;
        // What its second box asks for
        textLabel?: string;
    }
> = {
    open: {
        label: 'Open a page',
        fields: ['url'],
        placeholder: 'https://…',
        group: 'do',
    },
    click: {
        label: 'Click',
        fields: ['target'],
        placeholder: 'A button, a link, or words on the page',
        group: 'do',
    },
    hover: {
        label: 'Point at',
        fields: ['target'],
        placeholder: 'What to hold the pointer over: a menu, a picture',
        group: 'do',
    },
    type: {
        label: 'Type into',
        fields: ['target', 'text'],
        placeholder: "A field's label or placeholder",
        group: 'do',
        textLabel: 'What to type',
    },
    choose: {
        label: 'Choose from a list',
        fields: ['target', 'text'],
        placeholder: "The list's label, or what it shows now",
        group: 'do',
        textLabel: 'The option to choose',
    },
    upload: {
        label: 'Add a file',
        fields: ['target', 'file'],
        placeholder: 'The file field, or the button that asks for one',
        group: 'do',
    },
    press: { label: 'Press a key', fields: ['key'], group: 'do' },
    back: { label: 'Go back', fields: [], group: 'do' },
    scroll: {
        label: 'Scroll',
        fields: ['target'],
        placeholder: 'What to scroll to, or leave empty for a screen down',
        group: 'do',
    },
    see: {
        label: 'Expect to see',
        fields: ['target'],
        placeholder: 'Words that should be on the page',
        group: 'expect',
    },
    gone: {
        label: 'Expect not to see',
        fields: ['target'],
        placeholder: 'Words that should be gone: an error, "Loading…"',
        group: 'expect',
    },
    address: {
        label: 'Expect the address to have',
        fields: ['text'],
        placeholder: 'Part of the address, e.g. /thank-you',
        group: 'expect',
        textLabel: 'Part of the address',
    },
    shot: { label: 'Take a picture', fields: [], group: 'other' },
};

export const GROUPS: Record<StepGroup, string> = {
    do: 'Do on the page',
    expect: 'Expect',
    other: 'Other',
};

export const KEYS = [
    'Enter',
    'Tab',
    'Escape',
    'Backspace',
    'ArrowDown',
    'ArrowUp',
    'Space',
];

/** The screen a check is done on. */
export type Size = 'desktop' | 'tablet' | 'phone';

export const SIZES: Record<Size, string> = {
    desktop: 'Desktop',
    tablet: 'Tablet',
    phone: 'Phone',
};

/** A check: a few steps, with a name to know it by, done on one screen. */
export type Check = { id: string; title: string; size?: Size; steps: Step[] };

export type Pace = { glide: number; typing: number; pause: number };

/** How a run is asked for: its pace, what it shows and keeps, which checks (none: all). */
export type RunChoice = {
    speed: Speed;
    pace: Pace;
    captions: boolean;
    record: boolean;
    keep: boolean;
    checks: string[];
    // Every check one after another in one browser, filmed as one
    one_film?: boolean;
};
export type Every = 'off' | 'hour' | 'day' | 'weekdays';
export type Speed = 'quick' | 'watch' | 'steady' | 'slow' | 'custom';

/** How each speed is said, and what it is for. */
export const SPEEDS: Record<Speed, { label: string; about: string }> = {
    quick: {
        label: 'Quick',
        about: 'As fast as the browser goes: for testing.',
    },
    watch: {
        label: 'Watchable',
        about: 'The pointer glides to each thing and types key by key, as a watched test run does.',
    },
    steady: {
        label: 'Steady',
        about: 'A little slower: what a demo video goes at.',
    },
    slow: {
        label: 'Slow',
        about: 'Slower still: for a demo someone talks over.',
    },
    custom: { label: 'Your own', about: 'Set each part of the pace.' },
};

/** A picture or film kept in the Drive. */
export type Kept = { name: string; url: string; ref_id: string };

/** What one step did, as the browser answered. */
export type StepResult = {
    say: string;
    ok: boolean;
    took: number;
    error?: string;
    // In the Drive, or (when not kept) the picture itself
    shot?: string | Kept | null;
};

/** What one check did in a run. */
export type CheckResult = {
    id: string;
    title: string;
    ok: boolean;
    took: number;
    planned: number;
    steps: StepResult[];
    refused: string[];
    video: Kept | null;
    problem?: string;
};

export type RunState = 'waiting' | 'running' | 'passed' | 'failed';
export type StartedBy = 'person' | 'timetable' | 'workflow';

/** One run of a suite. */
export type CheckRun = {
    ref_id: string;
    state: RunState;
    settings: {
        speed: Speed;
        pace: Pace;
        captions: boolean;
        record: boolean;
        keep: boolean;
        checks: string[];
    };
    results: CheckResult[];
    created_at: string;
    started_at: string | null;
    ended_at: string | null;
    by: string | null;
    started_by: StartedBy;
    tickets: { number: number; title: string; new: boolean; url: string }[];
    folder: string | null;
    url: string;
};

/** A suite as the page lists it. */
export type SuiteSummary = {
    ref_id: string;
    title: string;
    checks: number;
    last: RunState | null;
    last_at?: string | null;
    // The last few runs, oldest first
    recent?: RunState[];
    url: string;
};

/** One run of a suite, in short, as its history lists it. */
export type RunSummary = {
    ref_id: string;
    state: RunState;
    speed: Speed;
    record: boolean;
    started_by: StartedBy;
    created_at: string;
    // How long it took, and how many of its checks passed
    took?: number | null;
    passed?: number;
    total?: number;
    url: string;
};

/** A suite opened: its checks, and its runs in short. */
export type Suite = {
    ref_id: string;
    title: string;
    checks: Check[];
    runs: RunSummary[];
    // How each check did the last time it ran, by its id
    latest: Record<string, { ok: boolean; took: number; at: string }>;
    urls: { update: string; runs: string; look: string; live: string };
    // When it runs by itself, and whether a failure files a ticket
    settings: {
        every: Every;
        at: string;
        zone: string | null;
        tickets: boolean;
    };
    project: boolean;
    next_run_at: string | null;
    // Only the names of its hidden values: a value is never sent back
    values: { name: string; set: boolean }[];
};

/** Where a picture is: a Drive file's address, or the picture itself. */
export const shotUrl = (shot: StepResult['shot']) =>
    typeof shot === 'string' ? shot : (shot?.url ?? null);

export const seconds = (ms: number) => `${(ms / 1000).toFixed(1)}s`;

/** Something on a page a step can be about, by the name a step gives it. */
export type Thing = {
    kind:
        | 'field'
        | 'button'
        | 'link'
        | 'tab'
        | 'checkbox'
        | 'radio'
        | 'picture'
        | 'list'
        | 'file'
        | 'words';
    name: string;
    // How many share the name: a step goes by the first
    count: number;
    // Where it is on the page's picture, in its pixels
    box: { x: number; y: number; w: number; h: number };
    // Where it is in the page, for one with no words to be found by, or one
    // of several sharing a name -- and which of them, when it is (nth)
    selector?: string;
    nth?: number;
    // A list the browser draws itself: what it offers, and what it shows now
    options?: string[];
    chosen?: string;
    // In a frame of the page, by the frame's path
    frame?: string;
    // A field of a kind of its own -- a slider and its ends, a date
    input?: string;
    min?: string;
    max?: string;
};

/** A page a check's first steps lead to, and what is on it. */
export type Look = {
    steps: { ok: boolean; error?: string }[];
    url: string;
    title: string;
    things: Thing[];
    // Things further down than the picture goes
    below: number;
    shot: string | null;
    size: { width: number; height: number };
};

/** A page's address, short: its host and path, or "a page of its own". */
const address = (url = '') => {
    if (url.toLowerCase().startsWith('data:')) {
        return 'a page of its own';
    }

    try {
        const parsed = new URL(url);

        return `${parsed.host}${parsed.pathname}`.replace(/\/$/, '');
    } catch {
        return url;
    }
};

const ordinal = (n: number) =>
    `${n}${[11, 12, 13].includes(n % 100) ? 'th' : (['th', 'st', 'nd', 'rd'][n % 10] ?? 'th')}`;

// One of several sharing a name: which
const which = (step: Step) =>
    step.nth ? `the ${ordinal(Number(step.nth))} ` : '';

/**
 * How long a demo video stays on a step left to itself, in seconds: its
 * note's hold (3 s to 8 s by its length), or the steady pace's pause --
 * none after a step nothing is seen to happen in (check.mjs).
 */
export function lingers(step: Step): number {
    if (step.note?.trim()) {
        return (
            Math.min(
                8000,
                Math.max(3000, 1500 + 15 * step.note.trim().length),
            ) / 1000
        );
    }

    return ['see', 'gone', 'address', 'shot'].includes(step.do) ? 0 : 1.15;
}

/** A step in a few words, as a run and a caption say it (BrowserCheck::say). */
export function say(step: Step): string {
    switch (step.do) {
        case 'open':
            return `Open ${address(step.url)}`;
        case 'click':
            return `Click ${which(step)}"${step.target ?? ''}"`;
        case 'hover':
            return `Point at ${which(step)}"${step.target ?? ''}"`;
        case 'upload':
            return `Add "${step.text || 'a file'}" to "${step.target ?? ''}"`;
        case 'back':
            return 'Go back';
        case 'scroll':
            return step.target
                ? `Scroll to ${which(step)}"${step.target}"`
                : 'Scroll down';
        case 'gone':
            return `Expect not to see "${step.target ?? ''}"`;
        case 'address':
            return `Expect the address to have "${step.text ?? ''}"`;
        case 'type':
            return `Type "${step.text ?? ''}" into "${step.target ?? ''}"`;
        case 'choose':
            return `Choose "${step.text ?? ''}" in "${step.target ?? ''}"`;
        case 'press':
            return `Press ${step.key ?? 'Enter'}`;
        case 'see':
            return `Expect to see ${which(step)}"${step.target ?? ''}"`;
        default:
            return 'Take a picture';
    }
}

/** A page kept open in the browser while a check is built on it. */
export type LivePage = Look & { id: string };

/** What one more step did on a live page, and the page now. */
export type LiveStep = Omit<Look, 'steps'> & {
    step: { ok: boolean; error?: string };
};
