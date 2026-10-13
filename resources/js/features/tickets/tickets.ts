import {
    Bug,
    CircleCheck,
    CircleDashed,
    CircleDot,
    CircleSlash,
    Sparkles,
} from '@lucide/vue';
import type { Component } from 'vue';

export type TicketType = 'bug' | 'feature';

export type TicketStatus = 'open' | 'in_progress' | 'done' | 'dropped';

export type TicketPriority = 'low' | 'normal' | 'high';

export type TicketPicture = { src: string; name: string };

export type TicketPerson = { id: number; name: string };

// A ticket as the page is given it (Ticket::summary)
export type Ticket = {
    ref_id: string;
    number: number;
    type: TicketType;
    status: TicketStatus;
    priority: TicketPriority;
    title: string;
    unit: string;
    body: string;
    pictures: TicketPicture[];
    created_by: TicketPerson | null;
    assigned_to: TicketPerson | null;
    // The group it is talked over in, once somebody has started it
    chat: string | null;
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
    assigned_to: number | null;
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
    assigned_to: null,
    pictures: [],
});

export const draftOf = (ticket: Ticket): TicketDraft => ({
    type: ticket.type,
    title: ticket.title,
    unit: ticket.unit,
    body: ticket.body,
    priority: ticket.priority,
    status: ticket.status,
    assigned_to: ticket.assigned_to?.id ?? null,
    pictures: ticket.pictures.map((picture) => ({ ...picture })),
});
