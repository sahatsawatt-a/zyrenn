import {
    AtSign,
    BadgeCheck,
    Ban,
    BookOpenText,
    Bot,
    Braces,
    Code,
    ChartBar,
    CheckCheck,
    CircleCheck,
    CircleDashed,
    CircleSlash,
    CircleX,
    Clock,
    Contact,
    Copy,
    CornerDownRight,
    CreditCard,
    Ellipsis,
    FileDown,
    FileText,
    FileUp,
    FolderSearch,
    Forward,
    Gauge,
    Globe,
    HardDriveDownload,
    Image,
    Images,
    Info,
    Link,
    List,
    ListTree,
    Loader2,
    Locate,
    LogOut,
    MapPin,
    MapPinned,
    Megaphone,
    Merge,
    MessageCircle,
    MessageSquare,
    MessageSquareDashed,
    MessageSquarePlus,
    MessagesSquare,
    MousePointerClick,
    Network,
    Newspaper,
    PanelBottom,
    PanelBottomClose,
    PanelBottomDashed,
    PanelBottomOpen,
    Pencil,
    Pin,
    PinOff,
    Play,
    Repeat,
    Reply,
    ScrollText,
    Search,
    Send,
    Shield,
    Signpost,
    Slash,
    Smile,
    Sparkles,
    Split,
    SquareMousePointer,
    SquarePlay,
    Sticker,
    Table2,
    Tag,
    Terminal,
    TextSearch,
    ThumbsDown,
    ThumbsUp,
    Timer,
    Trash2,
    UserCheck,
    Users,
    UserSearch,
    Webhook,
    Workflow,
} from '@lucide/vue';
import type { Component } from 'vue';
import type {
    Does,
    NodeType,
    RunStatus,
    StepNode,
    StepStatus,
} from '@/features/workflow/types';

const ICONS: Record<string, Component> = {
    play: Play,
    braces: Braces,
    code: Code,
    split: Split,
    signpost: Signpost,
    merge: Merge,
    repeat: Repeat,
    'corner-down-right': CornerDownRight,
    'user-search': UserSearch,
    terminal: Terminal,
    timer: Timer,
    'user-check': UserCheck,
    clock: Clock,
    'message-square': MessageSquare,
    'messages-square': MessagesSquare,
    'message-square-plus': MessageSquarePlus,
    'scroll-text': ScrollText,
    'table-2': Table2,
    'hard-drive-download': HardDriveDownload,
    'folder-search': FolderSearch,
    'file-text': FileText,
    'file-down': FileDown,
    'file-up': FileUp,
    reply: Reply,
    images: Images,
    sticker: Sticker,
    'map-pin': MapPin,
    contact: Contact,
    'chart-bar': ChartBar,
    forward: Forward,
    copy: Copy,
    'credit-card': CreditCard,
    'message-square-dashed': MessageSquareDashed,
    ellipsis: Ellipsis,
    pencil: Pencil,
    'square-mouse-pointer': SquareMousePointer,
    'trash-2': Trash2,
    pin: Pin,
    'pin-off': PinOff,
    smile: Smile,
    'mouse-pointer-click': MousePointerClick,
    slash: Slash,
    ban: Ban,
    shield: Shield,
    'at-sign': AtSign,
    'badge-check': BadgeCheck,
    info: Info,
    users: Users,
    megaphone: Megaphone,
    'check-check': CheckCheck,
    'log-out': LogOut,
    'panel-bottom': PanelBottom,
    'panel-bottom-close': PanelBottomClose,
    'panel-bottom-open': PanelBottomOpen,
    'panel-bottom-dashed': PanelBottomDashed,
    tag: Tag,
    list: List,
    link: Link,
    gauge: Gauge,
    sparkles: Sparkles,
    globe: Globe,
    webhook: Webhook,
    send: Send,
    'message-circle': MessageCircle,
    'thumbs-up': ThumbsUp,
    'thumbs-down': ThumbsDown,
    search: Search,
    newspaper: Newspaper,
    image: Image,
    'map-pinned': MapPinned,
    'square-play': SquarePlay,
    'text-search': TextSearch,
    locate: Locate,
    'book-open-text': BookOpenText,
    bot: Bot,
    network: Network,
    'list-tree': ListTree,
};

