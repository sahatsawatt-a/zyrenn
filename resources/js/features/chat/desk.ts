import { CircleCheck, CircleDot, Hourglass } from '@lucide/vue';
import type { Component } from 'vue';

// Where a project's chat with a customer stands (ChatRoom::STATUSES)
export type DeskStatus = 'open' | 'waiting' | 'closed';

export type DeskPerson = { id: number; name: string };

// What a customer's chat says of itself (ChatRoom::desk)
export type Desk = {
    status: DeskStatus;
    assignee: DeskPerson | null;
    tags: string[];
    // Whether AI answers them here, while nobody has taken the chat
    ai: boolean;
    // The project's agents, any of which can suggest a reply
    agents?: { ref_id: string; name: string }[];
    // Tickets made of what they asked
    tickets?: {
        number: number;
        type: 'bug' | 'feature';
        status: string;
        title: string;
        url: string;
    }[];
};

export const STATUSES: Record<
    DeskStatus,
    { label: string; hint: string; icon: Component; tone: string }
> = {
    open: {
        label: 'Open',
        hint: 'To be answered',
        icon: CircleDot,
        tone: 'text-emerald-600 dark:text-emerald-400',
    },
    waiting: {
        label: 'Waiting',
        hint: 'Answered: waiting on them',
        icon: Hourglass,
        tone: 'text-amber-600 dark:text-amber-400',
    },
    closed: {
        label: 'Closed',
        hint: 'Done with, until they write again',
        icon: CircleCheck,
        tone: 'text-muted-foreground',
    },
};

// The services a room can be linked to a chat on, as they write their names (ChatRoom::LINKS)
export const SERVICES: Record<string, string> = {
    telegram: 'Telegram',
    line: 'LINE',
    whatsapp: 'WhatsApp',
    instagram: 'Instagram',
    channel: 'Custom channel',
};

// Who a customer's chat is with, on every service (Contact::card)
export type DeskContact = {
    ref_id: string;
    name: string;
    // What a workflow joins their chats by: an email, a phone number
    key: string | null;
    picture: string | null;
    details: Record<string, string>;
    rooms: {
        ref_id: string;
        title: string;
        service: string | null;
        status: DeskStatus | null;
    }[];
};
