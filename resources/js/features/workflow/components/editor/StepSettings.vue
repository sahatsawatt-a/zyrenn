<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Braces,
    Check,
    ChevronRight,
    CircleAlert,
    Copy,
    HardDrive,
    Trash2,
    X,
} from '@lucide/vue';
import { useLocalStorage } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import NumberField from '@/components/common/NumberField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import DriveFileDialog from '@/features/chat/components/DriveFileDialog.vue';
import ModelPicker from '@/features/chat/components/ModelPicker.vue';
import NamedValues from '@/features/workflow/components/editor/NamedValues.vue';
import PickOne from '@/features/workflow/components/editor/PickOne.vue';
import { iconOf, textBox, toneOf } from '@/features/workflow/steps';
import type {
    Field,
    NodeType,
    Options,
    Setting,
    StepNode,
} from '@/features/workflow/types';
import { currentProject, owned } from '@/lib/projects';
import {
    index as ownConnections,
    models as modelsRoute,
} from '@/routes/connections';
import { page as codeAdmin } from '@/routes/admin/code';
import { index as projectConnections } from '@/routes/projects/connections';
import Tip from '@/components/common/Tip.vue';

// One step's settings, beside the canvas: its name and whatever its kind is
// set up with. What is seldom changed -- the key formulas know it by, what a
// failure does -- is under "More".

const props = defineProps<{
    step: StepNode;
    type?: NodeType;
    problems: string[];
    editable: boolean;
    // Keys of the other steps, which this one's can't be
    takenKeys: Set<string>;
    // What its settings may choose from
    options: Options;
    // The test data beside it, and whether a part of it is being dragged
    dataShown: boolean;
    dragging: boolean;
    // One of two tabs over the canvas, on a narrow screen
    compact?: boolean;
}>();

const emit = defineEmits<{
    update: [patch: Partial<StepNode>];
    duplicate: [];
    remove: [];
    close: [];
    showData: [];
}>();

// --- how wide it is: as it was last dragged, by its left edge ---
const USUAL_WIDTH = 384;
const width = useLocalStorage('workflow.settings.width', USUAL_WIDTH);

const bounded = (wide: number) =>
    Math.round(Math.max(320, Math.min(wide, 720, window.innerWidth - 360)));

function startResize(event: PointerEvent) {
    const startX = event.clientX;
    const startWidth = bounded(width.value);

    const move = (moved: PointerEvent) =>
        (width.value = bounded(startWidth + startX - moved.clientX));

    const stop = () => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', stop);
    };

    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', stop);
}

// --- its key, kept only once it is one a formula can name ---
const page = usePage();

const key = ref(props.step.key);
watch(
    () => props.step.key,
    (value) => (key.value = value),
);

const keyProblem = computed(() => {
    if (!/^[a-z][a-z0-9_]{0,39}$/.test(key.value)) {
        return 'Lowercase letters, digits and _, starting with a letter.';
    }

    return props.takenKeys.has(key.value) ? 'Another step has this key.' : '';
});

// What its kind does can run to a paragraph
const wholeDescription = ref(false);

// Seldom changed, so folded away -- unless it already says something unusual
const more = ref(props.step.onError === 'continue');

function changeKey(value: string) {
    key.value = value;

    if (!keyProblem.value) {
        emit('update', { key: value });
    }
}

// --- its settings ---
const value = (setting: Setting) => props.step.config[setting.name];

const set = (setting: Setting, next: unknown) => {
    const config = { ...props.step.config, [setting.name]: next };

    // Run code in another language: code still as it started starts again in
    // that one, and libraries of the other are no use to it
    if (setting.name === 'language' && props.step.type === 'data.code') {
        const starts =
            props.type?.config.find((one) => one.name === 'code')?.starts ?? {};
        const was = String(props.step.config.language ?? 'javascript');

        if (
            starts[String(next)] &&
            String(props.step.config.code ?? '').trim() ===
                String(starts[was] ?? '').trim()
        ) {
            config.code = starts[String(next)];
        }

        config.libraries = [];
    }

    emit('update', { config });
};

