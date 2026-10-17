<script setup lang="ts">
import { computed } from 'vue';
import { Circle, Group, Path } from 'vue-konva';
import type { Action, Trigger } from '@/features/prototypes/interactions';
import { boundsOf } from '../model/geometry';
import type { Item } from '../model/items';
import { screenOf, screensNamed, triggersOf } from '../model/links';

// In a prototype's editor: a small badge at the corner of everything that
// does something in Play, one for each way it is set off -- a tap, a long
// press, hover, a swipe -- and a clock on a screen that moves on by itself.
// The same size however far it is zoomed; only in the editor: a picture of
// the board, or Play, has none.
const props = defineProps<{
    items: Item[];
    byId: Map<string, Item>;
    scale: number;
}>();

// Lucide's icons, in their 24 by 24 box
const ICONS = {
    link: 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71 M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71',
    back: 'M9 14 4 9l5-5 M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5 5.5 5.5 0 0 1-5.5 5.5H11',
    zap: 'M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z',
    'long-press': 'M10 2h4 M12 14l3-3 M12 22a8 8 0 1 0 0-16 8 8 0 0 0 0 16z',
    hover: 'M12.586 12.586 19 19 M3.688 3.037a.497.497 0 0 0-.651.651l6.5 15.999a.501.501 0 0 0 .947-.062l1.569-6.083a2 2 0 0 1 1.448-1.479l6.124-1.579a.5.5 0 0 0 .063-.947z',
    'swipe-left': 'm12 19-7-7 7-7 M19 12H5',
    'swipe-right': 'M5 12h14 m12 5 7 7-7 7',
    'swipe-up': 'm5 12 7-7 7 7 M12 19V5',
    'swipe-down': 'M12 5v14 m19 12-7 7-7-7',
    after: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z M12 6v6l4 2',
};

const RADIUS = 9;
// From the corner leftwards, a badge's width apart
const STEP = 20;

/** A tap's icon says what it does: go somewhere, go back, or else. */
const tapIcon = (action: Action) =>
    'back' in action
        ? ICONS.back
        : screensNamed(action).length
          ? ICONS.link
          : ICONS.zap;

const badges = computed(() =>
    props.items.flatMap((item) => {
        if (item.hidden) {
            return [];
        }

        const box = boundsOf(item);
        const icons =
            item.kind === 'frame'
                ? screenOf(item).after
                    ? [ICONS.after]
                    : []
                : triggersOf(item)
                      // What goes to a screen no longer there goes nowhere
                      .filter(
                          ({ action }) =>
                              !('to' in action) ||
                              props.byId.get(action.to)?.kind === 'frame',
                      )
                      .map(({ trigger, action }) =>
                          trigger === 'tap'
                              ? tapIcon(action)
                              : ICONS[trigger as Trigger],
                      );

        return icons.map((icon, at) => ({
            key: `${item.id}-${at}`,
            x: box.x + box.width,
            y: box.y,
            at,
            icon,
        }));
    }),
);

const size = computed(() => 1 / Math.max(0.05, props.scale));
</script>

<template>
    <Group :config="{ listening: false }">
        <Group
            v-for="badge in badges"
            :key="badge.key"
            :config="{
                x: badge.x - badge.at * STEP * size,
                y: badge.y,
                scaleX: size,
                scaleY: size,
                name: 'link-badge',
            }"
        >
            <Circle
                :config="{
                    radius: RADIUS,
                    fill: '#4f46e5',
                    stroke: '#ffffff',
                    strokeWidth: 1.5,
                    shadowColor: 'rgba(15,23,42,0.35)',
                    shadowBlur: 3,
                    shadowOffsetY: 1,
                }"
            />
            <Path
                :config="{
                    data: badge.icon,
                    x: -6,
                    y: -6,
                    scaleX: 0.5,
                    scaleY: 0.5,
                    stroke: '#ffffff',
                    strokeWidth: 2.4,
                    lineCap: 'round',
                    lineJoin: 'round',
                }"
            />
        </Group>
    </Group>
</template>
