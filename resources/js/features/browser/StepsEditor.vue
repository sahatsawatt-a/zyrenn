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
import { GROUPS, KEYS, KINDS, lingers, say } from './steps';
import type { Look, Step, StepGroup, StepKind, Thing } from './steps';

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
    // Scrolling to nothing in particular goes a screen down
    if (step.do === 'scroll') {
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
                                : 'A picture of the page as it is now, kept with the run.'
                        }}
                    </p>
                </div>

                <div
                    v-if="!readonly || step.note || step.wait != null"
                    class="flex gap-2 sm:col-span-2"
                >
                    <textarea
                        v-model="step.note"
                        :readonly="readonly"
                        rows="2"
                        maxlength="600"
                        class="bg-background dark:bg-input/30 border-input min-w-0 flex-1 rounded-md border px-3 py-1.5 text-sm"
                        placeholder="A note for a demo video (optional): what to tell whoever watches this step"
                        aria-label="A note for a demo video"
                        data-test="step-note"
                        @input="emit('change')"
                    />
                    <label
                        class="text-muted-foreground flex w-20 shrink-0 flex-col gap-1 text-[11px]"
                        title="How long a demo video stays on this step, in seconds. Empty: as long as its note needs, or the pace's pause."
                    >
                        Stay (s)
                        <Input
                            :model-value="step.wait ?? ''"
                            :readonly="readonly"
                            type="number"
                            min="0"
                            max="30"
                            step="0.5"
                            :placeholder="String(lingers(step))"
                            class="h-8 px-2"
                            data-test="step-wait"
                            @update:model-value="
                                step.wait =
                                    $event === '' || $event == null
                                        ? null
                                        : Number($event);
                                emit('change');
                            "
                        />
                    </label>
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
