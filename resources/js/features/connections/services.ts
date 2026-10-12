import {
    AtSign,
    Briefcase,
    Camera,
    Cpu,
    Flag,
    KeyRound,
    MessageCircle,
    Phone,
    Radar,
    Search,
    Send,
    Server,
    ShieldCheck,
    Sparkles,
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
};

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
        case 'serpapi':
            return { icon: Search, tone: TONES.cyan };
        case 'tavily':
            return { icon: Radar, tone: TONES.rose };
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

            return 'Messaging API channel';
        case 'social':
            return connection.sign_in?.detail ?? '';
        case 'search':
            return connection.service === 'tavily'
                ? 'Searches the web and reads pages, by its key'
                : 'Searches Google and other engines, by its key';
        default:
            if (connection.service === 'http_header') {
                return `${s.header_name ?? 'Authorization'} header, only for ${host(s.base_url)}`;
            }

            return 'Signing secret';
    }
}
