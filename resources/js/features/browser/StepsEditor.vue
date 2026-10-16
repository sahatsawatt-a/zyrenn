<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    Copy,
    Crosshair,
    EllipsisVertical,
    Frame,
    HardDrive,
    MessageSquareText,
    Plus,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import DriveFileDialog from '@/features/chat/components/DriveFileDialog.vue';
import type { DriveFile } from '@/lib/drive';
import { KIND_ICONS, KIND_TONE } from './icons';
import PickDialog from './PickDialog.vue';
import PointerFields from './PointerFields.vue';
import { GROUPS, KEYS, KINDS, lingers, say } from './steps';
import type { Look, Step, StepGroup, StepKind, Thing } from './steps';

// How long a demo film stays on a step, and how long a step may wait, as
// choices: "Auto" (its caption's time, or the pace's) and "the suite's" are
// what is left out
const AUTO = 'auto';
const STAYS = [0, 1, 2, 3, 5, 8, 10, 15, 20, 30];
const GIVE_UPS = [5, 10, 15, 20, 30, 45, 60, 90, 120, 180, 300];

const seconds = (value: number) =>
    value === 0 ? 'No pause' : `${Number(value.toFixed(1))} s`;

/** The choices, with what is set now kept among them. */
const choices = (all: number[], now: number | string | null | undefined) =>
    [
        ...new Set([
            ...all,
            ...(now == null || now === '' ? [] : [Number(now)]),
        ]),
    ].sort((a, b) => a - b);

const autoStay = (step: Step) => {
    const at = lingers(step);

    return at === 0 ? 'no pause' : `${Math.round(at)} s`;
};

/** How long its caption is up for, all told. */
const shownFor = (step: Step) =>
    `${Math.round(step.wait == null ? lingers(step) : Number(step.wait))} s`;

