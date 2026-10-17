<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { getInitials } from '@/composables/useInitials';
import { colourFor } from '@/lib/live';
import { formatRelativeTime } from '@/lib/utils';
import { show } from '@/routes/chats';
import Tip from '@/components/common/Tip.vue';

export type ListedRoom = {
    ref_id: string;
    title: string;
    // The line under the title: who answers, which project, how many are in it
    detail: string;
    updated_at: string;
    unread?: number;
    // What was said last, and by whom: shown in place of the detail
    last?: string | null;
    // The service a room is linked to a chat on: "line", "telegram"
    linked?: string | null;
    // Its face, when it keeps one: who an outside chat is with
    picture?: string | null;
    // False for a group one may see but is not in: it is joined, not opened
    open?: boolean;
    // A customer's chat: what it is tagged, and who has taken it
    tags?: string[];
    // The services they write on besides this one, by name
    also?: string[];
    assignee?: { id: number; name: string } | null;
};

// A list of rooms, each opening its room -- or, for a group one is not in, offering to join it
defineProps<{ rooms: ListedRoom[]; icon: Component }>();
</script>

<template>
    <ul class="divide-y rounded-xl border">
        <li
            v-for="room in rooms"
            :key="room.ref_id"
            class="flex items-center"
            data-room
        >
            <component
                :is="room.open === false ? 'div' : Link"
                :href="room.open === false ? undefined : show(room.ref_id)"
                class="flex min-w-0 flex-1 items-center gap-3 px-4 py-3 transition-colors"
                :class="{ 'hover:bg-accent/50': room.open !== false }"
            >
                <img
                    v-if="room.picture"
                    :src="room.picture"
                    alt=""
                    class="-mx-2 size-8 shrink-0 rounded-full object-cover"
                    referrerpolicy="no-referrer"
                    loading="lazy"
                    data-room-face
                />
                <component
                    :is="icon"
                    v-else
                    class="text-muted-foreground size-4 shrink-0"
                />
                <div class="min-w-0 flex-1">
                    <p class="flex min-w-0 items-center gap-2">
                        <span
                            class="truncate"
                            :class="
                                room.unread ? 'font-semibold' : 'font-medium'
                            "
                            >{{ room.title || 'New chat' }}</span
                        >
                        <span
                            v-if="room.linked"
                            class="shrink-0 rounded-full bg-sky-500/10 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 uppercase dark:text-sky-400"
                            data-room-linked
                            >{{ room.linked }}</span
                        >
                        <Tip
                            v-for="service in room.also ?? []"
                            :key="service"
                            :text="`Also writes on ${service}`"
                        >
                            <span
                                class="hidden shrink-0 rounded-full border border-sky-500/30 px-1.5 py-0.5 text-[10px] text-sky-700 sm:inline dark:text-sky-400"
                                data-room-also
                                >+ {{ service }}</span
                            >
                        </Tip>
                        <span
                            v-for="tag in (room.tags ?? []).slice(0, 3)"
                            :key="tag"
                            class="bg-muted text-muted-foreground hidden shrink-0 rounded-full px-1.5 py-0.5 text-[10px] sm:inline"
                            data-room-tag
                            >{{ tag }}</span
                        >
                    </p>
                    <p
                        class="truncate text-xs"
                        :class="
                            room.unread
                                ? 'text-foreground'
                                : 'text-muted-foreground'
                        "
                        data-room-last
                    >
                        {{ room.last || room.detail }}
                    </p>
                </div>
                <span
                    v-if="room.unread"
                    class="bg-primary text-primary-foreground rounded-full px-2 py-0.5 text-xs font-semibold"
                    :aria-label="`${room.unread} unread`"
                    data-unread
                >
                    {{ room.unread > 99 ? '99+' : room.unread }}
                </span>
                <Tip
                    v-if="room.assignee"
                    :text="`Assigned to ${room.assignee.name}`"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold text-white"
                        :style="{
                            backgroundColor: colourFor(room.assignee.id),
                        }"
                        data-room-assignee
                    >
                        {{ getInitials(room.assignee.name) }}
                    </span>
                </Tip>
                <span class="text-muted-foreground shrink-0 text-xs">
                    {{ formatRelativeTime(room.updated_at) }}
                </span>
            </component>
            <div v-if="$slots.action" class="pr-3">
                <slot name="action" :room="room" />
            </div>
        </li>
    </ul>
</template>
