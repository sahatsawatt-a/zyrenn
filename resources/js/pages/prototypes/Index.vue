<script setup lang="ts">
import { Head, Link, useForm, usePage, setLayoutProps } from '@inertiajs/vue3';
import {
    Monitor,
    MousePointerClick,
    Play,
    Plus,
    Smartphone,
    Tablet,
} from '@lucide/vue';
import { computed, ref, watchEffect } from 'vue';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useSpaceChanges } from '@/composables/useSpaceChanges';
import { fitBoard } from '@/features/dashboard/preview';
import type { BoardPreview, Preview } from '@/features/dashboard/preview';
import { DEVICES } from '@/features/prototypes/devices';
import type { Device } from '@/features/prototypes/devices';
import { canChange } from '@/lib/projects';
import { formatRelativeTime } from '@/lib/utils';
import { index, store } from '@/routes/projects/prototypes';
import { play, show } from '@/routes/prototypes';

// A project's prototypes: clickable screens to show someone what they will
// get before anything is built, so what is agreed is agreed early. Each one
// opens on the board, its frames its screens; Play clicks through them.

type Prototype = {
    ref_id: string;
    title: string;
    device: Device;
    screens: number;
    updated_at: string;
    edited_by: string | null;
};

const props = defineProps<{
    prototypes: Prototype[];
    // A glimpse of each, by "board:ref_id"
    previews: Record<string, Preview>;
}>();

const page = usePage();
const project = computed(() => page.props.project!);
const changes = computed(() => canChange());

// Made, renamed or deleted by someone else, or by MCP: the list follows
useSpaceChanges(['boards'], ['prototypes', 'previews']);

watchEffect(() =>
    setLayoutProps({
        breadcrumbs: [
            {
                title: 'Prototypes',
                href: index(project.value.ref_id).url,
            },
        ],
    }),
);

const ICONS: Record<Device, Component> = {
    phone: Smartphone,
    tablet: Tablet,
    desktop: Monitor,
};

// The card's drawing area: tall enough for a phone's screens side by side
const WIDTH = 280;
const HEIGHT = 150;

const drawing = (prototype: Prototype) => {
    const preview = props.previews[`board:${prototype.ref_id}`];

    return preview?.kind === 'board'
        ? fitBoard(preview as BoardPreview, WIDTH, HEIGHT)
        : null;
};

const screensLabel = (count: number) =>
    `${count} ${count === 1 ? 'screen' : 'screens'}`;

// ---------------------------------------------------------------- Making one
const making = ref(false);
const form = useForm<{ title: string; device: Device }>({
    title: '',
    device: 'phone',
});

const make = () =>
    form.post(store.url(project.value.ref_id), {
        onSuccess: () => (making.value = false),
    });
</script>