// Run code's libraries: those added for its language, and any it names that
// are gone, so they can be taken off
const libraries = computed(() => {
    const offered =
        props.options.libraries?.[
            String(props.step.config.language ?? 'javascript')
        ] ?? [];
    const chosen = Array.isArray(props.step.config.libraries)
        ? (props.step.config.libraries as string[])
        : [];

    return [
        ...offered.map((one) => ({
            ...one,
            on: chosen.includes(one.name),
            gone: false,
        })),
        ...chosen
            .filter((name) => !offered.some((one) => one.name === name))
            .map((name) => ({ name, version: null, on: true, gone: true })),
    ];
});

function toggleLibrary(setting: Setting, name: string) {
    const chosen = Array.isArray(value(setting))
        ? (value(setting) as string[])
        : [];

    set(
        setting,
        chosen.includes(name)
            ? chosen.filter((one) => one !== name)
            : [...chosen, name],
    );
}

// The setting a file is being chosen for in the Drive, while one is
const choosing = ref<Setting | null>(null);

function chosen(file: { ref_id: string }) {
    if (choosing.value) {
        set(choosing.value, file.ref_id);
    }
}

const fields = (setting: Setting): Field[] =>
    Array.isArray(value(setting)) ? (value(setting) as Field[]) : [];

// In code, Tab sets in rather than leaving the box
function indent(event: KeyboardEvent, setting: Setting) {
    const box = event.target as HTMLTextAreaElement;
    const at = box.selectionStart;

    box.setRangeText('    ', at, box.selectionEnd, 'end');
    set(setting, box.value);
}

const jsonProblem = (text: unknown) => {
    if (typeof text !== 'string' || text.trim() === '') {
        return '';
    }

    try {
        const parsed = JSON.parse(text);

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
            ? ''
            : 'A JSON object, in { }.';
    } catch {
        return 'Not valid JSON yet.';
    }
};

// --- what a setting may be chosen from ---
const connectionsPage = owned(ownConnections, projectConnections);

// What a step sends through, from a linked chat: that chat's own
const LINKED = '*linked';
// Any of a project's chats with customers (References::CUSTOMERS)
const CUSTOMERS = '*customers';
// The services a room can be linked to a chat on (ChatRoom::LINKS)
const LINKS = {
    telegram: 'Telegram',
    line: 'LINE',
    whatsapp: 'WhatsApp',
    instagram: 'Instagram',
    channel: 'a custom channel',
};

const connectionsFor = (setting: Setting) => [
    ...(setting.linked
        ? [
              {
                  value: LINKED,
                  label: `The chat's own ${setting.label.toLowerCase()}`,
                  hint: 'what it is linked through',
              },
          ]
        : []),
    ...props.options.connections
        .filter((c) =>
            (setting.services ?? []).some((service) =>
                service === 'ai' ? c.ai : c.service === service,
            ),
        )
        .map((c) => ({ value: c.ref_id, label: c.name, hint: c.label })),
];

const choicesOf = (setting: Setting) =>
    Object.entries(setting.options ?? {})
        // Python only where the admins allow it, or where a step has it already
        .filter(
            ([value]) =>
                !(
                    props.step.type === 'data.code' &&
                    setting.name === 'language' &&
                    value === 'python' &&
                    !props.options.python &&
                    props.step.config.language !== 'python'
                ),
        )
        .map(([value, label]) => ({
            value,
            label,
        }));

// The step's table, for its columns
const table = computed(() =>
    props.options.tables.find((t) => t.ref_id === props.step.config.table),
);

const columns = computed(() =>
    (table.value?.columns ?? []).map((c) => ({
        value: c.name,
        label: c.label,
    })),
);

