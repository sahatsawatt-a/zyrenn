import {
    CreditCard,
    AtSign,
    Briefcase,
    Camera,
    Cpu,
    Flag,
    GitBranch,
    Hash,
    KeyRound,
    Mail,
    MessageCircle,
    MessageSquare,
    Phone,
    Plug,
    Radar,
    Search,
    Send,
    Server,
    ShieldCheck,
    Sparkles,
    Webhook,
    Workflow,
} from '@lucide/vue';
import type { Component } from 'vue';

// What the server says each service needs (App\Support\Connections\Services)
export type Field = {
    name: string;
    label: string;
    type: 'url' | 'text' | 'choice' | 'secret' | 'model';
    required: boolean;
    placeholder?: string;
    help?: string;
    options?: Record<string, string>;
    default?: string;
    // A secret used once, to sign in, and never kept
    spent?: boolean;
};

export type ServiceGroup = 'ai' | 'messaging' | 'social' | 'search' | 'http';

export type Service = {
    label: string;
    group: ServiceGroup;
    hint: string;
    testable: boolean;
    settings: Field[];
    secrets: Field[];
    // AI services only: where it usually lives
    url?: string;
};

export type SavedConnection = {
    ref_id: string;
    name: string;
    service: string;
    settings: Record<string, string>;
    // Which secrets it holds; never what they are
    saved_secrets: string[];
    added_by: string | null;
    created_at: string;
    // Where a bot or channel sends what it is told: pasted into LINE's
    // console by hand, or set by the app for Telegram; no url while nothing
    // outside can reach the app. A hint says where it is pasted, where that
    // is not LINE's console.
    hook: {
        url: string | null;
        set: 'by_hand' | 'by_app';
        hint?: string;
    } | null;
    // For one that is signed in on its service's own pages: the way there,
    // the address the service has to be told it comes back to and where that
    // is told, and how it stands -- 'no' before it ever was signed in, 'over'
    // once its time has run out
    sign_in: {
        url: string;
        callback: string;
        service: string;
        hint: string;
        state: 'yes' | 'no' | 'over';
        detail: string;
    } | null;
    // How its last test went; null before it was ever tested
    check?: LastCheck | null;
};

export type LastCheck = { ok: boolean; message: string | null; at: string };

export type CheckResult = {
    ok: boolean;
    message: string;
    models?: string[];
    free?: string[] | null;
};

export const GROUPS: {
    key: ServiceGroup;
    title: string;
    description: string;
}[] = [
    {
        key: 'ai',
        title: 'AI models',
        description: 'Hosts that answer chats, and AI steps in workflows.',
    },
    {
        key: 'messaging',
        title: 'Messaging',
        description: 'Bots that workflows hear from and send through.',
    },
    {
        key: 'social',
        title: 'Social',
        description: 'Members and pages that workflows post as.',
    },
    {
        key: 'search',
        title: 'Search',
        description: 'Keys for searching the web, and reading its pages.',
    },
    {
        key: 'http',
        title: 'HTTP & webhooks',
        description:
            'Keys for other APIs, and secrets that prove a webhook is genuine.',
    },
];

type Look = { icon: Component; tone: string };

const TONES = {
    violet: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    sky: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    green: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    blue: 'bg-blue-600/10 text-blue-700 dark:text-blue-400',
    pink: 'bg-pink-500/10 text-pink-600 dark:text-pink-400',
    amber: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    cyan: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
    rose: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    slate: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
};