// Each group -- a service, or the app's own flow and data -- its colour:
// the strip down a step's side, its icon's tile
const TONES: Record<string, { strip: string; tile: string }> = {
    flow: {
        strip: 'bg-violet-500',
        tile: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    },
    data: {
        strip: 'bg-slate-500',
        tile: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
    },
    chat: {
        strip: 'bg-indigo-500',
        tile: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    },
    table: {
        strip: 'bg-teal-500',
        tile: 'bg-teal-500/10 text-teal-600 dark:text-teal-400',
    },
    drive: {
        strip: 'bg-orange-500',
        tile: 'bg-orange-500/10 text-orange-600 dark:text-orange-400',
    },
    ai: {
        strip: 'bg-fuchsia-500',
        tile: 'bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400',
    },
    web: {
        strip: 'bg-amber-500',
        tile: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    },
    serpapi: {
        strip: 'bg-cyan-500',
        tile: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
    },
    tavily: {
        strip: 'bg-rose-500',
        tile: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    },
    promptx: {
        strip: 'bg-purple-500',
        tile: 'bg-purple-500/10 text-purple-600 dark:text-purple-400',
    },
    telegram: {
        strip: 'bg-sky-500',
        tile: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    },
    line: {
        strip: 'bg-green-500',
        tile: 'bg-green-500/10 text-green-600 dark:text-green-400',
    },
    linkedin: {
        strip: 'bg-blue-600',
        tile: 'bg-blue-600/10 text-blue-700 dark:text-blue-400',
    },
    whatsapp: {
        strip: 'bg-lime-500',
        tile: 'bg-lime-500/10 text-lime-700 dark:text-lime-400',
    },
    channel: {
        strip: 'bg-stone-500',
        tile: 'bg-stone-500/10 text-stone-700 dark:text-stone-300',
    },
    facebook: {
        strip: 'bg-sky-600',
        tile: 'bg-sky-600/10 text-sky-700 dark:text-sky-400',
    },
    instagram: {
        strip: 'bg-pink-500',
        tile: 'bg-pink-500/10 text-pink-600 dark:text-pink-400',
    },
    threads: {
        strip: 'bg-neutral-700 dark:bg-neutral-300',
        tile: 'bg-neutral-500/10 text-neutral-700 dark:text-neutral-300',
    },
};

// What starts a run is marked the same whichever service it is of
export const TRIGGER_STRIP = 'bg-emerald-500';

export const iconOf = (type?: NodeType): Component =>
    (type && ICONS[type.icon]) || Workflow;

export const toneOf = (type?: NodeType) =>
    (type && TONES[type.group]) || TONES.data;

// What a way out is called on the canvas; a single one needs no name
export const handleLabel = (handle: string) =>
    ({
        true: 'true',
        false: 'false',
        approved: 'approved',
        rejected: 'rejected',
        // A reply that could not go, for a push to send instead
        unsent: 'not sent',
    })[handle] ?? '';

/**
 * The ways out of one step: its kind's, or a Switch's own -- each way it
 * names, then "otherwise" (App\Support\Workflow\NodeTypes::outputsOf).
 */
export function outputsOf(step: StepNode, type?: NodeType): string[] {
    if (step.type === 'logic.switch') {
        const named = (Array.isArray(step.config.ways) ? step.config.ways : [])
            .map((way: { name?: unknown }) =>
                typeof way?.name === 'string' ? way.name.trim() : '',
            )
            .filter((name: string) => name !== '' && name !== 'otherwise');

        return [...new Set<string>(named), 'otherwise'];
    }

    return type?.outputs ?? ['out'];
}

// Which ways out mean no, drawn in red; the other named ones in green
export const isNo = (handle: string) =>
    ['false', 'rejected', 'unsent'].includes(handle);

/** "Set data 2" → "set_data_2": a key a formula can name, not yet taken. */
export function freshKey(label: string, taken: Set<string>): string {
    const base =
        label
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .replace(/^(\d)/, 's_$1')
            .slice(0, 32) || 'step';

    let key = base;

    for (let n = 2; taken.has(key); n++) {
        key = `${base}_${n}`;
    }

    return key;
}

const randomId = () => Math.random().toString(36).slice(2, 10);

