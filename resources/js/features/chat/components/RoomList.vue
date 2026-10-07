<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { formatRelativeTime } from '@/lib/utils';
import { show } from '@/routes/chats';

export type ListedRoom = {
    ref_id: string;
    title: string;
    // The line under the title: who answers, which project, how many are in it
    detail: string;
    updated_at: string;
    unread?: number;
    // False for a group one may see but is not in: it is joined, not opened
    open?: boolean;
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
                <component
                    :is="icon"
                    class="text-muted-foreground size-4 shrink-0"
                />
                <div class="min-w-0 flex-1">
                    <p
                        class="truncate"
                        :class="room.unread ? 'font-semibold' : 'font-medium'"
                    >
                        {{ room.title || 'New chat' }}
                    </p>
                    <p class="text-muted-foreground truncate text-xs">
                        {{ room.detail }}
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
