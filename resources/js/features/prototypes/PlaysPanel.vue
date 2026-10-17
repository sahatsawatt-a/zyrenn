<script setup lang="ts">
import { computed } from 'vue';
import type { Item } from '@/features/boards/model/items';
import { onTheBoard } from './feedback';
import type { PrototypePlay } from './feedback';

// Who played the prototype, and what they saw: each play -- who, when, for
// how long, the screens in the order they went through them, and how many
// taps found nothing. Picking one shows its taps on the screens; picking it
// again shows every play's.
const props = defineProps<{
    plays: PrototypePlay[] | null;
    frames: Item[];
}>();

const titleOf = (screen: string) =>
    props.frames.find((frame) => frame.id === screen)?.text.trim() ||
    'A screen gone since';

const when = (at: string) =>
    new Date(at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

const long = (seconds: number) =>
    seconds < 60
        ? `${Math.round(seconds)} s`
        : `${Math.floor(seconds / 60)} min ${Math.round(seconds % 60)} s`;

const pick = (play: string) =>
    (onTheBoard.play = onTheBoard.play === play ? null : play);

const totalMisses = computed(
    () => props.plays?.reduce((sum, play) => sum + play.misses.length, 0) ?? 0,
);
</script>

<template>
    <div class="flex max-h-[min(32rem,70vh)] flex-col" data-test="plays-panel">
        <div class="border-b px-3 py-2">
            <p class="text-sm font-medium">Plays</p>
            <p class="text-muted-foreground text-xs">
                <template v-if="plays === null">Loading…</template>
                <template v-else-if="!plays.length">
                    No one has played it yet.
                </template>
                <template v-else>
                    {{ plays.length }}
                    {{ plays.length === 1 ? 'play' : 'plays' }} ·
                    {{ totalMisses }} missed
                    {{ totalMisses === 1 ? 'tap' : 'taps' }} · shown on the
                    screens
                </template>
            </p>
        </div>
        <ul class="min-h-0 flex-1 overflow-y-auto p-1">
            <li v-for="play in plays ?? []" :key="play.ref_id">
                <button
                    type="button"
                    class="hover:bg-accent w-full rounded-md px-2 py-2 text-left"
                    :class="{ 'bg-accent': onTheBoard.play === play.ref_id }"
                    :aria-pressed="onTheBoard.play === play.ref_id"
                    data-test="play-row"
                    @click="pick(play.ref_id)"
                >
                    <span class="flex items-baseline justify-between gap-2">
                        <span class="truncate text-sm font-medium">{{
                            play.by?.name ?? 'Someone'
                        }}</span>
                        <span
                            class="text-muted-foreground shrink-0 text-xs tabular-nums"
                            >{{ long(play.seconds) }}</span
                        >
                    </span>
                    <span class="text-muted-foreground block text-xs">
                        {{ when(play.started_at) }}
                        <template v-if="play.misses.length">
                            · {{ play.misses.length }} missed
                            {{ play.misses.length === 1 ? 'tap' : 'taps' }}
                        </template>
                    </span>
                    <span
                        class="mt-1.5 flex flex-wrap gap-1"
                        data-test="play-path"
                    >
                        <span
                            v-for="(step, index) in play.path"
                            :key="index"
                            class="bg-muted rounded px-1.5 py-0.5 text-[11px]"
                            >{{ titleOf(step.screen) }}</span
                        >
                    </span>
                </button>
            </li>
        </ul>
    </div>
</template>
