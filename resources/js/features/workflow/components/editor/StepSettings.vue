<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Braces,
    Check,
    ChevronRight,
    CircleAlert,
    Copy,
    Plus,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ModelPicker from '@/features/chat/components/ModelPicker.vue';
import PickOne from '@/features/workflow/components/editor/PickOne.vue';
import { iconOf, toneOf } from '@/features/workflow/steps';
import type {
    Field,
    NodeType,
    Options,
    Setting,
    StepNode,
} from '@/features/workflow/types';
import { owned } from '@/lib/projects';
import {
    index as ownConnections,
    models as modelsRoute,
} from '@/routes/connections';
import { index as projectConnections } from '@/routes/projects/connections';

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

const textarea =
    'border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border px-3 py-2 font-mono text-xs shadow-xs outline-none focus-visible:ring-[3px] disabled:opacity-60';

// --- its key, kept only once it is one a formula can name ---
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

const set = (setting: Setting, next: unknown) =>
    emit('update', { config: { ...props.step.config, [setting.name]: next } });

const fields = (setting: Setting): Field[] =>
    Array.isArray(value(setting)) ? (value(setting) as Field[]) : [];

function changeField(setting: Setting, index: number, patch: Partial<Field>) {
    set(
        setting,
        fields(setting).map((field, at) =>
            at === index ? { ...field, ...patch } : field,
        ),
    );
}

const addField = (setting: Setting) =>
    set(setting, [...fields(setting), { name: '', value: '' }]);

const removeField = (setting: Setting, index: number) =>
    set(
        setting,
        fields(setting).filter((_, at) => at !== index),
    );

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
    Object.entries(setting.options ?? {}).map(([value, label]) => ({
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
              {
                  value: `${LINKED}:telegram`,
                  label: 'Any chat linked to Telegram',
              },
              { value: `${LINKED}:line`, label: 'Any chat linked to LINE' },
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
        class="bg-background flex min-h-0 flex-col"
        :class="[
            compact ? 'flex-1' : 'w-80 shrink-0 border-l',
            { 'taking-data': dragging },
        ]"
        data-step-settings
    >
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
            <Button
                v-if="!dataShown"
                size="icon-sm"
                variant="ghost"
                aria-label="Show the test data"
                title="Test data: what a run gave, to set this up from"
                data-show-test-data
                @click="emit('showData')"
            >
                <Braces />
            </Button>
            <!-- Over the canvas, its tabs close it -->
            <Button
                v-if="!compact"
                size="icon-sm"
                variant="ghost"
                aria-label="Close"
                @click="emit('close')"
            >
                <X />
            </Button>
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
                <div
                    v-if="setting.type === 'fields'"
                    class="grid grid-cols-1 gap-2"
                >
                    <Label>{{ setting.label }}</Label>
                    <div
                        v-for="(field, index) in fields(setting)"
                        :key="index"
                        class="flex items-start gap-1.5"
                        data-field-row
                    >
                        <div
                            v-if="setting.names === 'columns'"
                            class="w-32 shrink-0"
                        >
                            <PickOne
                                :model-value="field.name"
                                :options="columns"
                                placeholder="Column"
                                :disabled="!editable || !table"
                                @update:model-value="
                                    changeField(setting, index, {
                                        name: $event ?? '',
                                    })
                                "
                            />
                        </div>
                        <Input
                            v-else
                            :model-value="field.name"
                            :placeholder="
                                setting.ways
                                    ? 'way'
                                    : setting.labels
                                      ? 'what it says'
                                      : 'name'
                            "
                            class="h-8 w-28 shrink-0 text-xs"
                            :class="{ 'font-mono': !setting.labels }"
                            :disabled="!editable"
                            aria-label="Name"
                            @update:model-value="
                                changeField(setting, index, {
                                    name: String($event),
                                })
                            "
                        />
                        <Input
                            :model-value="field.value"
                            :placeholder="
                                setting.ways
                                    ? 'input.total > 100'
                                    : setting.labels
                                      ? 'what it does'
                                      : '{{ input.total }}'
                            "
                            class="h-8 min-w-0 flex-1 font-mono text-xs"
                            :disabled="!editable"
                            :aria-label="setting.ways ? 'When' : 'Value'"
                            :data-takes-data="
                                setting.ways ? 'formula' : 'template'
                            "
                            @update:model-value="
                                changeField(setting, index, {
                                    value: String($event),
                                })
                            "
                        />
                        <Button
                            v-if="editable"
                            size="icon-sm"
                            variant="ghost"
                            aria-label="Remove value"
                            @click="removeField(setting, index)"
                        >
                            <X />
                        </Button>
                    </div>
                    <p
                        v-if="setting.names === 'columns' && !table"
                        class="text-muted-foreground text-xs"
                    >
                        Choose the table first.
                    </p>
                    <Button
                        v-if="editable"
                        variant="outline"
                        size="sm"
                        class="justify-self-start"
                        data-add-field
                        @click="addField(setting)"
                    >
                        <Plus />
                        {{
                            setting.ways
                                ? 'Add a way'
                                : setting.labels
                                  ? 'Add one'
                                  : 'Add a value'
                        }}
                    </Button>
                    <p
                        v-if="setting.ways && fields(setting).length"
                        class="text-muted-foreground text-xs"
                    >
                        Then “otherwise”, when none of these is true.
                    </p>
                </div>

                <!-- From a list: a dropdown -->
                <div
                    v-else-if="
                        [
                            'choice',
                            'connection',
                            'table',
                            'column',
                            'workflow',
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
                                    : setting.type === 'workflow'
                                      ? (options.workflows ?? []).map((w) => ({
                                            value: w.ref_id,
                                            label: w.title,
                                        }))
                                      : columns
                        "
                        :none="
                            setting.required
                                ? undefined
                                : setting.type === 'column'
                                  ? 'Any row'
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
                        <Button
                            size="icon-sm"
                            variant="outline"
                            aria-label="Copy the address"
                            @click="copyHook(value(setting))"
                        >
                            <Check v-if="copied" />
                            <Copy v-else />
                        </Button>
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
                    <Input
                        :id="`setting-${setting.name}`"
                        type="number"
                        min="1"
                        :model-value="String(value(setting) ?? '')"
                        class="text-xs"
                        :disabled="!editable"
                        @update:model-value="
                            set(setting, $event === '' ? null : Number($event))
                        "
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

                <!-- A template, or JSON -->
                <div v-else class="grid grid-cols-1 gap-2">
                    <Label :for="`setting-${setting.name}`">{{
                        setting.label
                    }}</Label>
                    <textarea
                        :id="`setting-${setting.name}`"
                        :value="String(value(setting) ?? '')"
                        :rows="setting.type === 'json' ? 7 : 3"
                        :class="textarea"
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
