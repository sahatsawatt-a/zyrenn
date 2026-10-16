import { getInitials } from '@/composables/useInitials';
import { colourFor } from '@/lib/live';
import type { Message, MessageFile, Room } from './types';

// How a room's messages are laid out: in turns, under the day they were
// said, with pictures sent together drawn as one

// Only emoji, and few: drawn large, with nothing round them, as a sticker is
const EMOJI_ONLY =
    /^(?:\p{Extended_Pictographic}|\p{Emoji_Modifier}|\p{Regional_Indicator}|‍|️|\s)+$/u;
const segmenter =
    typeof Intl.Segmenter === 'function'
        ? new Intl.Segmenter(undefined, { granularity: 'grapheme' })
        : null;

export const onlyEmoji = (message: Message) => {
    const said = message.content.trim();

    return (
        said !== '' &&
        !message.emojis?.length &&
        /\p{Extended_Pictographic}|\p{Regional_Indicator}/u.test(said) &&
        EMOJI_ONLY.test(said) &&
        // Counted by what reads as one character, where the browser can say
        (segmenter
            ? [...segmenter.segment(said.replace(/\s+/g, ''))]
            : Array.from(said)
        ).length <= 3
    );
};

// Who said a message, so several from one person in a row read as one turn
const speakerOf = (message: Message) =>
    message.role === 'user'
        ? `person:${message.author?.id}`
        : message.role === 'workflow'
          ? `workflow:${message.sender?.outside ? 'outside' : 'own'}:${message.sender?.name}`
          : 'agent';

const dayOf = (message: Message) =>
    message.created_at ? new Date(message.created_at).toDateString() : '';

// A turn goes on while the same one speaks, on the same day, without a long gap
const TURN_MINUTES = 10;

const sameTurn = (a: Message | undefined, b: Message | undefined) =>
    !!a &&
    !!b &&
    speakerOf(a) === speakerOf(b) &&
    dayOf(a) === dayOf(b) &&
    Math.abs(
        new Date(b.created_at ?? 0).getTime() -
            new Date(a.created_at ?? 0).getTime(),
    ) <=
        TURN_MINUTES * 60_000;

const clock = new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: '2-digit',
});
const dayThisYear = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});
const dayAnyYear = new Intl.DateTimeFormat(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});
const whole = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'full',
    timeStyle: 'short',
});

export const timeOf = (message: Message) =>
    message.created_at ? clock.format(new Date(message.created_at)) : '';
export const whenOf = (message: Message) =>
    message.created_at ? whole.format(new Date(message.created_at)) : '';

function dayLabel(message: Message) {
    const day = new Date(message.created_at ?? Date.now());
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (day.toDateString() === today.toDateString()) {
        return 'Today';
    }

    if (day.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return (
        day.getFullYear() === today.getFullYear() ? dayThisYear : dayAnyYear
    ).format(day);
}

// Pictures sent together are drawn as one: by the same speaker, one after
// another, each within this long of the one before
const ALBUM_SECONDS = 60;

// A picture that is not a sticker
const pictureOf = (message: Message) =>
    message.file?.is_image && !message.sticker ? message.file : null;

// Whether a message is the next picture of those before it: a picture with
// nothing said, from the same speaker, straight after
const joins = (before: Message, message: Message) =>
    !!pictureOf(before) &&
    !!pictureOf(message) &&
    !message.content &&
    !message.place &&
    speakerOf(before) === speakerOf(message) &&
    dayOf(before) === dayOf(message) &&
    Math.abs(
        new Date(message.created_at ?? 0).getTime() -
            new Date(before.created_at ?? 0).getTime(),
    ) <=
        ALBUM_SECONDS * 1000;

export type Entry = {
    message: Message;
    album: MessageFile[] | null;
    day: string | null;
    first: boolean;
    last: boolean;
};

// Each message with where it stands: the first of a day, and the first and
// last of its speaker's turn -- a name goes over the first, a face and the
// time by the last. Pictures sent together stand as one, under the first
export function layOut(all: Message[]): Entry[] {
    const entries: Entry[] = [];

    for (let at = 0; at < all.length; at++) {
        const message = all[at];
        let end = at;

        while (end + 1 < all.length && joins(all[end], all[end + 1])) {
            end++;
        }

        entries.push({
            message,
            album:
                end > at
                    ? all
                          .slice(at, end + 1)
                          .map((one) => one.file as MessageFile)
                    : null,
            day:
                dayOf(all[at - 1] ?? message) !== dayOf(message) || at === 0
                    ? dayLabel(message)
                    : null,
            first: !sameTurn(all[at - 1], message),
            last: !sameTurn(all[end], all[end + 1]),
        });
        at = end;
    }

    return entries;
}

// Someone outside whose words a workflow carried in is drawn as a person;
// a workflow speaking for itself, as a workflow
export const fromOutside = (message: Message) =>
    message.role === 'workflow' && !!message.sender?.outside;

// The name over a turn: always for what a workflow wrote, and in a group
// for the others in it
export const nameOver = (message: Message, kind: Room['kind']) =>
    message.role === 'workflow'
        ? (message.sender?.name ?? 'Workflow')
        : kind === 'group' || kind === 'customer'
          ? (message.author?.name ?? '')
          : '';

// "Ada (LINE)" is Ada: what is in brackets says where from, not who
export const initialsOf = (name: string) =>
    getInitials(name.replace(/\s*\(.*\)\s*$/, '') || name);

export const colourOf = (message: Message) =>
    message.role === 'user'
        ? message.author?.id != null
            ? colourFor(message.author.id)
            : '#9ca3af'
        : colourFor(
              Array.from(message.sender?.name ?? '').reduce(
                  (sum, letter) => (sum * 31 + letter.charCodeAt(0)) | 0,
                  7,
              ),
          );

// A note among the project: drawn apart from what was said to the customer
export const NOTE =
    'border border-amber-500/40 bg-amber-100 text-amber-950 dark:bg-amber-500/15 dark:text-amber-100';
