<script setup lang="ts">
import { Quote, StickyNote, Ticket as TicketIcon } from '@lucide/vue';
import TiptapEditor from '@/features/notes/components/TiptapEditor.vue';
import { NOTE, fromOutside, nameOver, timeOf, whenOf } from '../conversation';
import type { Entry } from '../conversation';
import type { Message, Room } from '../types';
import AnswerSources from './AnswerSources.vue';
import MessageBody from './MessageBody.vue';
import MessageFace from './MessageFace.vue';
import Tip from '@/components/common/Tip.vue';

// One message in the conversation, where it stands in its speaker's turn:
// one's own on the right; someone else's -- a person here, someone outside
// whose words a workflow carried in, or a workflow speaking for itself --
// on the left by their face; an agent's answer across the whole width.

defineProps<
    Omit<Entry, 'day'> & {
        // Written by whoever is looking
        mine: boolean;
        kind: Room['kind'];
        // Their face, beside what someone outside wrote
        face: string | null;
        // May make a ticket or a request of what a customer asked
        canFile: boolean;
    }
>();

const emit = defineEmits<{
    view: [src: string];
    broken: [src: string];
    promote: [message: Message];
    request: [message: Message];
}>();
</script>

<template>
    <div
        v-if="mine"
        class="flex flex-col items-end"
        :class="first ? 'mt-3' : 'mt-0.5'"
        data-chat-user
        data-chat-mine
    >
        <div class="flex max-w-[85%] min-w-0 flex-col items-end sm:max-w-[70%]">
            <p
                v-if="message.internal && first"
                class="mb-0.5 flex items-center gap-1 px-1 text-[11px] text-amber-700 dark:text-amber-400"
                data-chat-note
            >
                <StickyNote class="size-3" /> Note · not sent to them
            </p>
            <MessageBody
                :message="message"
                :album="album"
                :bubble="[
                    message.internal
                        ? NOTE
                        : 'bg-primary text-primary-foreground',
                    { 'rounded-br-md': last },
                ]"
            />
        </div>
        <Tip v-if="last && message.created_at" :text="whenOf(message)">
            <time
                class="text-muted-foreground mt-0.5 px-1 text-[11px] tabular-nums"
                :datetime="message.created_at"
                data-chat-time
            >
                {{ timeOf(message) }}
            </time>
        </Tip>
    </div>

    <div
        v-else-if="message.role !== 'assistant'"
        class="group flex items-end gap-2"
        :class="first ? 'mt-3' : 'mt-0.5'"
        :data-chat-user="message.role === 'user' ? '' : undefined"
        :data-chat-theirs="message.role === 'user' ? '' : undefined"
        :data-chat-workflow="message.role === 'workflow' ? '' : undefined"
    >
        <MessageFace
            :message="message"
            :kind="kind"
            :face="face"
            :last="last"
            @view="emit('view', $event)"
            @broken="emit('broken', $event)"
        />
        <div class="max-w-[85%] min-w-0 sm:max-w-[70%]">
            <p
                v-if="first && nameOver(message, kind)"
                class="text-muted-foreground mb-0.5 px-1 text-xs"
                data-chat-author
            >
                {{ nameOver(message, kind)
                }}<template v-if="message.internal">
                    · note, not sent to them</template
                ><template
                    v-if="
                        message.role === 'workflow' &&
                        !fromOutside(message) &&
                        message.sender?.by
                    "
                >
                    · for {{ message.sender.by }}</template
                >
            </p>
            <MessageBody
                :message="message"
                :album="album"
                :bubble="[
                    'w-fit',
                    message.internal
                        ? NOTE
                        : message.role === 'workflow' && !fromOutside(message)
                          ? 'border border-amber-500/20 bg-amber-500/5'
                          : 'bg-muted',
                    { 'rounded-bl-md': last },
                ]"
            />
            <!-- An agent's answer: what it answered from, for the project to check it by -->
            <AnswerSources
                v-if="message.sources"
                :sources="message.sources"
                class="mt-1 px-1"
            />
            <Tip v-if="last && message.created_at" :text="whenOf(message)">
                <time
                    class="text-muted-foreground mt-0.5 block h-4 px-1 text-[11px] tabular-nums"
                    :datetime="message.created_at"
                    data-chat-time
                >
                    {{ timeOf(message) }}
                </time>
            </Tip>
        </div>
        <template v-if="canFile && fromOutside(message)">
            <Tip text="Make a ticket of this">
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground mb-5 shrink-0 rounded-md p-1 opacity-50 focus:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                    aria-label="Make a ticket of this"
                    data-test="chat-promote"
                    @click="emit('promote', message)"
                >
                    <TicketIcon class="size-4" />
                </button>
            </Tip>
            <Tip text="Keep as a request of the product">
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground mb-5 shrink-0 rounded-md p-1 opacity-50 focus:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                    aria-label="Keep as a request of the product"
                    data-test="chat-request"
                    @click="emit('request', message)"
                >
                    <Quote class="size-4" />
                </button>
            </Tip>
        </template>
    </div>

    <div
        v-else
        class="max-w-full"
        :class="first ? 'mt-3' : 'mt-1'"
        data-chat-assistant
    >
        <!-- The same editor and Markdown as a note, only for reading -->
        <TiptapEditor
            :content="message.doc"
            :editable="false"
            wide
            class="chat-answer min-h-0! pt-0!"
        />
        <p v-if="message.error" class="text-destructive mt-1 text-xs">
            Stopped early: {{ message.error }}
        </p>
    </div>
</template>