// A caption box as tall as what is written in it, up to a point
const grow = (el: HTMLTextAreaElement) => {
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight + 2, 240)}px`;
};
// (and again when its width changes: on a narrower screen its words take more lines)
const watchers = new WeakMap<HTMLTextAreaElement, ResizeObserver>();
const vGrow = {
    mounted: (el: HTMLTextAreaElement) => {
        grow(el);
        el.addEventListener('input', () => grow(el));
        let width = el.clientWidth;
        const watcher = new ResizeObserver(() => {
            if (el.clientWidth !== width) {
                width = el.clientWidth;
                grow(el);
            }
        });
        watcher.observe(el);
        watchers.set(el, watcher);
    },
    updated: grow,
    unmounted: (el: HTMLTextAreaElement) => watchers.get(el)?.disconnect(),
};

// One check's steps, one under another, each said in a line -- what it does,
// in words -- and opened to change it: what it does, and what it needs (the
// page, the thing it is about, what to type, the key, the file). Changed in
// place. What a step is about can be picked from the page the steps before it
// lead to, rather than guessed at. Any step can carry a note: what a demo
// video tells whoever watches it -- and how long the video stays on it.

const props = defineProps<{
    steps: Step[];
    readonly?: boolean;
    // The page the first `upto` steps lead to, and what is on it
    look?: (upto: number) => Promise<Look>;
    // How long a step waits for the site, as its suite says, and the most one may
    stepLimit?: number;
    stepMost?: number;
}>();
const emit = defineEmits<{ change: [] }>();

// The kinds, as the menus offer them: what is done, then what is expected
const GROUPED = (Object.keys(GROUPS) as StepGroup[]).map((group) => ({
    group,
    label: GROUPS[group],
    kinds: (Object.keys(KINDS) as StepKind[]).filter(
        (kind) => KINDS[kind].group === group,
    ),
}));

// Those being changed: open, the rest said in a line. A step not yet
// written in full starts open.
const opened = ref<Set<Step>>(
    new Set(props.steps.filter((step) => unwritten(step))),
);

function unwritten(step: Step) {
    // Scrolling to nothing in particular goes a screen down; a move may go
    // by points or pixels alone
    if (step.do === 'scroll' || step.do === 'move') {
        return false;
    }

    return KINDS[step.do].fields.some((field) =>
        field === 'url'
            ? !/^(https?:\/\/[^/\s]+|data:text\/html)/i.test(step.url ?? '')
            : field === 'key'
              ? false
              : !String(step[field] ?? '').trim(),
    );
}

function toggle(step: Step) {
    const next = new Set(opened.value);

    if (next.has(step)) {
        next.delete(step);
    } else {
        next.add(step);
    }

    opened.value = next;
}

function setKind(step: Step, kind: StepKind) {
    step.do = kind;

    if (kind === 'press') {
        step.key ??= 'Enter';
    }

    emit('change');
}

function add(kind: StepKind) {
    const step: Step =
        kind === 'press' ? { do: 'press', key: 'Enter' } : { do: kind };
    props.steps.push(step);
    opened.value = new Set([...opened.value, step]);
    emit('change');
}

function move(at: number, by: -1 | 1) {
    const [step] = props.steps.splice(at, 1);
    props.steps.splice(at + by, 0, step);
    emit('change');
}

function copy(at: number) {
    const step = { ...props.steps[at] };
    props.steps.splice(at + 1, 0, step);
    emit('change');
}

function remove(at: number) {
    props.steps.splice(at, 1);
    emit('change');
}

// ------------------------------------------------------------ Picking from the page
const picking = ref<number | null>(null);
const pickOpen = ref(false);
const looked = ref<Look | null>(null);
const looking = ref(false);
const lookProblem = ref<string | null>(null);

async function startPicking(at: number) {
    picking.value = at;
    pickOpen.value = true;
    looked.value = null;
    lookProblem.value = null;
    looking.value = true;

    try {
        looked.value = await props.look!(at);
    } catch (problem) {
        lookProblem.value = (problem as Error).message;
    } finally {
        looking.value = false;
    }
}

function picked(thing: Thing) {
    if (picking.value !== null) {
        const step = props.steps[picking.value];
        step.target = thing.name;

        // What has no words of its own is found by where it was picked from
        for (const [field, value] of [
            ['selector', thing.selector],
            ['frame', thing.frame],
            ['nth', thing.nth && thing.nth > 1 ? thing.nth : undefined],
        ] as const) {
            if (value) {
                (step as Record<string, unknown>)[field] = value;
            } else {
                delete step[field];
            }
        }

        emit('change');
    }
}

/** Its words written by hand: no longer where it was picked from. */
function wrote(step: Step) {
    delete step.selector;
    delete step.nth;
    delete step.frame;
    emit('change');
}

// A page to pick from needs a page opened first
const canPick = (at: number) =>
    !props.readonly && !!props.look && at > 0 && props.steps[0].do === 'open';

// ------------------------------------------------------------ A file from the Drive
const filing = ref<Step | null>(null);
const fileOpen = ref(false);

function chooseFile(step: Step) {
    filing.value = step;
    fileOpen.value = true;
}

function filed(file: DriveFile) {
    if (filing.value) {
        filing.value.file = file.ref_id;
        filing.value.text = file.name;
        emit('change');
    }
}
</script>

<template>
    <ol class="divide-y rounded-lg border" data-test="check-steps">
        <li
            v-for="(step, at) in steps"
            :key="at"
            class="group/step"
            data-test="check-step"
        >
            <!-- The step in a line -->
            <div class="flex items-center gap-2 px-2 py-1.5">
                <span
                    class="text-muted-foreground w-5 shrink-0 text-center text-[11px] tabular-nums"
                    >{{ at + 1 }}</span
                >
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded-md"
                    :class="KIND_TONE[step.do]"
                >
                    <component :is="KIND_ICONS[step.do]" class="size-3.5" />
                </span>
                <button
                    type="button"
                    class="min-w-0 flex-1 truncate py-1 text-left text-sm"
                    :class="{
                        'text-muted-foreground italic': unwritten(step),
                    }"
                    :aria-expanded="opened.has(step)"
                    data-test="step-line"
                    @click="toggle(step)"
                >
                    {{
                        unwritten(step) ? `${KINDS[step.do].label}…` : say(step)
                    }}
                </button>
                <MessageSquareText
                    v-if="step.note"
                    class="text-muted-foreground size-3.5 shrink-0"
                    :title="`Says in a demo video: ${step.note}`"
                    data-test="step-has-note"
                />
                <span
                    v-if="step.frame"
                    class="text-muted-foreground hidden items-center gap-1 text-[11px] sm:inline-flex"
                    title="Inside a frame of the page"
                >
                    <Frame class="size-3" /> in a frame
                </span>
                <span
                    v-else-if="step.selector"
                    class="text-muted-foreground hidden text-[11px] sm:inline"
                    title="Picked on the page: found by where it is there"
                    >picked</span
                >
                <ChevronDown
                    class="text-muted-foreground hidden size-4 shrink-0 transition-transform sm:block"
                    :class="{ 'rotate-180': opened.has(step) }"
                    @click="toggle(step)"
                />
                <DropdownMenu v-if="!readonly">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="shrink-0"
                            aria-label="More for this step"
                            data-test="step-menu"
                        >
                            <EllipsisVertical />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            :disabled="at === 0"
                            @select="move(at, -1)"
                            ><ArrowUp /> Move up</DropdownMenuItem
                        >
                        <DropdownMenuItem
                            :disabled="at === steps.length - 1"
                            @select="move(at, 1)"
                            ><ArrowDown /> Move down</DropdownMenuItem
                        >
                        <DropdownMenuItem @select="copy(at)"
                            ><Copy /> Do it twice</DropdownMenuItem
                        >
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            :disabled="steps.length === 1"
                            @select="remove(at)"
                            ><Trash2 /> Remove</DropdownMenuItem
                        >
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <!-- The step, to change -->
            <div
                v-if="opened.has(step)"
                class="bg-muted/30 grid gap-2 border-t px-3 py-2.5 sm:grid-cols-[11rem_1fr] sm:pl-[3.75rem]"
            >
                <Select
                    :model-value="step.do"
                    :disabled="readonly"
                    @update:model-value="setKind(step, $event as StepKind)"
                >
                    <SelectTrigger
                        class="w-full"
                        size="sm"
                        aria-label="What the step does"
                        data-test="step-kind"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup v-for="one in GROUPED" :key="one.group">
                            <SelectLabel>{{ one.label }}</SelectLabel>
                            <SelectItem
                                v-for="kind in one.kinds"
                                :key="kind"
                                :value="kind"
                                >{{ KINDS[kind].label }}</SelectItem
                            >
                        </SelectGroup>
                    </SelectContent>
                </Select>

                <div class="flex min-w-0 flex-col gap-2 sm:flex-row">
                    <Input
                        v-if="step.do === 'open'"
                        v-model="step.url"
                        :readonly="readonly"
                        :placeholder="KINDS.open.placeholder"
                        aria-label="The page's address"
                        class="h-8"
                        data-test="step-url"
                        @update:model-value="emit('change')"
                    />
                    <div
                        v-if="KINDS[step.do].fields.includes('target')"
                        class="flex min-w-0 flex-1 gap-1"
                    >
                        <Input
                            v-model="step.target"
                            :readonly="readonly"
                            :placeholder="KINDS[step.do].placeholder"
                            aria-label="What it is about"
                            class="h-8"
                            data-test="step-target"
                            @update:model-value="wrote(step)"
                        />
                        <Button
                            v-if="canPick(at)"
                            variant="outline"
                            size="sm"
                            class="h-8 shrink-0"
                            title="Pick it from the page"
                            data-test="step-pick"
                            @click="startPicking(at)"
                        >
                            <Crosshair />
                            <span class="hidden sm:inline">Pick</span>
                        </Button>
                    </div>
                    <Input
                        v-if="
                            step.do === 'type' ||
                            step.do === 'choose' ||
                            step.do === 'address'
                        "
                        v-model="step.text"
                        :readonly="readonly"
                        :placeholder="
                            step.do === 'address'
                                ? KINDS.address.placeholder
                                : KINDS[step.do].textLabel
                        "
                        :aria-label="KINDS[step.do].textLabel"
                        class="h-8 min-w-0 sm:flex-1"
                        data-test="step-text"
                        @update:model-value="emit('change')"
                    />
                    <!-- A drag's common case: from one thing onto another -->
                    <Input
                        v-if="step.do === 'drag'"
                        :model-value="step.onto ?? ''"
                        :readonly="readonly"
                        placeholder="Dropped onto…"
                        aria-label="What it is dropped onto"
                        class="h-8 min-w-0 sm:flex-1"
                        data-test="step-onto"
                        @update:model-value="
                            step.onto = String($event) || undefined;
                            emit('change');
                        "
                    />
                    <Button
                        v-if="step.do === 'upload'"
                        variant="outline"
                        size="sm"
                        class="h-8 min-w-0 shrink justify-start sm:max-w-56"
                        :disabled="readonly"
                        data-test="step-file"
                        @click="chooseFile(step)"
                    >
                        <HardDrive />
                        <span class="truncate">{{
                            step.text || 'Choose a file from the Drive'
                        }}</span>
                    </Button>
                    <Select
                        v-if="step.do === 'press'"
                        :model-value="step.key ?? 'Enter'"
                        :disabled="readonly"
                        @update:model-value="
                            step.key = $event as string;
                            emit('change');
                        "
                    >
                        <SelectTrigger
                            class="w-40"
                            size="sm"
                            aria-label="Which key"
                            data-test="step-key"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="key in KEYS"
                                :key="key"
                                :value="key"
                                >{{ key }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <p
                        v-if="!KINDS[step.do].fields.length"
                        class="text-muted-foreground self-center text-xs"
                    >
                        {{
                            step.do === 'back'
                                ? 'Goes back to the page before, as the browser’s back button does.'
                                : step.do === 'up'
                                  ? 'Lets go of what was pressed and held, with any keys held.'
                                  : 'A picture of the page as it is now, kept with the run.'
                        }}
                    </p>
                </div>

                <PointerFields
                    v-if="['down', 'move', 'drag'].includes(step.do)"
                    :step="step"
                    :readonly="readonly"
                    @change="emit('change')"
                />

                <!-- For a demo film: what to tell whoever watches, how long the
                     film stays on it; for a slow site: how long it may wait -->
                <div
                    v-if="
                        !readonly ||
                        step.note ||
                        step.wait != null ||
                        step.timeout != null
                    "
                    class="space-y-2 sm:col-span-2"
                >
                    <label class="block space-y-1">
                        <span
                            class="text-muted-foreground flex items-baseline justify-between gap-2 text-xs"
                        >
                            Caption in a demo film
                            <span
                                v-if="step.note?.trim()"
                                class="tabular-nums"
                                data-test="step-note-says"
                                >{{ step.note.trim().length }}/600 · up about
                                {{ shownFor(step) }}</span
                            >
                        </span>
                        <textarea
                            v-model="step.note"
                            v-grow
                            :readonly="readonly"
                            rows="2"
                            maxlength="600"
                            class="bg-background dark:bg-input/30 border-input block w-full resize-none rounded-md border px-3 py-2 text-sm leading-relaxed"
                            placeholder="Optional: what to tell whoever watches this step"
                            aria-label="Caption in a demo film"
                            data-test="step-note"
                            @input="emit('change')"
                        />
                    </label>
                    <div class="grid grid-cols-2 gap-2 sm:max-w-md">
                        <label class="space-y-1">
                            <span class="text-muted-foreground block text-xs"
                                >Stays on screen</span
                            >
                            <Select
                                :model-value="
                                    step.wait == null
                                        ? AUTO
                                        : String(Number(step.wait))
                                "
                                :disabled="readonly"
                                @update:model-value="
                                    step.wait =
                                        $event === AUTO ? null : Number($event);
                                    emit('change');
                                "
                            >
                                <SelectTrigger
                                    size="sm"
                                    class="w-full"
                                    aria-label="How long a demo film stays on this step"
                                    title="How long a demo film stays on this step once it is done"
                                    data-test="step-wait"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="AUTO"
                                        >Auto · {{ autoStay(step) }}</SelectItem
                                    >
                                    <SelectItem
                                        v-for="one in choices(STAYS, step.wait)"
                                        :key="one"
                                        :value="String(one)"
                                        >{{ seconds(one) }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </label>
                        <!-- For a slow page or a long job: this step alone waits longer -->
                        <label class="space-y-1">
                            <span class="text-muted-foreground block text-xs"
                                >Gives up after</span
                            >
                            <Select
                                :model-value="
                                    step.timeout == null
                                        ? AUTO
                                        : String(step.timeout)
                                "
                                :disabled="readonly"
                                @update:model-value="
                                    step.timeout =
                                        $event === AUTO ? null : Number($event);
                                    emit('change');
                                "
                            >
                                <SelectTrigger
                                    size="sm"
                                    class="w-full"
                                    aria-label="How long this step waits for the site before it fails"
                                    :title="`How long this step waits for the site before it fails, up to ${stepMost ?? 60} s`"
                                    data-test="step-timeout"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="AUTO"
                                        >Suite's
                                        {{ stepLimit ?? 10 }} s</SelectItem
                                    >
                                    <SelectItem
                                        v-for="one in choices(
                                            GIVE_UPS.filter(
                                                (it) => it <= (stepMost ?? 60),
                                            ),
                                            step.timeout,
                                        )"
                                        :key="one"
                                        :value="String(one)"
                                        >{{ seconds(one) }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </label>
                    </div>
                </div>
            </div>
        </li>
    </ol>

    <DropdownMenu v-if="!readonly">
        <DropdownMenuTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                class="mt-2"
                data-test="check-add-step"
            >
                <Plus /> Add a step
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-56">
            <template v-for="(one, index) in GROUPED" :key="one.group">
                <DropdownMenuSeparator v-if="index" />
                <DropdownMenuLabel class="text-muted-foreground text-xs">{{
                    one.label
                }}</DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="kind in one.kinds"
                    :key="kind"
                    :data-test="`add-${kind}`"
                    @select="add(kind)"
                >
                    <component :is="KIND_ICONS[kind]" />
                    {{ KINDS[kind].label }}
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>

    <PickDialog
        v-if="picking !== null"
        v-model:open="pickOpen"
        :at="picking"
        :kind="steps[picking].do"
        :look="looked"
        :loading="looking"
        :problem="lookProblem"
        @pick="picked"
    />
    <DriveFileDialog
        v-model:open="fileOpen"
        kind="any"
        title="A file to add to the page"
        description="From the Drive, newest first. Each run adds it as a customer would."
        @pick="filed"
    />
</template>
