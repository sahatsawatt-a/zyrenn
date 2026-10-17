<script setup lang="ts">
import { Keyboard } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import Tip from '@/components/common/Tip.vue';

// How to get about a board, kept a click away rather than written under its
// title where it was read once and then only took up room
const sections: { name: string; rows: [string, string][] }[] = [
    {
        name: 'Getting about',
        rows: [
            ['Space + drag', 'Move the canvas'],
            ['Right-drag', 'Move the canvas'],
            ['Scroll · pinch', 'Zoom'],
            ['Shift + scroll', 'Move sideways'],
        ],
    },
    {
        name: 'Tools',
        rows: [
            ['V', 'Select'],
            ['S · T', 'Sticky note · text'],
            ['R · O · M', 'Rectangle · ellipse · decision'],
            ['A', 'Connector'],
            ['D · F · E', 'Pen · frame · formula'],
            ['Esc', 'Back to select'],
        ],
    },
    {
        name: 'Editing',
        rows: [
            ['Double-click', 'Write on a shape'],
            ['Enter', 'Finish writing'],
            ['Shift + click', 'Add to the selection'],
            ['Ctrl + D', 'Duplicate'],
            ['R', 'Turn a selected part of a circuit'],
            ['Delete', 'Remove'],
            ['Ctrl + ] · [', 'Forward · backward'],
            ['Ctrl + Z · Shift + Z', 'Undo · redo'],
        ],
    },
];
</script>

<template>
    <!-- The tip is round the whole popover: inside it, the tip's own
         anchor would be taken for the popover's -->
    <Tip text="Keyboard shortcuts">
        <span class="inline-flex">
            <Popover>
                <PopoverTrigger as-child>
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Keyboard shortcuts"
                        data-test="board-shortcuts"
                    >
                        <Keyboard />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="end" class="w-80 p-3">
                    <section
                        v-for="section in sections"
                        :key="section.name"
                        class="not-first:mt-3"
                    >
                        <p
                            class="text-muted-foreground mb-1 text-[11px] font-semibold tracking-wide uppercase"
                        >
                            {{ section.name }}
                        </p>
                        <dl
                            class="grid grid-cols-[8.5rem_1fr] gap-x-3 gap-y-1 text-xs"
                        >
                            <template
                                v-for="[keys, what] in section.rows"
                                :key="keys"
                            >
                                <dt>
                                    <kbd
                                        class="bg-muted rounded px-1.5 py-0.5 font-mono text-[11px] whitespace-nowrap"
                                        >{{ keys }}</kbd
                                    >
                                </dt>
                                <dd class="text-muted-foreground self-center">
                                    {{ what }}
                                </dd>
                            </template>
                        </dl>
                    </section>
                </PopoverContent>
            </Popover>
        </span>
    </Tip>
</template>
