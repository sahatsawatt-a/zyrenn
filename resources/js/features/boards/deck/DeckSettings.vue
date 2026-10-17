<script setup lang="ts">
import { Paintbrush, Presentation } from '@lucide/vue';
import { computed, ref } from 'vue';
import FoldSection from '@/components/common/FoldSection.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import type { Deck, DeckTheme } from '@/features/boards/deck/deck';
import { DECK_THEMES, deckLook } from '@/features/boards/deck/deck';
import type { FontFamily } from '@/features/boards/model/items';
import { FONT_CHOICES, FONT_FAMILIES } from '@/features/boards/model/items';
import ColourChip from '../inspector/ColourChip.vue';

// The deck the board's frames make, behind a button in the panel: one look
// for every slide, and what is written on each -- a header, a footer, its
// number. A change is made as it is chosen; putting the slides already drawn
// into the look is a button of its own, since it changes what is on them.
const props = defineProps<{ deck: Deck | null; side: 'top' | 'left' }>();
const emit = defineEmits<{ update: [deck: Deck | null]; restyle: [] }>();

const look = computed(() => deckLook(props.deck));

// The theme's colours, one at a time: seldom changed, so folded away
const coloursOpen = ref(false);

/** The deck with these said over it; anything emptied is taken away. */
const change = (patch: Partial<Deck>) => {
    const next: Record<string, unknown> = { ...props.deck, ...patch };

    for (const [key, value] of Object.entries(next)) {
        if (value === '' || value === false || value === undefined) {
            delete next[key];
        }
    }

    emit('update', Object.keys(next).length ? (next as Deck) : null);
};

// A theme chosen is taken whole: its colours and typefaces, not those said
// over the last one's
const pickTheme = (theme: DeckTheme) =>
    change({
        theme: theme.id,
        background: undefined,
        ink: undefined,
        muted: undefined,
        accent: undefined,
        font: undefined,
        headingFont: undefined,
    });

const FONTS = FONT_CHOICES;

const COLOURS = [
    { key: 'background', label: 'Background' },
    { key: 'ink', label: 'Words' },
    { key: 'muted', label: 'Header and footer' },
    { key: 'accent', label: 'Accent' },
] as const;

const text = (event: Event) => (event.target as HTMLInputElement).value;
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="inspector-wide"
                title="The look of every slide, and what is written on each"
                data-test="deck-open"
            >
                <Presentation class="size-3.5" />
                Deck: theme, header and footer
            </button>
        </PopoverTrigger>
        <PopoverContent
            :side="side"
            align="start"
            :collision-padding="8"
            class="max-h-(--reka-popover-content-available-height) w-72 overflow-y-auto p-3"
        >
            <div class="space-y-3" data-test="deck-settings">
                <div class="space-y-1.5">
                    <p class="inspector-label">Theme</p>
                    <div class="grid grid-cols-3 gap-1.5">
                        <button
                            v-for="theme in DECK_THEMES"
                            :key="theme.id"
                            type="button"
                            class="deck-theme"
                            :class="{ 'is-on': look.id === theme.id }"
                            :style="{ backgroundColor: theme.background }"
                            :title="theme.label"
                            :data-test="`deck-theme-${theme.id}`"
                            @click="pickTheme(theme)"
                        >
                            <span
                                :style="{
                                    color: theme.ink,
                                    fontFamily:
                                        FONT_FAMILIES[theme.headingFont],
                                }"
                                >Aa</span
                            >
                            <span
                                class="deck-theme-bar"
                                :style="{ backgroundColor: theme.accent }"
                            />
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="space-y-1">
                        <p class="inspector-label">Titles</p>
                        <Select
                            :model-value="look.headingFont"
                            @update:model-value="
                                change({ headingFont: $event as FontFamily })
                            "
                        >
                            <SelectTrigger
                                class="w-full"
                                size="sm"
                                data-test="deck-heading-font"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="font in FONTS"
                                    :key="font.value"
                                    :value="font.value"
                                    :style="{
                                        fontFamily: FONT_FAMILIES[font.value],
                                    }"
                                    >{{ font.label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="space-y-1">
                        <p class="inspector-label">Text</p>
                        <Select
                            :model-value="look.font"
                            @update:model-value="
                                change({ font: $event as FontFamily })
                            "
                        >
                            <SelectTrigger
                                class="w-full"
                                size="sm"
                                data-test="deck-font"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="font in FONTS"
                                    :key="font.value"
                                    :value="font.value"
                                    :style="{
                                        fontFamily: FONT_FAMILIES[font.value],
                                    }"
                                    >{{ font.label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <!-- The theme's colours, one at a time: seldom changed -->
                <FoldSection v-model:open="coloursOpen" title="Colours">
                    <div class="grid grid-cols-2 gap-x-2">
                        <label
                            v-for="colour in COLOURS"
                            :key="colour.key"
                            class="flex items-center gap-1 text-xs"
                        >
                            <ColourChip
                                :colour="look[colour.key]"
                                :label="colour.label"
                                :side="side"
                                @pick="change({ [colour.key]: $event })"
                            />
                            {{ colour.label }}
                        </label>
                    </div>
                </FoldSection>

                <label class="block space-y-1">
                    <span class="inspector-label">Header</span>
                    <input
                        class="w-full rounded-md border bg-transparent px-2 py-1 text-xs"
                        maxlength="200"
                        placeholder="e.g. {section}"
                        :value="deck?.header ?? ''"
                        data-test="deck-header"
                        @change="change({ header: text($event) })"
                    />
                </label>
                <label class="block space-y-1">
                    <span class="inspector-label">Footer</span>
                    <input
                        class="w-full rounded-md border bg-transparent px-2 py-1 text-xs"
                        maxlength="200"
                        placeholder="e.g. {title} · Source: …"
                        :value="deck?.footer ?? ''"
                        data-test="deck-footer"
                        @change="change({ footer: text($event) })"
                    />
                </label>
                <p class="inspector-hint">
                    {n} the slide's number, {count} how many, {section} its
                    part, {title} the board's title.
                </p>

                <label class="inspector-switch">
                    Number the slides
                    <Switch
                        :model-value="!!deck?.numbers"
                        data-test="deck-numbers"
                        @update:model-value="change({ numbers: $event })"
                    />
                </label>

                <button
                    type="button"
                    class="inspector-wide"
                    title="Every slide in the deck's background, and the words on them in its typefaces and colours"
                    data-test="deck-restyle"
                    @click="emit('restyle')"
                >
                    <Paintbrush class="size-3.5" />
                    Put every slide in this look
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>

<style scoped>
.deck-theme {
    position: relative;
    display: flex;
    height: 2.5rem;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    font-size: 0.875rem;
    font-weight: 600;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.deck-theme.is-on {
    outline: 2px solid var(--primary);
    outline-offset: 1px;
}
.deck-theme-bar {
    position: absolute;
    left: 0.375rem;
    bottom: 0.375rem;
    width: 0.875rem;
    height: 0.1875rem;
    border-radius: 999px;
}
</style>