<template>
    <Head title="Prototypes" />

    <div class="mx-auto grid w-full max-w-6xl gap-5 p-4 md:p-6">
        <div class="flex items-center gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="text-lg font-semibold">Prototypes</h1>
                <p class="text-muted-foreground text-sm">
                    Screens to click through with whoever it is for, before
                    anything is built.
                </p>
            </div>
            <Button
                v-if="changes"
                data-test="new-prototype"
                @click="making = true"
            >
                <Plus /> New prototype
            </Button>
        </div>

        <!-- None yet: what one is, and how it goes -->
        <div
            v-if="!prototypes.length"
            class="bg-card grid gap-4 rounded-xl border p-5 sm:grid-cols-3"
            data-test="prototypes-empty"
        >
            <div
                v-for="(step, at) in [
                    {
                        title: 'Draw the screens',
                        text: 'Each screen is a frame at the size of a phone, tablet or desktop. Put buttons, lists and forms on it from the UI kit.',
                    },
                    {
                        title: 'Link them',
                        text: 'Choose an item and say which screen tapping it goes to, or back.',
                    },
                    {
                        title: 'Play it with them',
                        text: 'Click through as they would and agree what to build, while changing it is still cheap.',
                    },
                ]"
                :key="at"
                class="flex gap-3"
            >
                <span
                    class="bg-primary/10 text-primary grid size-7 shrink-0 place-items-center rounded-full text-sm font-semibold"
                    >{{ at + 1 }}</span
                >
                <div>
                    <p class="text-sm font-medium">{{ step.title }}</p>
                    <p class="text-muted-foreground text-sm">{{ step.text }}</p>
                </div>
            </div>
        </div>

        <div
            v-else
            class="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-3"
            data-test="prototypes"
        >
            <div
                v-for="prototype in prototypes"
                :key="prototype.ref_id"
                class="bg-card hover:border-primary/40 group relative flex min-w-0 flex-col overflow-hidden rounded-xl border transition-colors"
                data-test="prototype-card"
            >
                <Link
                    :href="show(prototype.ref_id)"
                    class="flex min-w-0 flex-col outline-none"
                >
                    <div
                        class="bg-muted text-muted-foreground h-[150px] shrink-0 overflow-hidden"
                        aria-hidden="true"
                    >
                        <svg
                            v-if="drawing(prototype)"
                            class="size-full"
                            :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
                            preserveAspectRatio="xMidYMid meet"
                        >
                            <rect
                                v-for="(box, at) in drawing(prototype)!.boxes"
                                :key="at"
                                :x="box.x"
                                :y="box.y"
                                :width="box.width"
                                :height="box.height"
                                :rx="box.radius"
                                :fill="
                                    box.look === 'shape'
                                        ? box.fill || 'none'
                                        : 'currentColor'
                                "
                                :fill-opacity="box.look === 'shape' ? 1 : 0.3"
                                :stroke="box.stroke || 'currentColor'"
                                :stroke-opacity="box.stroke ? 1 : 0.4"
                                stroke-width="1"
                            />
                        </svg>
                    </div>
                    <div class="grid gap-0.5 px-3 py-2.5">
                        <p class="truncate text-sm font-medium">
                            {{ prototype.title }}
                        </p>
                        <p
                            class="text-muted-foreground flex items-center gap-1.5 truncate text-xs"
                        >
                            <component
                                :is="ICONS[prototype.device]"
                                class="size-3.5 shrink-0"
                            />
                            {{ screensLabel(prototype.screens) }} ·
                            {{
                                [
                                    prototype.edited_by,
                                    formatRelativeTime(prototype.updated_at),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                    </div>
                </Link>
                <Button
                    as-child
                    size="sm"
                    variant="secondary"
                    class="absolute top-2 right-2 shadow-sm"
                >
                    <Link
                        :href="play(prototype.ref_id)"
                        :title="`Play ${prototype.title}`"
                        data-test="prototype-card-play"
                    >
                        <Play /> Play
                    </Link>
                </Button>
            </div>
        </div>
    </div>

    <Dialog v-model:open="making">
        <DialogContent class="sm:max-w-md" data-test="new-prototype-dialog">
            <DialogHeader>
                <DialogTitle>New prototype</DialogTitle>
                <DialogDescription>
                    It starts with three empty screens -- Home, List and Detail
                    -- at the device's size.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="make">
                <Input
                    v-model="form.title"
                    placeholder="What it is, e.g. Booking flow"
                    aria-label="Name"
                    name="title"
                    autofocus
                />
                <div
                    class="grid grid-cols-3 gap-2"
                    role="radiogroup"
                    aria-label="Made for"
                >
                    <button
                        v-for="(device, key) in DEVICES"
                        :key="key"
                        type="button"
                        role="radio"
                        :aria-checked="form.device === key"
                        :data-device="key"
                        class="hover:border-primary/40 grid justify-items-center gap-1 rounded-lg border p-3 text-sm transition-colors"
                        :class="
                            form.device === key
                                ? 'border-primary bg-primary/5 text-primary'
                                : ''
                        "
                        @click="form.device = key"
                    >
                        <component :is="ICONS[key]" class="size-5" />
                        {{ device.label }}
                        <span class="text-muted-foreground text-[11px]"
                            >{{ device.width }}×{{ device.height }}</span
                        >
                    </button>
                </div>
                <p v-if="form.errors.device" class="text-destructive text-sm">
                    {{ form.errors.device }}
                </p>
                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="make-prototype"
                    >
                        <MousePointerClick /> Make it
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
