<script setup lang="ts">
import { computed } from 'vue';
import type { Item } from '@/features/boards/model/items';
import CommentPin from './CommentPin.vue';
import { onTheBoard, usePrototypeComments } from './feedback';
import type { PlayMiss } from './feedback';

// Over a prototype's screens on its board, placed as the camera has it: the
// comments left on each, as pins; and while the Plays panel is open, how
// often each screen was seen, and a dot for each tap there that found
// nothing to follow -- of the play picked, or of every play.
const props = defineProps<{
    board: string;
    // Its screens: the frames on it
    frames: Item[];
    scale: number;
    position: { x: number; y: number };
    // Owners and editors: they resolve any comment and make tickets of them
    canChange: boolean;
}>();

const { comments } = usePrototypeComments(props.board);

const byId = computed(() => new Map(props.frames.map((f) => [f.id, f])));

/** A spot on a screen (0 to 1 across and down) where it is on the page. */
const placed = (screen: string, x: number, y: number) => {
    const frame = byId.value.get(screen);

    return frame
        ? {
              left: `${(frame.x + x * frame.width) * props.scale + props.position.x}px`,
              top: `${(frame.y + y * frame.height) * props.scale + props.position.y}px`,
          }
        : null;
};

const pins = computed(() => {
    if (onTheBoard.pins === 'none') {
        return [];
    }

    const shown = comments.value.filter(
        (comment) =>
            byId.value.has(comment.screen) &&
            (onTheBoard.pins === 'all' || !comment.resolved),
    );
    const counted = new Map<string, number>();

    return shown.map((comment) => {
        const number = (counted.get(comment.screen) ?? 0) + 1;
        counted.set(comment.screen, number);

        return {
            comment,
            number,
            at: placed(comment.screen, comment.x, comment.y)!,
        };
    });
});

// ------------------------------------------------------------------ Plays
const plays = computed(() =>
    !onTheBoard.plays
        ? []
        : onTheBoard.play
          ? onTheBoard.plays.filter((play) => play.ref_id === onTheBoard.play)
          : onTheBoard.plays,
);

/** How many times each screen was gone to, in the plays shown. */
const visits = computed(() => {
    const seen = new Map<string, number>();

    for (const play of plays.value) {
        for (const step of play.path) {
            seen.set(step.screen, (seen.get(step.screen) ?? 0) + 1);
        }
    }

    return props.frames
        .filter((frame) => seen.has(frame.id))
        .map((frame) => ({
            id: frame.id,
            count: seen.get(frame.id)!,
            at: placed(frame.id, 1, 0)!,
        }));
});

const misses = computed(() =>
    plays.value
        .flatMap((play) => play.misses)
        .filter((miss: PlayMiss) => byId.value.has(miss.screen))
        .map((miss, index) => ({
            key: index,
            at: placed(miss.screen, miss.x, miss.y)!,
        })),
);
</script>

<template>
    <div
        class="pointer-events-none absolute inset-0 z-10 overflow-hidden"
        data-test="screen-feedback"
    >
        <span
            v-for="miss in misses"
            :key="miss.key"
            class="screen-miss"
            :style="miss.at"
            data-test="play-miss"
        />
        <span
            v-for="screen in visits"
            :key="screen.id"
            class="screen-visits"
            :style="screen.at"
            :data-screen="screen.id"
            data-test="screen-visits"
        >
            {{ screen.count }} {{ screen.count === 1 ? 'visit' : 'visits' }}
        </span>
        <div
            v-for="pin in pins"
            :key="pin.comment.ref_id"
            class="absolute h-0 w-0"
            :style="pin.at"
        >
            <CommentPin
                :board="board"
                :comment="pin.comment"
                :number="pin.number"
                :can-resolve="canChange"
                :can-ticket="canChange"
            />
        </div>
    </div>
</template>

<style scoped>
.screen-miss {
    position: absolute;
    width: 14px;
    height: 14px;
    margin: -7px 0 0 -7px;
    background: rgb(239 68 68 / 0.45);
    border: 1.5px solid rgb(220 38 38 / 0.9);
    border-radius: 50%;
}
.screen-visits {
    position: absolute;
    transform: translate(-100%, calc(-100% - 6px));
    padding: 1px 8px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    color: white;
    background: #6366f1;
    border-radius: 999px;
}
</style>
