<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronDown,
    MapPin,
    MapPinOff,
    MessageSquarePlus,
    Pencil,
    RotateCcw,
} from '@lucide/vue';
import { computed } from 'vue';
import Tip from '@/components/common/Tip.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Screen } from './navigation';

// What there is to do in Play besides tapping: which prototype it is, any
// screen at once, back a step, from the start again, and back to editing.
const props = defineProps<{
    title: string;
    screens: Screen[];
    screen: string;
    // Something to go back to
    canGoBack: boolean;
    // The editor, for whoever may change the prototype
    edit: string | null;
    // Comments open on it, all screens
    comments: number;
}>();

// Tapping to leave a comment, and whether the comments' pins show
const commenting = defineModel<boolean>('commenting', { required: true });
const pinsShown = defineModel<boolean>('pinsShown', { required: true });

const emit = defineEmits<{
    jump: [string];
    back: [];
    restart: [];
}>();

const at = computed(() =>
    props.screens.findIndex((screen) => screen.id === props.screen),
);
const current = computed(() => props.screens[at.value]);
</script>

<template>
    <div class="play-bar" data-test="play-bar">
        <!-- On a phone the screen menu says enough: the room is the screen's -->
        <p
            class="min-w-0 truncate text-sm font-medium max-sm:hidden"
            :title="title"
        >
            {{ title }}
        </p>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="sm"
                    class="max-w-56 min-w-0 gap-1 font-normal"
                    data-test="play-screens"
                >
                    <span class="text-muted-foreground tabular-nums"
                        >{{ at + 1 }}/{{ screens.length }}</span
                    >
                    <span class="truncate">{{ current?.title }}</span>
                    <ChevronDown class="text-muted-foreground" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="max-h-[60vh] w-60">
                <DropdownMenuLabel>Go to a screen</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup
                    :model-value="screen"
                    @update:model-value="emit('jump', String($event))"
                >
                    <DropdownMenuRadioItem
                        v-for="(one, index) in screens"
                        :key="one.id"
                        :value="one.id"
                        :data-play-screen="one.id"
                    >
                        <span class="text-muted-foreground w-5 tabular-nums">{{
                            index + 1
                        }}</span>
                        <span class="truncate">{{ one.title }}</span>
                    </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>

        <span class="flex-1" />

        <Tip
            :text="
                commenting
                    ? 'Stop commenting'
                    : 'Comment: tap where it is about'
            "
        >
            <Button
                :variant="commenting ? 'secondary' : 'ghost'"
                size="sm"
                class="gap-1 px-2"
                :aria-pressed="commenting"
                aria-label="Comment"
                data-test="play-comment"
                @click="commenting = !commenting"
            >
                <MessageSquarePlus />
                <span
                    v-if="comments"
                    class="text-muted-foreground text-xs tabular-nums"
                    >{{ comments }}</span
                >
            </Button>
        </Tip>
        <Tip :text="pinsShown ? 'Hide the comments' : 'Show the comments'">
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-pressed="pinsShown"
                :aria-label="
                    pinsShown ? 'Hide the comments' : 'Show the comments'
                "
                data-test="play-pins"
                @click="pinsShown = !pinsShown"
            >
                <MapPin v-if="pinsShown" />
                <MapPinOff v-else />
            </Button>
        </Tip>

        <Tip text="Back a screen">
            <Button
                variant="ghost"
                size="icon-sm"
                aria-label="Back a screen"
                :disabled="!canGoBack"
                data-test="play-back"
                @click="emit('back')"
            >
                <ArrowLeft />
            </Button>
        </Tip>
        <Tip text="From the start">
            <Button
                variant="ghost"
                size="icon-sm"
                aria-label="From the start"
                data-test="play-restart"
                @click="emit('restart')"
            >
                <RotateCcw />
            </Button>
        </Tip>
        <!-- On a phone, the pencil says it: the bar is short -->
        <Tip v-if="edit" text="Edit the prototype">
            <Button
                as-child
                variant="outline"
                size="sm"
                class="max-sm:px-2"
                data-test="play-edit"
            >
                <Link :href="edit" aria-label="Edit"
                    ><Pencil /><span class="max-sm:hidden">Edit</span></Link
                >
            </Button>
        </Tip>
    </div>
</template>

<style scoped>
.play-bar {
    display: flex;
    min-width: 0;
    align-items: center;
    gap: 6px;
    padding: 6px 8px 6px 14px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: color-mix(in oklab, var(--card) 92%, transparent);
    box-shadow: 0 6px 20px rgb(15 23 42 / 0.12);
    backdrop-filter: blur(8px);
}

/* A phone's bar holds big buttons for fingers: a second row when it needs one */
@media (max-width: 640px) {
    .play-bar {
        flex-wrap: wrap;
        padding-left: 8px;
    }
}
</style>