// How a service is drawn: its icon on a tinted tile
export function lookOf(service: string, group?: ServiceGroup): Look {
    switch (service) {
        case 'ollama':
        case 'lmstudio':
            return { icon: Cpu, tone: TONES.violet };
        case 'custom':
            return { icon: Server, tone: TONES.violet };
        case 'telegram':
            return { icon: Send, tone: TONES.sky };
        case 'channel':
            return { icon: Webhook, tone: TONES.slate };
        case 'mcp':
            return { icon: Plug, tone: TONES.violet };
        case 'line':
            return { icon: MessageCircle, tone: TONES.green };
        case 'linkedin':
            return { icon: Briefcase, tone: TONES.blue };
        case 'facebook':
            return { icon: Flag, tone: TONES.sky };
        case 'instagram':
            return { icon: Camera, tone: TONES.pink };
        case 'threads':
            return { icon: AtSign, tone: TONES.slate };
        case 'whatsapp':
            return { icon: Phone, tone: TONES.green };
        case 'slack':
            return { icon: Hash, tone: TONES.violet };
        case 'discord':
            return { icon: MessageSquare, tone: TONES.blue };
        case 'smtp':
            return { icon: Mail, tone: TONES.rose };
        case 'serpapi':
            return { icon: Search, tone: TONES.cyan };
        case 'tavily':
            return { icon: Radar, tone: TONES.rose };
        case 'github':
            return { icon: GitBranch, tone: TONES.slate };
        case 'stripe':
            return { icon: CreditCard, tone: TONES.violet };
        case 'promptx':
            return { icon: Workflow, tone: TONES.violet };
        case 'http_header':
            return { icon: KeyRound, tone: TONES.amber };
        case 'webhook_secret':
            return { icon: ShieldCheck, tone: TONES.slate };
        default:
            return {
                icon: group === 'ai' || !group ? Sparkles : KeyRound,
                tone: TONES.violet,
            };
    }
}

// What each service is, in a few words, for choosing it, and where its key or
// app is got. Kept clear of the groups' own titles, which head the choice.
type Guide = { summary: string; docs?: { label: string; url: string } };

const META_APPS = {
    label: 'Meta for Developers',
    url: 'https://developers.facebook.com/apps/',
};

const GUIDES: Record<string, Guide> = {
    ollama: {
        summary: 'Models on your own machine, no key',
        docs: { label: 'Get Ollama', url: 'https://ollama.com/download' },
    },
    lmstudio: {
        summary: 'LM Studio’s local server, no key',
        docs: { label: 'Get LM Studio', url: 'https://lmstudio.ai' },
    },
    openrouter: {
        summary: 'Hundreds of hosted models behind one key',
        docs: { label: 'OpenRouter keys', url: 'https://openrouter.ai/keys' },
    },
    openai: {
        summary: 'OpenAI’s own models, with your key',
        docs: {
            label: 'OpenAI API keys',
            url: 'https://platform.openai.com/api-keys',
        },
    },
    groq: {
        summary: 'Fast open models, with your key',
        docs: { label: 'Groq keys', url: 'https://console.groq.com/keys' },
    },
    gemini: {
        summary: 'Google’s Gemini, with your key',
        docs: {
            label: 'Google AI Studio keys',
            url: 'https://aistudio.google.com/apikey',
        },
    },
    custom: { summary: 'Any host that speaks the OpenAI chat API' },
    mcp: {
        summary: 'Tools from an MCP server, for agents',
        docs: { label: 'What MCP is', url: 'https://modelcontextprotocol.io' },
    },
    telegram: {
        summary: 'A bot made with @BotFather',
        docs: { label: 'Open @BotFather', url: 'https://t.me/BotFather' },
    },
    line: {
        summary: 'An official account’s channel',
        docs: {
            label: 'LINE Developers console',
            url: 'https://developers.line.biz/console/',
        },
    },
    whatsapp: { summary: 'A business number, by Meta', docs: META_APPS },
    channel: { summary: 'Any chat platform with an API of its own' },
    slack: {
        summary: 'Posts to one channel',
        docs: { label: 'Slack apps', url: 'https://api.slack.com/apps' },
    },
    discord: {
        summary: 'Posts to one channel',
        docs: {
            label: 'Discord webhooks',
            url: 'https://support.discord.com/hc/en-us/articles/228383668',
        },
    },
    smtp: { summary: 'Sends email through your mail server' },
    linkedin: {
        summary: 'Posts as a LinkedIn member',
        docs: {
            label: 'LinkedIn developer apps',
            url: 'https://www.linkedin.com/developers/apps',
        },
    },
    facebook: { summary: 'Posts as a Facebook Page', docs: META_APPS },
    instagram: { summary: 'Posts as a professional account', docs: META_APPS },
    threads: { summary: 'Posts as a Threads profile', docs: META_APPS },
    serpapi: {
        summary: 'Google and other engines, as data',
        docs: {
            label: 'SerpApi key',
            url: 'https://serpapi.com/manage-api-key',
        },
    },
    tavily: {
        summary: 'Web search made for models to read',
        docs: { label: 'Tavily key', url: 'https://app.tavily.com' },
    },
    github: {
        summary: 'Issues, pull requests, files and Actions',
        docs: {
            label: 'GitHub tokens',
            url: 'https://github.com/settings/personal-access-tokens',
        },
    },
    stripe: {
        summary: 'Payment links, checkout, invoices and refunds',
        docs: {
            label: 'Stripe API keys',
            url: 'https://dashboard.stripe.com/apikeys',
        },
    },
    promptx: { summary: 'Makes and runs PromptX flows' },
    http_header: { summary: 'A key or token for one API' },
    webhook_secret: { summary: 'Proves a webhook is genuine' },
};