// Shown only while the setting it depends on is so
const shown = (setting: Setting) =>
    Object.entries(setting.when ?? {}).every(([other, wanted]) => {
        const now = props.step.config[other];

        if (Array.isArray(wanted)) {
            return wanted.includes(String(now ?? ''));
        }

        return wanted === true
            ? now !== null &&
                  now !== undefined &&
                  now !== '' &&
                  now !== 'none' &&
                  !(Array.isArray(now) && now.length === 0)
            : now === wanted;
    });

// A room: one of the owner's chats, or worked out while it runs
const FORMULA = '__formula__';
const roomByFormula = ref<Record<string, boolean>>({});

// One's own chats; for a trigger, every chat linked to a service too; for a
// step, a room worked out from earlier steps instead
const roomOptions = (setting: Setting) => [
    ...(setting.linked
        ? [
              // A project's: every one of its chats with customers, whichever service
              ...(currentProject()
                  ? [{ value: CUSTOMERS, label: 'Any chat with a customer' }]
                  : []),
              ...Object.entries(LINKS).map(([service, name]) => ({
                  value: `${LINKED}:${service}`,
                  label: `Any chat linked to ${name}`,
              })),
          ]
        : []),
    ...props.options.rooms.map((r) => ({ value: r.ref_id, label: r.title })),
    ...(setting.linked
        ? []
        : [{ value: FORMULA, label: 'Worked out from earlier steps…' }]),
];

const roomMode = (setting: Setting) => {
    const now = String(value(setting) ?? '');

    return roomByFormula.value[setting.name] || now.includes('{{')
        ? FORMULA
        : now;
};

function pickRoom(setting: Setting, picked: string | null) {
    roomByFormula.value[setting.name] = picked === FORMULA;
    set(setting, picked === FORMULA ? '{{ node("").room }}' : picked);
}

// A Drive folder: one there already, by its path, or a path of one's own --
// which can be worked out, and is made the first time it is needed
const OWN_FOLDER = '__own__';
const folderTyped = ref<Record<string, boolean>>({});

const folderOptions = computed(() => [
    ...(props.options.folders ?? []).map((path) => ({
        value: path,
        label: path,
    })),
    { value: OWN_FOLDER, label: 'Another, by its path…' },
]);

const folderMode = (setting: Setting) => {
    const now = String(value(setting) ?? '');

    return folderTyped.value[setting.name] ||
        (now !== '' && !(props.options.folders ?? []).includes(now))
        ? OWN_FOLDER
        : now || null;
};

function pickFolder(setting: Setting, picked: string | null) {
    folderTyped.value[setting.name] = picked === OWN_FOLDER;

    if (picked !== OWN_FOLDER) {
        set(setting, picked);
    }
}

// When it runs: a usual time, or a cron line of one's own
const CRONS = [
    { value: '*/5 * * * *', label: 'Every 5 minutes' },
    { value: '*/15 * * * *', label: 'Every 15 minutes' },
    { value: '0 * * * *', label: 'Every hour' },
    { value: '0 9 * * *', label: 'Every day at 9:00' },
    { value: '0 9 * * 1-5', label: 'Weekdays at 9:00' },
    { value: '0 9 * * 1', label: 'Mondays at 9:00' },
    { value: '0 9 1 * *', label: 'The 1st of each month at 9:00' },
];
const OWN_CRON = '__own__';
const ownCron = ref(false);

const cronMode = (setting: Setting) =>
    ownCron.value || !CRONS.some((c) => c.value === value(setting))
        ? OWN_CRON
        : String(value(setting));

function pickCron(setting: Setting, picked: string | null) {
    ownCron.value = picked === OWN_CRON;

    if (picked && picked !== OWN_CRON) {
        set(setting, picked);
    }
}

const zones: string[] =
    typeof Intl.supportedValuesOf === 'function'
        ? Intl.supportedValuesOf('timeZone')
        : [];

