<script setup lang="ts">
import { Zap } from '@lucide/vue';
import { colourOf, fromOutside, initialsOf, nameOver } from '../conversation';
import type { Message, Room } from '../types';
import Tip from '@/components/common/Tip.vue';

// Beside someone else's turn: a workflow's mark, the face of someone
// outside whose words a workflow carried in, or a person's initials. Only
// the turn's last message shows it; the others keep its room.

defineProps<{
    message: Message;
    kind: Room['kind'];
    // Their picture, when there is one that loads
    face: string | null;
    last: boolean;
}>();

const emit = defineEmits<{
    // Looked at larger
    view: [src: string];
    // Would not load
    broken: [src: string];
}>();
</script>

<template>
    <Tip
        v-if="message.role === 'workflow' && !fromOutside(message)"
        :text="`${nameOver(message, kind)}, a workflow`"
    >
        <span
            class="mb-4 flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-amber-700 dark:text-amber-400"
            :class="{ invisible: !last }"
            :aria-label="`${nameOver(message, kind)}, a workflow`"
        >
            <Zap class="size-3.5" />
        </span>
    </Tip>
    <Tip
        v-else-if="face"
        :text="`${message.sender?.name}: written outside, carried in by a workflow`"
    >
        <img
            :src="face"
            :alt="message.sender?.name"
            class="mb-4 size-7 shrink-0 cursor-zoom-in rounded-full object-cover"
            :class="{ invisible: !last }"
            referrerpolicy="no-referrer"
            loading="lazy"
            data-chat-face
            @error="emit('broken', face as string)"
            @click="emit('view', face as string)"
        />
    </Tip>
    <Tip
        v-else
        :text="
            message.role === 'user'
                ? message.author?.name
                : `${message.sender?.name}: written outside, carried in by a workflow`
        "
    >
        <span
            class="mb-4 flex size-7 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white"
            :class="{ invisible: !last }"
            :style="{ backgroundColor: colourOf(message) }"
        >
            {{
                initialsOf(message.author?.name ?? message.sender?.name ?? '?')
            }}
        </span>
    </Tip>
</template>
