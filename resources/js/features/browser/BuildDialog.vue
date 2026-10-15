<script setup lang="ts">
import {
    ArrowLeft,
    AtSign,
    Camera,
    ChevronDown,
    CornerDownLeft,
    Eye,
    EyeOff,
    Globe,
    Keyboard,
    Loader2,
    Lock,
    Monitor,
    MousePointer2,
    MousePointerClick,
    Smartphone,
    Tablet,
    Undo2,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import SegmentTabs from '@/components/common/SegmentTabs.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import DriveFileDialog from '@/features/chat/components/DriveFileDialog.vue';
import type { DriveFile } from '@/lib/drive';
import { KIND_ICONS, KIND_TONE } from './icons';
import PageThings from './PageThings.vue';
import { KEYS, say } from './steps';
import type { LivePage, LiveStep, Look, Size, Step, Thing } from './steps';

// Building a check by clicking through the page, as a customer would. The
// page is kept open in the browser on the server while it is built, so each
// click is done once, there, and the picture shows where it led -- on any
// device: the one in hand only shows the picture and passes the clicks on.
// What a click on the page does is chosen above it: click it, point at it
// (a menu that opens on its own), or expect to see it. A click on a field
// asks what to type, on a list what to choose, on a file field which file
// from the Drive. A step is kept only once it has worked.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    title: string;
    // The check's steps so far, built on; none for a new check
    steps: Step[];
    size: Size;
    // The browser's side: a page opened at these steps, a step done on it, closed
    openPage: (steps: Step[], size: Size) => Promise<LivePage>;
    stepPage: (id: string, step: Step) => Promise<LiveStep>;
    closePage: (id: string) => Promise<unknown>;
}>();

const emit = defineEmits<{
    done: [steps: Step[], title: string, size: Size];
}>();

const built = ref<Step[]>([]);
const name = ref('');
const address = ref('https://');
const size = ref<Size>('desktop');
const pageId = ref<string | null>(null);
const page = ref<Look | null>(null);
// What the browser is doing now, in words
const doing = ref<string | null>(null);
const problem = ref<string | null>(null);
const mode = ref<'click' | 'hover' | 'see'>('click');
const typing = ref<Thing | null>(null);
const typed = ref('');
const typedInput = ref<InstanceType<typeof Input> | null>(null);
// A list being chosen from, and how its step finds it
const choosing = ref<{ thing: Thing; where: Where } | null>(null);

type Where = { selector?: string; nth?: number; frame?: string };

const started = computed(
    () => built.value.length > 0 && built.value[0].do === 'open',
);

watch(open, (now) => {
    if (!now) {
        void shut();

        return;
    }

    built.value = props.steps.map((step) => ({ ...step }));
    name.value = props.title;
    size.value = props.size;
    page.value = null;
    problem.value = null;
    typing.value = null;
    choosing.value = null;
    mode.value = 'click';

    if (started.value) {
        void openOn(built.value);
    }
});

async function shut() {
    if (pageId.value) {
        const id = pageId.value;
        pageId.value = null;
        await props.closePage(id).catch(() => {});
    }
}

/** A page opened anew at these steps: a new check, one built on, after an undo, or on another screen. */
async function openOn(steps: Step[]) {
    await shut();
    doing.value =
        steps.length > 1
            ? `Opening the page and doing the ${steps.length} steps so far…`
            : 'Opening the page…';
    problem.value = null;

    try {
        const answer = await props.openPage(steps, size.value);
        pageId.value = answer.id;
        page.value = answer;
    } catch (error) {
        problem.value = (error as Error).message;
    } finally {
        doing.value = null;
    }
}

function begin() {
    built.value = [{ do: 'open', url: address.value.trim() }];
    void openOn(built.value);
}

/** Another screen: the page opened again on it, at the steps so far. */
function resize(now: Size) {
    if (now !== size.value) {
        size.value = now;
        void openOn(built.value);
    }
}

/** Does `step` on the page, and keeps it when it worked. */
async function attempt(step: Step) {
    if (!pageId.value) {
        return false;
    }

    doing.value = `${say(step)}…`;
    problem.value = null;

    try {
        const answer = await props.stepPage(pageId.value, step);

        if (answer.step.ok) {
            built.value.push(step);
        } else {
            problem.value = `${say(step)} did not work: ${answer.step.error ?? ''} It was not added.`;
        }

        page.value = { ...answer, steps: [] };

        return answer.step.ok;
    } catch (error) {
        problem.value = (error as Error).message;

        return false;
    } finally {
        doing.value = null;
    }
}

