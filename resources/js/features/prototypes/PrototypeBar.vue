<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Check,
    History,
    LayoutGrid,
    MessageSquare,
    Monitor,
    Play,
    Plus,
    Smartphone,
    Star,
    Tablet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Item } from '@/features/boards/model/items';
import { makeItem } from '@/features/boards/model/items';
import { play, update } from '@/routes/prototypes';
import { DEVICES } from './devices';
import type { Device, PrototypeSettings } from './devices';
import { onTheBoard, playsOf, usePrototypeComments } from './feedback';
import PlaysPanel from './PlaysPanel.vue';

// A prototype's own bar on its board: its screens, to go to one, add one or
// choose the one Play opens on; its comments, and which show on the screens;
// who played it and what they saw; the device it is made for; and Play.

const props = defineProps<{
    board: string;
    prototype: PrototypeSettings;
    // Owners and editors: they choose where Play's saves go
    canChange?: boolean;
    // The canvas, as it exposes itself: its items and its camera
    canvas: {
        board: {
            frames: { value: Item[] };
            add: (item: Item) => void;
        };
        camera: {
            focus: (
                box: { x: number; y: number; width: number; height: number },
                options?: { animate?: boolean; padding?: number },
            ) => void;
        };
    } | null;
}>();

const ICONS: Record<Device, Component> = {
    phone: Smartphone,
    tablet: Tablet,
    desktop: Monitor,
};

const screens = computed(() => props.canvas?.board.frames.value ?? []);
const start = computed(
    () => props.prototype.start ?? screens.value[0]?.id ?? null,
);

const save = (change: Partial<PrototypeSettings>) =>
    router.patch(update.url(props.board), change, {
        preserveScroll: true,
        preserveState: true,
    });

const goTo = (screen: Item) =>
    props.canvas?.camera.focus(screen, { animate: true, padding: 48 });

// ------------------------------------------------------- Where saves go
const data = computed(() => props.prototype.data ?? 'play');

const DATA: { value: 'play' | 'tables'; label: string; hint: string }[] = [
    {
        value: 'play',
        label: 'Kept to each play',
        hint: 'A demo never changes the tables',
    },
    {
        value: 'tables',
        label: 'Saved to the tables',
        hint: 'For editors; viewers still play on a copy',
    },
];

// ---------------------------------------------------------------- Comments
const { open: openComments, count } = usePrototypeComments(props.board);

const PINS: { value: typeof onTheBoard.pins; label: string }[] = [
    { value: 'open', label: 'Show open comments' },
    { value: 'all', label: 'Show resolved ones too' },
    { value: 'none', label: 'Hide the pins' },
];

const screenTitle = (id: string) =>
    screens.value.find((screen) => screen.id === id)?.text.trim() ||
    'Untitled screen';

const goToScreen = (id: string) => {
    const screen = screens.value.find((each) => each.id === id);

    if (screen) {
        goTo(screen);
    }
};

// ------------------------------------------------------------------- Plays
// Read each time the panel opens; their taps and visits show on the screens
// while it is open
const playsOpen = ref(false);

const openPlays = async (open: boolean) => {
    playsOpen.value = open;
    onTheBoard.play = null;
    onTheBoard.plays = null;

    if (open) {
        const plays = await playsOf(props.board).catch(() => []);

        if (playsOpen.value) {
            onTheBoard.plays = plays;
        }
    }
};

// A new screen at the device's size, to the right of the others
const addScreen = () => {
    if (!props.canvas) {
        return;
    }

    const { width, height } = DEVICES[props.prototype.device];
    const right = Math.max(
        0,
        ...screens.value.map((screen) => screen.x + screen.width + 160),
    );
    const screen: Item = {
        ...makeItem('frame', right, 0),
        width,
        height,
        text: `Screen ${screens.value.length + 1}`,
        fill: '#ffffff',
        stroke: '#e2e8f0',
    };

    props.canvas.board.add(screen);
    goTo(screen);
};
</script>

