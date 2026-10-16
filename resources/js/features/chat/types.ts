import type { JSONContent } from '@tiptap/vue-3';
import type { AnswerSource } from './components/AnswerSources.vue';
import type { Desk, DeskContact, DeskPerson } from './desk';

// A chat room and what is said in it, as the room's page is given them

// A place sent in a chat, shown on a small map under the words
export type ChatPlace = {
    lat: number;
    lng: number;
    title: string | null;
    address: string | null;
};

export type Message = {
    id: number;
    role: 'user' | 'assistant' | 'workflow';
    content: string;
    created_at: string | null;
    // An agent's words as the note editor's document, so they are drawn as a note is
    doc: JSONContent | null;
    // Why an answer stopped short, when it did
    error: string | null;
    // Who said it, when a person did; id is null for someone since gone
    author: { id: number | null; name: string } | null;
    // A file a workflow put under its words, from the Drive
    file?: MessageFile | null;
    // A place shown on a small map under the words
    place?: ChatPlace | null;
    // Drawn as a sticker is: small, with nothing round it
    sticker?: boolean;
    // Pictures that stand for some of the words: an outside chat's own emoji
    emojis?: { index: number; length: number; url: string }[];
    // A note among the project, in a customer's chat: never sent out
    internal?: boolean;
    // What an agent answered from, when it is an agent's answer
    sources?: AnswerSource[] | null;
    // A workflow's: under its name, or the outside sender's it carried over
    sender?: {
        name: string;
        outside: boolean;
        // Their face: a picture's address, when a workflow looked one up
        picture?: string | null;
        by: string | null;
        workflow: string | null;
    } | null;
};

export type MessageFile = {
    ref_id: string;
    name: string;
    url: string;
    is_image: boolean;
    is_video: boolean;
};

export type Room = {
    ref_id: string;
    // personal: one's own, maybe with an agent; direct: two people; group: a project's;
    // customer: a project's chat with someone outside, in its inbox
    kind: 'personal' | 'direct' | 'group' | 'customer';
    title: string;
    // One's own room, whose agent can be set up
    mine: boolean;
    // May rename and delete it
    manage: boolean;
    // May leave it: a group one is in
    leave: boolean;
    // May delete it, and may write in it
    remove: boolean;
    post: boolean;
    // A customer's chat: where it stands, who has taken it, its tags, and who it can be given to
    desk:
        | (Desk & {
              people: DeskPerson[];
              // Until when its service takes an answer; null where it sets no such time
              answer_until: string | null;
              // The parts of the product a ticket made here can be about
              parts?: {
                  ref_id: string;
                  code: string;
                  name: string;
                  kind: string;
              }[];
          })
        | null;
    // Who a customer's chat is with, on every service
    contact: DeskContact | null;
    project: { ref_id: string; name: string } | null;
    model: string | null;
    system_prompt: string | null;
    connection: string | null;
    ready: boolean;
    // Linked to a chat outside: a workflow may send what is written here there
    link: { service: string; through: string | null } | null;
    // Its face, and what is known about it by name: kept by a workflow, or noted by hand
    picture?: string | null;
    details?: Record<string, string>;
    // The names among the details a workflow keeps, which are not changed by hand
    kept?: string[];
    // How many messages it holds in all
    messages?: number;
    created_at?: string | null;
};

// An AI connection a room's agent can answer through
export type Connection = {
    ref_id: string;
    name: string;
    kind: string;
    label: string;
    default_model: string | null;
};
