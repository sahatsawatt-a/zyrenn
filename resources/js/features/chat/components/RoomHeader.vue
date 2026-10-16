<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Info, Link2, LogOut, Settings2, Trash2 } from '@lucide/vue';
import PresenceAvatars from '@/components/common/PresenceAvatars.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Member } from '@/lib/live';
import { update } from '@/routes/chats';
import type { Room } from '../types';

// The top of a room: its face and name -- renamed in place by whoever may
// -- what it is linked to, who else is here, and what can be done with it

const title = defineModel<string>('title', { required: true });
// What is known about the room, opened beside it
const about = defineModel<boolean>('about', { required: true });

const props = defineProps<{
    room: Room;
    people: { id: number; name: string }[];
    others: Member[];
    // Whether one's own room may have an AI agent
    agents: boolean;
    // The room's face, when it has one that loads
    face: string | null;
}>();

const emit = defineEmits<{
    view: [src: string];
    broken: [src: string];
    settings: [];
    leave: [];
    remove: [];
}>();

// The room's own title is saved as it is left
function saveTitle() {
    if (title.value !== props.room.title) {
        router.patch(
            update(props.room.ref_id).url,
            { title: title.value },
            { preserveScroll: true, only: ['room'] },
        );
    }
}
</script>

<template>
    <header class="flex items-center gap-2 border-b px-4 py-2.5">
        <button
            v-if="face"
            type="button"
            class="shrink-0 cursor-zoom-in rounded-full"
            aria-label="Open the picture"
            @click="emit('view', face)"
        >
            <img
                :src="face"
                alt=""
                class="size-8 rounded-full object-cover"
                referrerpolicy="no-referrer"
                data-chat-room-face
                @error="emit('broken', face as string)"
            />
        </button>
        <Input
            v-if="room.manage"
            v-model="title"
            class="h-9 flex-1 border-transparent bg-transparent text-base font-medium shadow-none"
            :placeholder="
                room.kind === 'group'
                    ? 'Group name'
                    : room.kind === 'customer'
                      ? 'Who it is with'
                      : 'New chat'
            "
            :maxlength="room.kind === 'personal' ? 255 : 80"
            aria-label="Chat title"
            @blur="saveTitle"
            @keydown.enter.prevent="($event.target as HTMLInputElement).blur()"
        />
        <div v-else class="min-w-0 flex-1 px-3">
            <h1 class="truncate text-base font-medium" data-chat-title>
                {{ title }}
            </h1>
            <p
                v-if="room.kind === 'group'"
                class="text-muted-foreground truncate text-xs"
            >
                {{ room.project?.name }} ·
                {{ people.map((person) => person.name).join(', ') }}
            </p>
        </div>
        <span
            v-if="room.link"
            class="flex shrink-0 items-center gap-1 rounded-full bg-sky-500/10 px-2 py-0.5 text-xs text-sky-700 dark:text-sky-400"
            :title="`What you write here can be sent on through ${room.link.service}, by a workflow`"
            data-chat-link
        >
            <Link2 class="size-3" />
            {{ room.link.service
            }}<template v-if="room.link.through">
                · {{ room.link.through }}</template
            >
        </span>
        <p
            v-if="room.manage && room.kind === 'group'"
            class="text-muted-foreground hidden max-w-48 truncate text-xs sm:block"
        >
            {{ people.length }} people
        </p>
        <Button
            variant="ghost"
            size="icon-sm"
            aria-label="About this chat"
            title="About this chat"
            :aria-expanded="about"
            data-chat-about-toggle
            @click="about = !about"
        >
            <Info />
        </Button>
        <PresenceAvatars :others="others" />
        <Button
            v-if="room.mine && agents"
            variant="ghost"
            size="sm"
            @click="emit('settings')"
        >
            <Settings2 />
            <span class="hidden sm:inline">
                {{ room.ready ? 'Agent' : 'Set up agent' }}
            </span>
        </Button>
        <Button
            v-if="room.leave"
            variant="ghost"
            size="sm"
            data-leave
            @click="emit('leave')"
        >
            <LogOut />
            <span class="hidden sm:inline">Leave</span>
        </Button>
        <Button
            v-if="room.remove"
            variant="ghost"
            size="icon-sm"
            aria-label="Delete chat"
            @click="emit('remove')"
        >
            <Trash2 />
        </Button>
    </header>
</template>