<template>
    <div class="flex items-center gap-1" data-test="prototype-bar">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label="Screens"
                    data-test="prototype-screens"
                >
                    <!-- On a phone just the count, so the title keeps room -->
                    <LayoutGrid class="sm:hidden" />
                    <span class="hidden sm:inline">Screens</span>
                    <span class="text-muted-foreground tabular-nums">{{
                        screens.length
                    }}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-60">
                <DropdownMenuLabel class="text-muted-foreground text-xs">
                    Go to a screen · ★ opens Play
                </DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="screen in screens"
                    :key="screen.id"
                    class="group"
                    data-test="prototype-screen"
                    @select="goTo(screen)"
                >
                    <span class="min-w-0 flex-1 truncate">{{
                        screen.text || 'Untitled screen'
                    }}</span>
                    <button
                        type="button"
                        class="rounded p-0.5"
                        :class="
                            screen.id === start
                                ? 'text-amber-500'
                                : 'text-muted-foreground opacity-0 group-hover:opacity-100 focus:opacity-100'
                        "
                        :title="
                            screen.id === start
                                ? 'Play opens on this screen'
                                : 'Open Play on this screen'
                        "
                        data-test="prototype-start"
                        @click.stop="save({ start: screen.id })"
                    >
                        <Star
                            class="size-3.5"
                            :fill="
                                screen.id === start ? 'currentColor' : 'none'
                            "
                        />
                    </button>
                </DropdownMenuItem>
                <DropdownMenuSeparator v-if="screens.length" />
                <DropdownMenuItem
                    data-test="prototype-add-screen"
                    @select="addScreen"
                >
                    <Plus /> Add a screen
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="sm"
                    :aria-label="`Comments, ${count} open`"
                    data-test="prototype-comments"
                >
                    <MessageSquare />
                    <span
                        class="tabular-nums"
                        data-test="prototype-comment-count"
                        >{{ count }}</span
                    >
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-72">
                <DropdownMenuLabel class="text-muted-foreground text-xs">
                    {{
                        count
                            ? `${count} open · from Play and here`
                            : 'No open comments · leave them in Play'
                    }}
                </DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="comment in openComments"
                    :key="comment.ref_id"
                    class="flex-col items-start gap-0.5"
                    data-test="prototype-comment"
                    @select="goToScreen(comment.screen)"
                >
                    <span class="line-clamp-2 text-sm">{{ comment.body }}</span>
                    <span class="text-muted-foreground text-xs">
                        {{ comment.by?.name ?? 'Someone' }} ·
                        {{ screenTitle(comment.screen) }}
                    </span>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    v-for="option in PINS"
                    :key="option.value"
                    :data-pins="option.value"
                    @select="onTheBoard.pins = option.value"
                >
                    {{ option.label }}
                    <Check
                        v-if="onTheBoard.pins === option.value"
                        class="ml-auto size-3.5"
                    />
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <Popover :open="playsOpen" @update:open="openPlays">
            <PopoverTrigger as-child>
                <Button
                    :variant="playsOpen ? 'secondary' : 'ghost'"
                    size="sm"
                    data-test="prototype-plays"
                >
                    <History />
                    <span class="hidden sm:inline">Plays</span>
                </Button>
            </PopoverTrigger>
            <!-- Not in the way of the screens it marks up -->
            <PopoverContent
                align="end"
                class="w-80 p-0"
                @interact-outside="(event: Event) => event.preventDefault()"
            >
                <PlaysPanel :plays="onTheBoard.plays" :frames="screens" />
            </PopoverContent>
        </Popover>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="sm"
                    :title="`Made for a ${DEVICES[prototype.device].label.toLowerCase()}`"
                    data-test="prototype-device"
                >
                    <component :is="ICONS[prototype.device]" />
                    <span class="hidden sm:inline">{{
                        DEVICES[prototype.device].label
                    }}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuLabel class="text-muted-foreground text-xs">
                    New screens are made at its size
                </DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="(device, key) in DEVICES"
                    :key="key"
                    :data-device="key"
                    @select="key !== prototype.device && save({ device: key })"
                >
                    <component :is="ICONS[key]" />
                    {{ device.label }}
                    <span class="text-muted-foreground ml-auto text-xs"
                        >{{ device.width }}×{{ device.height }}</span
                    >
                    <Check v-if="key === prototype.device" class="size-3.5" />
                </DropdownMenuItem>
                <!-- Where what Play saves to its tables goes -->
                <template v-if="canChange">
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel class="text-muted-foreground text-xs">
                        Changes in Play
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="option in DATA"
                        :key="option.value"
                        :data-prototype-data="option.value"
                        @select="
                            option.value !== data &&
                            save({ data: option.value })
                        "
                    >
                        <span class="flex flex-col">
                            {{ option.label }}
                            <span class="text-muted-foreground text-xs">{{
                                option.hint
                            }}</span>
                        </span>
                        <Check
                            v-if="option.value === data"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>

        <Button as-child size="sm" data-test="prototype-play">
            <Link :href="play(board)" aria-label="Play">
                <Play /> <span class="hidden sm:inline">Play</span>
            </Link>
        </Button>
    </div>
</template>