// The models the step's AI connection offers, asked for once it is chosen
const models = ref<string[]>([]);
const loadingModels = ref(false);

watch(
    () => props.step.config.connection,
    async (connection) => {
        models.value = [];

        if (
            typeof connection !== 'string' ||
            !props.type?.config.some((s) => s.type === 'model')
        ) {
            return;
        }

        loadingModels.value = true;

        try {
            const response = await fetch(modelsRoute(connection).url, {
                headers: { Accept: 'application/json' },
            });

            if (response.ok) {
                models.value = (await response.json()).models;
            }
        } finally {
            loadingModels.value = false;
        }
    },
    { immediate: true },
);

const usualModel = computed(
    () =>
        props.options.connections.find(
            (c) => c.ref_id === props.step.config.connection,
        )?.name,
);

// A webhook's address, to give the service that calls it
const hookUrl = (token: unknown) =>
    `${props.options.hooks ?? '/hooks/w/'}${String(token ?? '')}`;
const copied = ref(false);

async function copyHook(token: unknown) {
    await navigator.clipboard.writeText(hookUrl(token));
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}
</script>

<template>
    <aside
        class="bg-background relative flex min-h-0 flex-col"
        :class="[
            compact ? 'flex-1' : 'shrink-0 border-l',
            { 'taking-data': dragging },
        ]"
        :style="compact ? undefined : { width: `${bounded(width)}px` }"
        data-step-settings
    >
        <Tip
            v-if="!compact"
            text="Drag to resize, double-click for its usual width"
        >
            <div
                class="hover:bg-primary active:bg-primary absolute inset-y-0 -left-px z-10 w-1 cursor-col-resize transition-colors"
                aria-label="Drag to resize, double-click for its usual width"
                data-resize-settings
                @pointerdown.prevent="startResize"
                @dblclick="width = USUAL_WIDTH"
            />
        </Tip>
        <div class="flex items-start gap-3 border-b p-4">
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                :class="toneOf(type).tile"
            >
                <component :is="iconOf(type)" class="size-4.5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium">
                    {{ type?.label ?? step.type }}
                </p>
                <!-- In two lines; all of it when asked -->
                <p
                    class="text-muted-foreground cursor-pointer text-xs leading-relaxed"
                    :class="{ 'line-clamp-2': !wholeDescription }"
                    :title="wholeDescription ? undefined : type?.description"
                    data-step-description
                    @click="wholeDescription = !wholeDescription"
                >
                    {{ type?.description }}
                </p>
            </div>
            <Tip
                v-if="!dataShown"
                text="Test data: what a run gave, to set this up from"
            >
                <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label="Show the test data"
                    data-show-test-data
                    @click="emit('showData')"
                >
                    <Braces />
                </Button>
            </Tip>
            <!-- Over the canvas, its tabs close it -->
            <Tip v-if="!compact" text="Close">
                <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label="Close"
                    @click="emit('close')"
                >
                    <X />
                </Button>
            </Tip>
        </div>

        <div class="flex-1 space-y-5 overflow-x-hidden overflow-y-auto p-4">
            <ul
                v-if="problems.length"
                class="border-destructive/30 bg-destructive/5 text-destructive space-y-1 rounded-lg border p-3 text-xs"
                data-settings-problems
            >
                <li
                    v-for="problem in problems"
                    :key="problem"
                    class="flex gap-1.5"
                >
                    <CircleAlert class="mt-px size-3.5 shrink-0" />
                    {{ problem }}
                </li>
            </ul>

            <div class="grid grid-cols-1 gap-2">
                <Label for="step-label">Name</Label>
                <Input
                    id="step-label"
                    :model-value="step.label"
                    :disabled="!editable"
                    maxlength="80"
                    @update:model-value="
                        emit('update', { label: String($event) })
                    "
                />
            </div>

            <template
                v-for="setting in (type?.config ?? []).filter(shown)"
                :key="setting.name"
            >
                <!-- Named values -->
                <NamedValues
                    v-if="setting.type === 'fields'"
                    :setting="setting"
                    :fields="fields(setting)"
                    :editable="editable"
                    :config="step.config"
                    :columns="columns"
                    :table="!!table"
                    @update="set(setting, $event)"
                />

                <!-- From a list: a dropdown -->
                <div
                    v-else-if="
                        [
                            'choice',
                            'connection',
                            'table',
                            'column',
                            'workflow',
                            'knowledge',
                            'agent',
                            'project',
                        ].includes(setting.type)
                    "
                    class="grid grid-cols-1 gap-2"
                >
                    <Label :for="`setting-${setting.name}`">{{
                        setting.label
                    }}</Label>
                    <PickOne
                        :id="`setting-${setting.name}`"
                        :model-value="value(setting) as string | null"
                        :options="
                            setting.type === 'choice'
                                ? choicesOf(setting)
                                : setting.type === 'connection'
                                  ? connectionsFor(setting)
                                  : setting.type === 'table'
                                    ? options.tables.map((t) => ({
                                          value: t.ref_id,
                                          label: t.title,
                                      }))
                                    : setting.type === 'project'
                                      ? (options.projects ?? []).map((p) => ({
                                            value: p.ref_id,
                                            label: p.name,
                                        }))
                                      : setting.type === 'workflow'
                                        ? (options.workflows ?? []).map(
                                              (w) => ({
                                                  value: w.ref_id,
                                                  label: w.title,
                                              }),
                                          )
                                        : setting.type === 'agent'
                                          ? (options.agents ?? []).map((a) => ({
                                                value: a.ref_id,
                                                label: a.title,
                                                hint: `v${a.version}${a.model ? ` · ${a.model}` : ''}`,
                                            }))
                                          : setting.type === 'knowledge'
                                            ? (options.knowledge ?? []).map(
                                                  (k) => ({
                                                      value: k.ref_id,
                                                      label: k.title,
                                                  }),
                                              )
                                            : columns
                        "
                        :none="
                            setting.required
                                ? undefined
                                : setting.type === 'column'
                                  ? 'Any row'
                                  : setting.type === 'project'
                                    ? 'This workflow’s own project'
                                    : 'None'
                        "
                        :disabled="!editable"
                        @update:model-value="set(setting, $event)"
                    />
                    <p
                        v-if="
                            setting.type === 'connection' &&
                            !connectionsFor(setting).length
                        "
                        class="text-muted-foreground text-xs"
                    >
                        None of the right kind yet.
                        <Link
                            :href="connectionsPage()"
                            class="text-primary underline-offset-2 hover:underline"
                            >Add one</Link
                        >
                    </p>
                </div>

                <!-- A chat: chosen, or worked out while it runs -->
                <div
                    v-else-if="setting.type === 'room'"
                    class="grid grid-cols-1 gap-2"
                >
                    <Label :for="`setting-${setting.name}`">{{
                        setting.label
                    }}</Label>
                    <PickOne
                        :id="`setting-${setting.name}`"
                        :model-value="roomMode(setting) || null"
                        :options="roomOptions(setting)"
                        placeholder="Choose a chat…"
                        :disabled="!editable"
                        @update:model-value="pickRoom(setting, $event)"
                    />
                    <Input
                        v-if="roomMode(setting) === FORMULA"
                        :model-value="String(value(setting) ?? '')"
                        class="font-mono text-xs"
                        placeholder='{{ node("find").room }}'
                        :disabled="!editable"
                        aria-label="Room, worked out"
                        data-takes-data="template"
                        @update:model-value="set(setting, String($event))"
                    />
                </div>

                <!-- A Drive folder: chosen, or a path written out -->
                <div
                    v-else-if="setting.type === 'folder'"
                    class="grid grid-cols-1 gap-2"
                >
                    <Label :for="`setting-${setting.name}`">{{
                        setting.label
                    }}</Label>
                    <PickOne
                        :id="`setting-${setting.name}`"
                        :model-value="folderMode(setting)"
                        :options="folderOptions"
                        :none="
                            setting.anywhere
                                ? 'Anywhere in the Drive'
                                : 'The top of the Drive'
                        "
                        :disabled="!editable"
                        @update:model-value="pickFolder(setting, $event)"
                    />
                    <Input
                        v-if="folderMode(setting) === OWN_FOLDER"
                        :model-value="String(value(setting) ?? '')"
                        class="font-mono text-xs"
                        placeholder="Receipts/{{ trigger.sender.name }}"
                        :disabled="!editable"
                        aria-label="Folder, by its path"
                        data-takes-data="template"
                        @update:model-value="set(setting, String($event))"
                    />
                    <p
                        v-if="
                            folderMode(setting) === OWN_FOLDER &&
                            !setting.anywhere
                        "
                        class="text-muted-foreground text-xs"
                    >
                        Folders not there yet are made.
                    </p>
                </div>

                <!-- When: a usual time, or a cron line -->
                <div
                    v-else-if="setting.type === 'cron'"
                    class="grid grid-cols-1 gap-2"
                >
                    <Label :for="`setting-${setting.name}`">{{
                        setting.label
                    }}</Label>
                    <PickOne
                        :id="`setting-${setting.name}`"
                        :model-value="cronMode(setting)"
                        :options="[
                            ...CRONS,
                            { value: OWN_CRON, label: 'Something else…' },
                        ]"
                        :disabled="!editable"
                        @update:model-value="pickCron(setting, $event)"
                    />
                    <Input
                        v-if="cronMode(setting) === OWN_CRON"
                        :model-value="String(value(setting) ?? '')"
                        class="font-mono text-xs"
                        placeholder="0 9 * * 1-5"
                        :disabled="!editable"
                        aria-label="Cron line"
                        @update:model-value="set(setting, String($event))"
                    />
                </div>

                <!-- A model, or a time zone: long lists, searched -->
                <div
                    v-else-if="['model', 'timezone'].includes(setting.type)"
                    class="grid grid-cols-1 gap-2"
                >
                    <Label>{{ setting.label }}</Label>
                    <ModelPicker
                        :model-value="String(value(setting) ?? '')"
                        :models="setting.type === 'model' ? models : zones"
                        :loading="setting.type === 'model' && loadingModels"
                        :none="
                            setting.type === 'model'
                                ? usualModel
                                    ? `${usualModel}'s usual model`
                                    : 'The usual model'
                                : `The app's own (${options.timezone})`
                        "
                        @update:model-value="set(setting, $event || null)"
                    />
                </div>

                <!-- A webhook's address -->
                <div
                    v-else-if="setting.type === 'token'"
                    class="grid grid-cols-1 gap-2"
                >
                    <Label>{{ setting.label }}</Label>
                    <div class="flex items-center gap-1.5">
                        <code
                            class="bg-muted/50 min-w-0 flex-1 truncate rounded-md border px-2 py-1.5 text-[11px]"
                            data-hook-url
                            >{{ hookUrl(value(setting)) }}</code
                        >
                        <Tip text="Copy the address">
                            <Button
                                size="icon-sm"
                                variant="outline"
                                aria-label="Copy the address"
                                @click="copyHook(value(setting))"
                            >
                                <Check v-if="copied" />
                                <Copy v-else />
                            </Button>
                        </Tip>
                    </div>
                    <p
                        v-if="!options.hooks"
                        class="text-xs text-amber-700 dark:text-amber-400"
                    >
                        Nothing outside can reach this app yet: set
                        WORKFLOWS_PUBLIC_URL to its public address.
                    </p>
                </div>

                <!-- A whole number -->
                <div
                    v-else-if="setting.type === 'number'"
                    class="grid grid-cols-1 gap-2"
                >
                    <Label :for="`setting-${setting.name}`">{{
                        setting.label
                    }}</Label>
                    <NumberField
                        :id="`setting-${setting.name}`"
                        :model-value="
                            value(setting) === null ||
                            value(setting) === undefined ||
                            value(setting) === ''
                                ? null
                                : Number(value(setting))
                        "
                        :label="setting.label"
                        :min="1"
                        nullable
                        compact
                        class="w-40"
                        :disabled="!editable"
                        @update:model-value="set(setting, $event)"
                    />
                </div>

                <!-- A formula, or plain text, on one line -->
                <div
                    v-else-if="['formula', 'text'].includes(setting.type)"
                    class="grid grid-cols-1 gap-2"
                >
                    <Label :for="`setting-${setting.name}`">{{
                        setting.label
                    }}</Label>
                    <Input
                        :id="`setting-${setting.name}`"
                        :model-value="String(value(setting) ?? '')"
                        :class="{ 'font-mono': setting.type === 'formula' }"
                        :data-takes-data="
                            setting.type === 'formula' ? 'formula' : undefined
                        "
                        class="text-xs"
                        :placeholder="setting.placeholder"
                        :disabled="!editable"
                        @update:model-value="set(setting, String($event))"
                    />
                </div>

                <!-- Libraries for code: those the admins added, each a switch -->
                <div
                    v-else-if="setting.type === 'libraries'"
                    class="grid grid-cols-1 gap-2"
                    data-setting-libraries
                >
                    <Label>{{ setting.label }}</Label>
                    <div v-if="libraries.length" class="flex flex-wrap gap-1.5">
                        <Tip
                            v-for="library in libraries"
                            :key="library.name"
                            :text="
                                library.gone
                                    ? 'Not there any more: take it off'
                                    : library.version
                                      ? `${library.name} ${library.version}`
                                      : library.name
                            "
                        >
                            <button
                                type="button"
                                class="inline-flex h-7 items-center gap-1 rounded-full border px-2.5 font-mono text-xs transition-colors"
                                :class="
                                    library.gone
                                        ? 'border-destructive/50 text-destructive line-through'
                                        : library.on
                                          ? 'border-primary bg-primary/10 text-primary'
                                          : 'text-muted-foreground hover:text-foreground'
                                "
                                :aria-pressed="library.on"
                                :disabled="!editable"
                                :data-library="library.name"
                                @click="toggleLibrary(setting, library.name)"
                            >
                                <Check
                                    v-if="library.on && !library.gone"
                                    class="size-3"
                                />
                                {{ library.name }}
                            </button>
                        </Tip>
                    </div>
                    <p v-else class="text-muted-foreground text-xs">
                        None added for this language yet.
                        <Link
                            v-if="page.props.auth.user?.is_admin"
                            :href="codeAdmin()"
                            class="text-primary underline-offset-2 hover:underline"
                            >Add some</Link
                        >
                        <template v-else>The app’s admins add them.</template>
                    </p>
                </div>

                <!-- Code: as it is written, its lines unbroken -->
                <div
                    v-else-if="setting.type === 'code'"
                    class="grid grid-cols-1 gap-2"
                >
                    <Label :for="`setting-${setting.name}`">{{
                        setting.label
                    }}</Label>
                    <textarea
                        :id="`setting-${setting.name}`"
                        :value="String(value(setting) ?? '')"
                        rows="14"
                        wrap="off"
                        :class="textBox"
                        class="leading-relaxed"
                        data-takes-data="formula"
                        :disabled="!editable"
                        spellcheck="false"
                        autocapitalize="off"
                        @keydown.tab.exact.prevent="indent($event, setting)"
                        @input="
                            set(
                                setting,
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                    />
                </div>

                <!-- A template, or JSON -->
                <div v-else class="grid grid-cols-1 gap-2">
                    <Label :for="`setting-${setting.name}`">{{
                        setting.label
                    }}</Label>
                    <textarea
                        :id="`setting-${setting.name}`"
                        :value="String(value(setting) ?? '')"
                        :rows="setting.type === 'json' ? 7 : 3"
                        :class="textBox"
                        data-takes-data="template"
                        :placeholder="setting.placeholder"
                        :disabled="!editable"
                        spellcheck="false"
                        @input="
                            set(
                                setting,
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                    />
                    <p
                        v-if="
                            setting.type === 'json' &&
                            jsonProblem(value(setting))
                        "
                        class="text-destructive text-xs"
                    >
                        {{ jsonProblem(value(setting)) }}
                    </p>
                    <Button
                        v-if="setting.drive && editable"
                        variant="outline"
                        size="sm"
                        class="justify-self-start"
                        :data-choose-file="setting.name"
                        @click="choosing = setting"
                    >
                        <HardDrive /> Choose from the Drive
                    </Button>
                </div>

                <p
                    v-if="setting.help"
                    class="text-muted-foreground -mt-3 text-xs leading-relaxed"
                >
                    {{ setting.help }}
                </p>
            </template>

            <div class="border-t pt-3">
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground flex w-full items-center gap-1 text-xs font-medium"
                    :aria-expanded="more"
                    data-more-settings
                    @click="more = !more"
                >
                    <ChevronRight
                        class="size-3.5 transition-transform"
                        :class="{ 'rotate-90': more }"
                    />
                    More
                    <code class="ml-auto font-normal">{{ key }}</code>
                </button>

                <div v-if="more || keyProblem" class="mt-4 space-y-5">
                    <div class="grid grid-cols-1 gap-2">
                        <Label for="step-key">Key</Label>
                        <Input
                            id="step-key"
                            :model-value="key"
                            class="font-mono text-xs"
                            :disabled="!editable"
                            maxlength="40"
                            @update:model-value="changeKey(String($event))"
                        />
                        <p v-if="keyProblem" class="text-destructive text-xs">
                            {{ keyProblem }}
                        </p>
                        <p v-else class="text-muted-foreground text-xs">
                            Later steps read its output as
                            <code class="text-foreground"
                                >node("{{ key }}")</code
                            >.
                        </p>
                    </div>

                    <div v-if="!type?.trigger" class="grid grid-cols-1 gap-2">
                        <Label for="step-on-error">If it fails</Label>
                        <PickOne
                            id="step-on-error"
                            :model-value="step.onError"
                            :options="[
                                { value: 'stop', label: 'Stop the run' },
                                {
                                    value: 'continue',
                                    label: 'Carry on, passing on what went wrong',
                                },
                            ]"
                            :disabled="!editable"
                            @update:model-value="
                                emit('update', {
                                    onError:
                                        $event === 'continue'
                                            ? 'continue'
                                            : 'stop',
                                })
                            "
                        />
                    </div>
                </div>
            </div>
        </div>

        <DriveFileDialog
            :open="choosing !== null"
            :kind="choosing?.drive"
            :title="`${choosing?.label ?? 'A file'}, from the Drive`"
            description="The newest first. Its ref_id is filled in."
            @update:open="(open) => (open ? null : (choosing = null))"
            @pick="chosen"
        />

        <div v-if="editable" class="flex gap-2 border-t p-3">
            <Button variant="outline" size="sm" @click="emit('duplicate')">
                <Copy /> Duplicate
            </Button>
            <Button
                variant="ghost"
                size="sm"
                class="text-destructive hover:text-destructive ml-auto"
                data-remove-step
                @click="emit('remove')"
            >
                <Trash2 /> Remove
            </Button>
        </div>
    </aside>
</template>

<style scoped>
/* While a part of the test data is dragged, where it may go */
.taking-data :deep([data-takes-data]:not(:disabled)) {
    outline: 1.5px dashed var(--primary);
    outline-offset: 1px;
}
</style>