/** A new step of a kind, set up with that kind's defaults. */
export function newStep(
    type: NodeType,
    position: { x: number; y: number },
    taken: Set<string>,
): StepNode {
    return {
        id: randomId(),
        key: freshKey(type.label, taken),
        type: type.type,
        nodeVersion: type.version,
        label: type.label,
        position: { x: Math.round(position.x), y: Math.round(position.y) },
        config: Object.fromEntries(
            type.config.map((setting) => [
                setting.name,
                setting.type === 'token'
                    ? freshToken()
                    : plainCopy(setting.default ?? null),
            ]),
        ),
        onError: 'stop',
    };
}

/**
 * A copy of settings, which are plain JSON. Not structuredClone: what the
 * page holds is wrapped by Vue, and a wrapped list can't be cloned that way.
 */
export const plainCopy = <T>(value: T): T => JSON.parse(JSON.stringify(value));

/** A webhook's secret address: 32 letters and digits, from the browser's own randomness. */
export function freshToken(): string {
    const letters =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    const bytes = crypto.getRandomValues(new Uint8Array(32));

    return Array.from(bytes, (byte) => letters[byte % letters.length]).join('');
}

/** A step's settings copied, with any secret address of its own made afresh. */
export function copyConfig(
    type: NodeType,
    config: Record<string, unknown>,
): Record<string, unknown> {
    const copy = plainCopy(config) as Record<string, unknown>;

    for (const setting of type.config) {
        if (setting.type === 'token') {
            copy[setting.name] = freshToken();
        }
    }

    return copy;
}

export const edgeId = (
    source: string,
    sourceHandle: string,
    target: string,
): string => `${source}.${sourceHandle}.${target}`;

// How a run or a step's status is shown: an icon, its colour, a word
export function statusLook(status: StepStatus | RunStatus): {
    icon: Component;
    tone: string;
    label: string;
    spin?: boolean;
} {
    switch (status) {
        case 'queued':
        case 'pending':
            return {
                icon: Clock,
                tone: 'text-muted-foreground',
                label: status === 'queued' ? 'Queued' : 'Waiting its turn',
            };
        case 'running':
            return {
                icon: Loader2,
                tone: 'text-sky-600 dark:text-sky-400',
                label: 'Running',
                spin: true,
            };
        case 'waiting':
        case 'retry_wait':
            return {
                icon: CircleDashed,
                tone: 'text-amber-600 dark:text-amber-400',
                label: 'Waiting',
            };
        case 'succeeded':
            return {
                icon: CircleCheck,
                tone: 'text-emerald-600 dark:text-emerald-400',
                label: 'Succeeded',
            };
        case 'failed':
            return { icon: CircleX, tone: 'text-destructive', label: 'Failed' };
        case 'skipped':
            return {
                icon: CircleSlash,
                tone: 'text-muted-foreground',
                label: 'Skipped',
            };
        default:
            return {
                icon: Ban,
                tone: 'text-muted-foreground',
                label: 'Cancelled',
            };
    }
}

/** "84 ms", "1.2 s", "2 min 5 s" */
export function duration(ms: number | null): string {
    if (ms === null) {
        return '';
    }

    if (ms < 1000) {
        return `${ms} ms`;
    }

    if (ms < 60000) {
        return `${(ms / 1000).toFixed(1)} s`;
    }

    return `${Math.floor(ms / 60000)} min ${Math.round((ms % 60000) / 1000)} s`;
}

/** A setting written over several lines: a template, or a named value. */
export const textBox =
    'border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border px-3 py-2 font-mono text-xs shadow-xs outline-none focus-visible:ring-[3px] disabled:opacity-60';

/** What a button's value does, and what is written of it once that is said. */
export function readDoes(
    does: Does[],
    value: string,
): { way: Does; rest: string } {
    const address = does.find(
        (way) => way.address && new RegExp(way.address, 'i').test(value),
    );

    if (address) {
        return { way: address, rest: value };
    }

    const prefixed = does.find(
        (way) => way.prefix && value.startsWith(way.prefix),
    );

    return prefixed
        ? { way: prefixed, rest: value.slice(prefixed.prefix!.length) }
        : { way: does.find((way) => !way.prefix) ?? does[0], rest: value };
}

/** A button's value, from what it does and what was written of it. */
export function writeDoes(way: Does, rest: string): string {
    return way.address && new RegExp(way.address, 'i').test(rest)
        ? rest
        : (way.prefix ?? '') + rest;
}
