import {
    Bug,
    CircleCheck,
    CircleDashed,
    CircleDot,
    CirclePause,
    CircleSlash,
    Sparkles,
} from '@lucide/vue';
import type { Component } from 'vue';

export type TicketType = 'bug' | 'feature';

export type TicketStatus =
    | 'open'
    | 'in_progress'
    | 'on_hold'
    | 'done'
    | 'dropped';

export type TicketPriority = 'low' | 'normal' | 'high';

// A picture or a video: older ones, kept before videos were, say nothing and are pictures
export type TicketPicture = {
    src: string;
    name: string;
    kind?: 'image' | 'video';
};

/** How to make a bug happen again. */
export type TicketRepro = {
    steps: string[];
    expected: string;
    happens: string;
};

/** A run of a bug's browser check: passed, it no longer happens. */
export type TicketReplayRun = {
    ref_id: string;
    state: 'waiting' | 'running' | 'passed' | 'failed' | 'stopped';
    // Whether the check passed, once the run has ended
    ok: boolean | null;
    verdict: string | null;
    video: { url: string; name: string } | null;
    at: string;
    // The run as JSON, followed while it goes; and on the checks page
    url: string;
    page: string;
};

/** The browser check that makes a bug happen (Replay::described). */
export type TicketCheck =
    | { gone: true; suite: string | null; check: string | null }
    | {
          gone: false;
          suite: string;
          suite_title: string;
          check: string;
          title: string;
          steps: number;
          url: string;
          run: TicketReplayRun | null;
      };

/** A suite of the project's browser checks, offered to link one from. */
export type TicketSuite = {
    ref_id: string;
    title: string;
    checks: { id: string; title: string }[];
};

export type TicketPerson = { id: number; name: string };

// A ticket as the page is given it (Ticket::summary)
export type Ticket = {
    ref_id: string;
    number: number;
    type: TicketType;
    status: TicketStatus;
    // What it waits on, or why it won't be done
    reason: string | null;
    priority: TicketPriority;
    title: string;
    unit: string;
    body: string;
    repro: TicketRepro | null;
    check: TicketCheck | null;
    pictures: TicketPicture[];
    created_by: TicketPerson | null;
    assigned_to: TicketPerson | null;
    // The group it is talked over in, once somebody has started it
    chat: string | null;
    // The customer's chat it was asked in, when it was made from one
    asked_in?: { ref_id: string; title: string } | null;
    created_at: string;
    updated_at: string;
    closed_at: string | null;
};

/** What the form holds: a new ticket, or the changes to one. */
export type TicketDraft = {
    type: TicketType;
    title: string;
    unit: string;
    body: string;
    priority: TicketPriority;
    status: TicketStatus;
    reason: string;
    assigned_to: number | null;
    repro: TicketRepro;
    // The suite and check that makes it happen; null for none
    check: { suite: string; check: string } | null;
    pictures: TicketPicture[];
};

export const TYPES: Record<
    TicketType,
    { label: string; icon: Component; tone: string; asks: string; hint: string }
> = {
    bug: {
        label: 'Bug',
        icon: Bug,
        tone: 'text-rose-600 dark:text-rose-400',
        asks: 'What goes wrong',
        hint: 'What you did, what happened, and what you expected instead.',
    },
    feature: {
        label: 'Feature',
        icon: Sparkles,
        tone: 'text-indigo-600 dark:text-indigo-400',
        asks: 'What is needed',
        hint: 'What it should do, and for whom.',
    },
};

// In the order a ticket moves through them
export const STATUSES: {
    value: TicketStatus;
    label: string;
    icon: Component;
    tone: string;
}[] = [
    {
        value: 'open',
        label: 'Open',
        icon: CircleDashed,
        tone: 'text-muted-foreground',
    },
    {
        value: 'in_progress',
        label: 'In progress',
        icon: CircleDot,
        tone: 'text-amber-600 dark:text-amber-400',
    },
    // To be done, but waiting on something first
    {
        value: 'on_hold',
        label: 'On hold',
        icon: CirclePause,
        tone: 'text-sky-600 dark:text-sky-400',
    },
    {
        value: 'done',
        label: 'Done',
        icon: CircleCheck,
        tone: 'text-emerald-600 dark:text-emerald-400',
    },
    {
        value: 'dropped',
        label: 'Won’t do',
        icon: CircleSlash,
        tone: 'text-muted-foreground',
    },
];

// The statuses a ticket is only put in with a word on why (Ticket::NEEDS_REASON)
export const REASONS: Partial<
    Record<TicketStatus, { asks: string; hint: string }>
> = {
    on_hold: {
        asks: 'What it waits on',
        hint: 'e.g. Enough runs to go by, or #12 first',
    },
    dropped: {
        asks: 'Why it won’t be done',
        hint: 'e.g. Covered by #8, or not worth what it costs',
    },
};

export const statusOf = (value: TicketStatus) =>
    STATUSES.find((status) => status.value === value) ?? STATUSES[0];

export const PRIORITIES: { value: TicketPriority; label: string }[] = [
    { value: 'low', label: 'Low' },
    { value: 'normal', label: 'Normal' },
    { value: 'high', label: 'High' },
];

/** The parts of this app, offered before a project has units of its own. */
export const UNITS = [
    'Notes',
    'Boards',
    'Tables',
    'Maps',
    'Drive',
    'Workflows',
    'Chat',
];

export const blankDraft = (type: TicketType = 'bug'): TicketDraft => ({
    type,
    title: '',
    unit: '',
    body: '',
    priority: 'normal',
    status: 'open',
    reason: '',
    assigned_to: null,
    repro: { steps: [], expected: '', happens: '' },
    check: null,
    pictures: [],
});

export const draftOf = (ticket: Ticket): TicketDraft => ({
    type: ticket.type,
    title: ticket.title,
    unit: ticket.unit,
    body: ticket.body,
    priority: ticket.priority,
    status: ticket.status,
    reason: ticket.reason ?? '',
    assigned_to: ticket.assigned_to?.id ?? null,
    repro: {
        steps: [...(ticket.repro?.steps ?? [])],
        expected: ticket.repro?.expected ?? '',
        happens: ticket.repro?.happens ?? '',
    },
    check:
        ticket.check && !ticket.check.gone
            ? { suite: ticket.check.suite, check: ticket.check.check }
            : null,
    pictures: ticket.pictures.map((picture) => ({ ...picture })),
});

/** Whether a bug says anything of how to make it happen again. */
export const saysRepro = (repro: TicketRepro | null | undefined) =>
    !!repro &&
    (repro.steps.some((step) => step.trim()) ||
        !!repro.expected.trim() ||
        !!repro.happens.trim());

export const isVideo = (picture: TicketPicture) => picture.kind === 'video';