/** How a step finds what was picked: where it was, which, and in which frame. */
const whereOf = (thing: Thing): Where => ({
    ...(thing.selector ? { selector: thing.selector } : {}),
    ...(thing.nth && thing.nth > 1 ? { nth: thing.nth } : {}),
    ...(thing.frame ? { frame: thing.frame } : {}),
});

/** A step made of what was clicked on the page. */
async function picked(thing: Thing) {
    const where = whereOf(thing);
    typing.value = null;
    choosing.value = null;

    if (mode.value === 'see') {
        return attempt({ do: 'see', target: thing.name, ...where });
    }

    if (mode.value === 'hover') {
        return attempt({ do: 'hover', target: thing.name, ...where });
    }

    // A list the browser draws itself: its options, to choose from here
    if (thing.kind === 'list') {
        choosing.value = { thing, where };

        return;
    }

    // A file field: which file, from the Drive
    if (thing.kind === 'file') {
        filing.value = thing;
        fileOpen.value = true;

        return;
    }

    if (thing.kind === 'field') {
        typing.value = thing;
        typed.value = '';
        await nextTick();
        (typedInput.value?.$el as HTMLInputElement | undefined)?.focus();

        return;
    }

    return attempt({ do: 'click', target: thing.name, ...where });
}

async function choose(option: string) {
    if (!choosing.value) {
        return;
    }

    const { thing, where } = choosing.value;
    choosing.value = null;
    await attempt({ do: 'choose', target: thing.name, text: option, ...where });
}

// What a field of its own kind takes, said where it is typed
const typingHint = computed(() => {
    const thing = typing.value;

    switch (thing?.input) {
        case 'range':
            return `A number from ${thing.min ?? 0} to ${thing.max ?? 100}`;
        case 'date':
            return 'As 2026-10-15';
        case 'time':
            return 'As 14:30';
        case 'datetime-local':
            return 'As 2026-10-15T14:30';
        case 'month':
            return 'As 2026-10';
        case 'color':
            return 'As #3366ff';
        default:
            return 'What a customer types, or {{password}}';
    }
});

// What is typed into the box is typed into the field on the page too, a
// moment after the typing stops, so the picture shows it -- and whatever
// the site does with it -- before it is kept as a step
const showing = ref(false);
let typedTurn = 0;

async function showTyped(text: string) {
    if (!typing.value || !pageId.value) {
        return;
    }

    const turn = ++typedTurn;
    showing.value = true;

    try {
        const answer = await props.stepPage(pageId.value, {
            do: 'type',
            target: typing.value.name,
            text,
            ...whereOf(typing.value),
        });

        // Only the latest: typing on while one was on its way
        if (turn === typedTurn) {
            page.value = { ...answer, steps: [] };
        }
    } catch {
        // Shown again with the next letter, or when it is added
    } finally {
        if (turn === typedTurn) {
            showing.value = false;
        }
    }
}

const showTypedSoon = useDebounceFn(showTyped, 450);
watch(typed, (now) => {
    if (typing.value) {
        void showTypedSoon(now);
    }
});

async function addTyping() {
    if (!typing.value) {
        return;
    }

    const step: Step = {
        do: 'type',
        target: typing.value.name,
        text: typed.value,
        ...whereOf(typing.value),
    };
    typedTurn++;
    typing.value = null;
    await attempt(step);
}

/** Not the field after all: what was shown in it is taken out again. */
async function notThisOne() {
    const left = typed.value !== '';
    typedTurn++;

    if (left) {
        await showTyped('');
    }

    typing.value = null;
}

// ------------------------------------------------------------ A file from the Drive
const filing = ref<Thing | null>(null);
const fileOpen = ref(false);

async function filed(file: DriveFile) {
    const thing = filing.value;
    filing.value = null;

    if (thing) {
        await attempt({
            do: 'upload',
            target: thing.name,
            text: file.name,
            file: file.ref_id,
            ...whereOf(thing),
        });
    }
}

// ------------------------------------------------------------ Steps of no thing
// Written rather than picked: what should be gone, what the address has
const goneText = ref('');
const goneOpen = ref(false);
const addressText = ref('');
const addressOpen = ref(false);

watch(addressOpen, (now) => {
    if (now && page.value) {
        try {
            addressText.value = new URL(page.value.url).pathname;
        } catch {
            addressText.value = '';
        }
    }
});

