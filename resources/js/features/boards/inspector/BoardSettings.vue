<script setup lang="ts">
import { Paintbrush } from '@lucide/vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import DeckSettings from '../deck/DeckSettings.vue';
import type { Deck } from '../deck/deck';
import StylePicker from './StylePicker.vue';

// The panel with nothing selected: how to start, and what goes for the whole
// board -- every shape in one colour set and style, and the deck its frames
// make.
defineProps<{
    itemCount: number;
    frameCount: number;
    deck?: Deck | null;
    deckReady?: boolean;
    beside: 'top' | 'left';
}>();
const emit = defineEmits<{
    'restyle-board': [choice: { set: string; style: string }];
    deck: [deck: Deck | null];
    'restyle-deck': [];
}>();
</script>

<template>
    <div class="inspector-body">
        <p class="inspector-hint">
            Pick a tool from the bar below and drag it out on the canvas, or
            click something to change its colour, size and stacking.
        </p>

        <!-- Everything at once: one colour set, one style -->
        <Popover v-if="itemCount">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="inspector-wide"
                    title="Put every shape on the board in one colour set and style"
                    data-test="restyle-board"
                >
                    <Paintbrush class="size-3.5" />
                    Restyle the board
                </button>
            </PopoverTrigger>
            <PopoverContent
                :side="beside"
                align="start"
                :collision-padding="8"
                class="w-72 p-3"
            >
                <StylePicker
                    board
                    @pick="
                        emit('restyle-board', {
                            set: $event.set,
                            style: $event.style,
                        })
                    "
                />
            </PopoverContent>
        </Popover>

        <!-- The slides' look, and what is written on each -->
        <DeckSettings
            v-if="frameCount && deckReady"
            :deck="deck ?? null"
            :side="beside"
            @update="emit('deck', $event)"
            @restyle="emit('restyle-deck')"
        />
    </div>
</template>