export function guideOf(name: string, service?: Service): Guide {
    return GUIDES[name] ?? { summary: service?.hint.split('. ')[0] ?? '' };
}

// The fields a form keeps folded under "More options": optional ones a
// person seldom changes. A usual model, a choice, and a local host's address
// stay in view, and so do a mail server's sign-in.
export function folded(field: Field, service: Service): boolean {
    return (
        !field.required &&
        field.type !== 'model' &&
        field.type !== 'choice' &&
        !(
            field.type === 'url' && service.url?.startsWith('http://localhost')
        ) &&
        !['username', 'password'].includes(field.name)
    );
}

export type Status = {
    kind: 'ok' | 'failing' | 'untested' | 'checking';
    text: string;
    message: string | null;
};

// How a connection stands, for its row: a test just made on the page, else
// the last one the server kept
export function statusOf(
    check: CheckResult | 'checking' | undefined,
    last: LastCheck | null,
    when: (at: string) => string,
): Status {
    if (check === 'checking') {
        return { kind: 'checking', text: 'Testing…', message: null };
    }

    // "Nothing to try yet": a sign-in still to make, or a service checked only
    // when it is used -- nothing was asked, so it is no test
    if (check && check.message.startsWith('Nothing to try')) {
        return {
            kind: 'untested',
            text: 'Not tested yet',
            message: check.message,
        };
    }

    if (check) {
        return {
            kind: check.ok ? 'ok' : 'failing',
            text: check.ok ? 'Works · just now' : 'Failing · just now',
            message: check.message,
        };
    }

    if (last) {
        return {
            kind: last.ok ? 'ok' : 'failing',
            text: `${last.ok ? 'Works' : 'Failing'} · ${when(last.at)}`,
            message: last.message,
        };
    }

    return { kind: 'untested', text: 'Not tested yet', message: null };
}

const host = (url: string | undefined) =>
    (url ?? '').replace(/^https?:\/\//, '').replace(/\/$/, '');

// One line on what a saved connection is set to
export function detailOf(
    connection: SavedConnection,
    service?: Service,
): string {
    const s = connection.settings;

    switch (service?.group) {
        case 'ai':
            if (connection.service === 'mcp') {
                return `MCP server at ${host(s.url)}`;
            }

            return [host(s.base_url), s.default_model ?? 'no usual model']
                .filter(Boolean)
                .join(' · ');
        case 'messaging':
            if (connection.service === 'telegram') {
                return s.receive === 'webhook'
                    ? 'Receives messages by webhook'
                    : 'Receives messages by asking Telegram';
            }

            if (connection.service === 'whatsapp') {
                return `Business number ${s.phone_number_id ?? ''}`;
            }

            if (connection.service === 'slack') {
                return 'Posts to a Slack channel by its webhook';
            }

            if (connection.service === 'discord') {
                return 'Posts to a Discord channel by its webhook';
            }

            if (connection.service === 'smtp') {
                return `From ${s.from ?? ''} through ${s.host ?? ''}`;
            }

            if (connection.service === 'channel') {
                return s.send_url
                    ? `${s.platform} · answers go to ${host(s.send_url)}`
                    : `${s.platform} · nowhere to send answers yet`;
            }

            return 'Messaging API channel';
        case 'social':
            return connection.sign_in?.detail ?? '';
        case 'search':
            return connection.service === 'tavily'
                ? 'Searches the web and reads pages, by its key'
                : 'Searches Google and other engines, by its key';
        default:
            if (connection.service === 'github') {
                return s.repo
                    ? `Works on ${s.repo} and others its token may`
                    : 'Works on the repositories its token may';
            }

            if (connection.service === 'stripe') {
                return 'Takes payments, by its key';
            }

            if (connection.service === 'promptx') {
                return `Signed in as ${s.login ?? ''}`;
            }

            if (connection.service === 'http_header') {
                return `${s.header_name ?? 'Authorization'} header, only for ${host(s.base_url)}`;
            }

            return 'Signing secret';
    }
}