async function expectGone() {
    const target = goneText.value.trim();

    if (target && (await attempt({ do: 'gone', target }))) {
        goneText.value = '';
        goneOpen.value = false;
    }
}

async function expectAddress() {
    const text = addressText.value.trim();

    if (text && (await attempt({ do: 'address', text }))) {
        addressOpen.value = false;
    }
}

function picture() {
    built.value.push({ do: 'shot' });
}

/** The last step taken away: the page is opened again at the ones before. */
async function undo() {
    built.value.pop();
    await openOn(built.value);
}

function finish() {
    emit('done', built.value, name.value.trim() || 'New check', size.value);
    open.value = false;
}

// Where the page is now, as an address bar shows it
const shownAddress = computed(() => {
    const url = page.value?.url ?? built.value[0]?.url ?? '';

    if (url.toLowerCase().startsWith('data:')) {
        return { secure: false, host: 'A page of its own', path: '' };
    }

    try {
        const parsed = new URL(url);

        return {
            secure: parsed.protocol === 'https:',
            host: parsed.host,
            path: `${parsed.pathname}${parsed.search}`.replace(/^\/$/, ''),
        };
    } catch {
        return { secure: false, host: url, path: '' };
    }
});

const MODES = [
    {
        value: 'click' as const,
        label: 'Click',
        icon: MousePointerClick,
        title: 'A click on the page clicks it there',
        test: 'build-click',
    },
    {
        value: 'hover' as const,
        label: 'Point at',
        icon: MousePointer2,
        title: 'A click on the page holds the pointer over it: a menu that opens on its own',
        test: 'build-hover',
    },
    {
        value: 'see' as const,
        label: 'Expect to see',
        icon: Eye,
        title: 'A click on the page makes a step that it must be there',
        test: 'build-see',
    },
];
const SCREENS = [
    {
        value: 'desktop' as const,
        label: 'Desktop',
        icon: Monitor,
        test: 'build-desktop',
    },
    {
        value: 'tablet' as const,
        label: 'Tablet',
        icon: Tablet,
        test: 'build-tablet',
    },
    {
        value: 'phone' as const,
        label: 'Phone',
        icon: Smartphone,
        test: 'build-phone',
    },
];
// On the page's own bar, only their pictures
const SCREEN_ICONS = SCREENS.map(({ value, icon, label, test }) => ({
    value,
    icon,
    title: label,
    test: `${test}-now`,
}));
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[96vh] w-full flex-col gap-3 p-3 sm:max-w-7xl sm:p-4"
            data-test="build-dialog"
            @open-auto-focus="started && $event.preventDefault()"
        >
            <div class="flex items-center gap-2 pr-8">
                <DialogTitle class="shrink-0 text-base"
                    >Build by clicking</DialogTitle
                >
                <Input
                    v-if="started"
                    v-model="name"
                    placeholder="What it checks"
                    aria-label="What it checks"
                    class="h-8 max-w-64 min-w-0 flex-1"
                    data-test="build-name"
                />
                <Button
                    v-if="started"
                    class="ml-auto"
                    size="sm"
                    :disabled="!!doing"
                    data-test="build-done"
                    @click="finish"
                >
                    Done: {{ built.length }}
                    {{ built.length === 1 ? 'step' : 'steps' }}
                </Button>
            </div>
            <DialogDescription
                class="-mt-2 text-xs"
                :class="{ 'sr-only': started }"
            >
                Click what a customer would click. Each click is done in the
                browser and becomes a step, and the page goes on as it would for
                them.
            </DialogDescription>

            <!-- Where it starts -->
            <form
                v-if="!started"
                class="mx-auto w-full max-w-lg space-y-4 py-6"
                data-test="build-start"
                @submit.prevent="begin"
            >
                <label class="block space-y-1.5 text-sm">
                    <span class="font-medium">What it checks</span>
                    <Input
                        v-model="name"
                        placeholder="Logging in, say"
                        data-test="build-name"
                    />
                </label>
                <label class="block space-y-1.5 text-sm">
                    <span class="font-medium">The page it starts on</span>
                    <div class="relative">
                        <Globe
                            class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                        />
                        <Input
                            v-model="address"
                            placeholder="https://"
                            class="pl-9"
                            data-test="build-address"
                        />
                    </div>
                </label>
                <div class="space-y-1.5 text-sm">
                    <span class="block font-medium"
                        >The screen it is done on</span
                    >
                    <SegmentTabs
                        v-model="size"
                        :options="SCREENS"
                        label="Screen"
                    />
                </div>
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="
                        !/^(https?:\/\/[^/\s]+|data:text\/html)/i.test(address)
                    "
                    data-test="build-open"
                >
                    Open it
                </Button>
            </form>

            <template v-else>
                <!-- What a click does, and steps of no thing on the page -->
                <div class="flex flex-wrap items-center gap-2">
                    <SegmentTabs
                        v-model="mode"
                        :options="MODES"
                        label="A click on the page"
                    />
                    <div class="bg-border hidden h-6 w-px sm:block" />
                    <div class="flex">
                        <Button
                            variant="outline"
                            size="sm"
                            class="rounded-r-none"
                            :disabled="!!doing || !pageId"
                            title="Press Enter"
                            data-test="build-enter"
                            @click="attempt({ do: 'press', key: 'Enter' })"
                        >
                            <CornerDownLeft /> Enter
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="rounded-l-none border-l-0 px-1.5"
                                    :disabled="!!doing || !pageId"
                                    aria-label="Press another key"
                                    data-test="build-key"
                                >
                                    <ChevronDown />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start">
                                <DropdownMenuItem
                                    v-for="key in KEYS"
                                    :key="key"
                                    @select="attempt({ do: 'press', key })"
                                    ><Keyboard /> Press
                                    {{ key }}</DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!!doing || !pageId"
                        title="Go back to the page before"
                        data-test="build-back"
                        @click="attempt({ do: 'back' })"
                    >
                        <ArrowLeft /> Back
                    </Button>
                    <Popover v-model:open="goneOpen">
                        <PopoverTrigger as-child>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="!!doing || !pageId"
                                title="Expect something not to be on the page: an error, a spinner"
                                data-test="build-gone"
                            >
                                <EyeOff /> Expect gone
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent class="w-72" align="start">
                            <form
                                class="space-y-2"
                                @submit.prevent="expectGone"
                            >
                                <p class="text-sm font-medium">
                                    Expect not to see
                                </p>
                                <Input
                                    v-model="goneText"
                                    placeholder="An error, “Loading…”"
                                    class="h-8"
                                    data-test="build-gone-text"
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    class="w-full"
                                    :disabled="!goneText.trim() || !!doing"
                                    >Add</Button
                                >
                            </form>
                        </PopoverContent>
                    </Popover>
                    <Popover v-model:open="addressOpen">
                        <PopoverTrigger as-child>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="!!doing || !pageId"
                                title="Expect the page's address to have some words in it"
                                data-test="build-address-check"
                            >
                                <AtSign /> Expect address
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent class="w-72" align="start">
                            <form
                                class="space-y-2"
                                @submit.prevent="expectAddress"
                            >
                                <p class="text-sm font-medium">
                                    Expect the address to have
                                </p>
                                <Input
                                    v-model="addressText"
                                    placeholder="/thank-you"
                                    class="h-8 font-mono text-xs"
                                    data-test="build-address-text"
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    class="w-full"
                                    :disabled="!addressText.trim() || !!doing"
                                    >Add</Button
                                >
                            </form>
                        </PopoverContent>
                    </Popover>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!!doing"
                        title="Take a picture of the page here"
                        data-test="build-shot"
                        @click="picture"
                    >
                        <Camera /> Picture
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="!!doing || built.length <= 1"
                        data-test="build-undo"
                        @click="undo"
                    >
                        <Undo2 /> Undo
                    </Button>
                </div>

                <!-- What to choose from a list -->
                <div
                    v-if="choosing"
                    class="space-y-2 rounded-lg border border-sky-500/40 bg-sky-500/5 p-2 text-sm"
                    data-test="build-choosing"
                >
                    <div class="flex items-center gap-2">
                        <span class="min-w-0 flex-1"
                            >Choose in
                            <strong>"{{ choosing.thing.name }}"</strong>, as a
                            customer would from its list:</span
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="choosing = null"
                            >Not this one</Button
                        >
                    </div>
                    <div
                        class="flex max-h-32 flex-wrap gap-1.5 overflow-y-auto"
                    >
                        <Button
                            v-for="option in choosing.thing.options ?? []"
                            :key="option"
                            type="button"
                            size="sm"
                            :variant="
                                option === choosing.thing.chosen
                                    ? 'secondary'
                                    : 'outline'
                            "
                            data-test="build-option"
                            @click="choose(option)"
                            >{{ option }}</Button
                        >
                    </div>
                </div>

                <!-- What to type into a field -->
                <form
                    v-if="typing"
                    class="flex flex-wrap items-center gap-2 rounded-lg border border-sky-500/40 bg-sky-500/5 p-2 text-sm"
                    data-test="build-typing"
                    @submit.prevent="addTyping"
                >
                    <span
                        >Type into <strong>"{{ typing.name }}"</strong>:</span
                    >
                    <Input
                        ref="typedInput"
                        v-model="typed"
                        class="h-8 min-w-40 flex-1"
                        :placeholder="typingHint"
                        data-test="build-typed"
                    />
                    <Loader2
                        v-if="showing"
                        class="text-muted-foreground size-4 animate-spin"
                        aria-label="Typing it on the page"
                    />
                    <Button type="submit" size="sm" data-test="build-add-typing"
                        >Add</Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="notThisOne"
                        >Not this one</Button
                    >
                </form>

                <p
                    v-if="problem"
                    class="border-destructive/30 bg-destructive/5 text-destructive rounded-lg border px-3 py-2 text-sm"
                    data-test="build-problem"
                >
                    {{ problem }}
                </p>

                <!-- The page, as in a browser -->
                <div
                    class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border"
                >
                    <div
                        class="bg-muted/50 flex items-center gap-2 border-b px-2 py-1.5"
                    >
                        <div
                            class="bg-background flex h-7 min-w-0 flex-1 items-center gap-1.5 rounded-md border px-2 text-xs"
                            data-test="build-where"
                        >
                            <Lock
                                v-if="shownAddress.secure"
                                class="size-3 shrink-0 text-emerald-600"
                            />
                            <Globe
                                v-else
                                class="text-muted-foreground size-3 shrink-0"
                            />
                            <span class="truncate"
                                ><span class="font-medium">{{
                                    shownAddress.host
                                }}</span
                                ><span class="text-muted-foreground">{{
                                    shownAddress.path
                                }}</span></span
                            >
                            <Loader2
                                v-if="doing"
                                class="text-muted-foreground ml-auto size-3 shrink-0 animate-spin"
                            />
                        </div>
                        <SegmentTabs
                            :model-value="size"
                            :options="SCREEN_ICONS"
                            label="Screen"
                            @update:model-value="resize"
                        />
                    </div>
                    <div class="relative min-h-0 flex-1 p-2">
                        <PageThings
                            v-if="page"
                            class="h-full"
                            :class="{ 'pointer-events-none opacity-50': doing }"
                            :look="page"
                            :marked="typing ?? choosing?.thing ?? null"
                            :kinds="[
                                'field',
                                'button',
                                'link',
                                'tab',
                                'checkbox',
                                'radio',
                                'picture',
                                'list',
                                'file',
                                'words',
                            ]"
                            @pick="picked"
                        />
                        <div
                            v-if="doing"
                            class="text-muted-foreground flex items-center gap-2 py-16 text-sm"
                            :class="
                                page
                                    ? 'absolute inset-x-0 top-0 justify-center'
                                    : 'justify-center'
                            "
                            data-test="build-loading"
                        >
                            <span
                                class="bg-background flex items-center gap-2 rounded-full border px-3 py-1.5 shadow-sm"
                            >
                                <Loader2 class="size-4 animate-spin" />
                                {{ doing }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- The steps so far -->
                <ol
                    class="flex max-h-24 flex-wrap gap-1.5 overflow-y-auto text-xs"
                    data-test="build-steps"
                >
                    <li
                        v-for="(step, at) in built"
                        :key="at"
                        class="flex items-center gap-1.5 rounded-full border py-0.5 pr-2.5 pl-1"
                        :class="{
                            'border-primary/40 bg-primary/5':
                                at === built.length - 1,
                        }"
                        data-test="build-step"
                    >
                        <span
                            class="flex size-5 items-center justify-center rounded-full"
                            :class="KIND_TONE[step.do]"
                        >
                            <component
                                :is="KIND_ICONS[step.do]"
                                class="size-3"
                            />
                        </span>
                        <span class="text-muted-foreground tabular-nums">{{
                            at + 1
                        }}</span>
                        {{ say(step) }}
                    </li>
                </ol>
            </template>
        </DialogContent>
    </Dialog>

    <DriveFileDialog
        v-model:open="fileOpen"
        kind="any"
        title="Which file to add"
        description="From the Drive, newest first: it is added to the page as a customer would add it."
        @pick="filed"
    />
</template>
