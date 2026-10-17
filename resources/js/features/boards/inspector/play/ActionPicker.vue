<script setup lang="ts">
/* oxlint-disable unicorn/no-thenable -- an Action's `if` has a `then`, as the
   contract (features/prototypes/interactions.ts) names it; nothing awaits one */
import { Check, ChevronDown, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import PickOrAdd from '@/components/common/PickOrAdd.vue';
import Tip from '@/components/common/Tip.vue';
import { Input } from '@/components/ui/input';
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
import type {
    Action,
    OverlayPlace,
    Transition,
    Value,
} from '@/features/prototypes/interactions';
import { OVERLAY_PLACES, TRANSITIONS } from '../../model/links';
import type { ProjectTable } from './tables';
import { tableOf } from './tables';

// One thing that happens in Play, picked as a person says it: go to a
// screen, open one over this, go back, close what is over, show, hide or
// flip things here, set a value, do one thing or another by a value, or
// several in a row. What each needs is asked under it; an "if" and "several"
// hold actions of their own, picked the same way.
const props = withDefaults(
    defineProps<{
        modelValue: Action | null;
        // The prototype's screens, by id and what each is called
        screens: { id: string; title: string }[];
        // What is on this screen, to show, hide or flip
        things: { id: string; name: string }[];
        // The values already named on the board: fields and what is set
        names: string[];
        // The project's tables, to save a row to or delete one from
        tables?: ProjectTable[];
        // Inside an "if" or "several": how many deep, to keep it to two
        depth?: number;
        // Where its controls are found by tests: "tap", "tap-then"
        test: string;
    }>(),
    { depth: 0, tables: () => [] },
);

const emit = defineEmits<{ 'update:modelValue': [Action | null] }>();

type Kind =
    | 'none'
    | 'to'
    | 'overlay'
    | 'back'
    | 'close'
    | 'show'
    | 'hide'
    | 'toggle'
    | 'set'
    | 'if'
    | 'all'
    | 'save'
    | 'remove';

const KINDS: { value: Kind; label: string; deep?: boolean }[] = [
    { value: 'none', label: 'Nothing' },
    { value: 'to', label: 'Go to' },
    { value: 'overlay', label: 'Open over' },
    { value: 'back', label: 'Go back' },
    { value: 'close', label: 'Close overlay' },
    { value: 'show', label: 'Show' },
    { value: 'hide', label: 'Hide' },
    { value: 'toggle', label: 'Show/hide' },
    { value: 'set', label: 'Set a value' },
    { value: 'save', label: 'Save to a table' },
    { value: 'remove', label: 'Delete the row' },
    { value: 'if', label: 'If…', deep: true },
    { value: 'all', label: 'Several', deep: true },
];

const TRANSITION_WORDS: Record<Transition, string> = {
    slide: 'Slide',
    push: 'Push',
    fade: 'Fade',
    up: 'Up',
    none: 'At once',
};

const PLACE_WORDS: Record<OverlayPlace, string> = {
    center: 'In the middle',
    bottom: 'At the foot',
    top: 'At the top',
    left: 'At the left',
    right: 'At the right',
};

// An "if" or "several" two deep is as far as the panel goes
const kinds = computed(() =>
    KINDS.filter((kind) => !kind.deep || props.depth < 2),
);

const action = computed(() => props.modelValue);

const kind = computed<Kind>(() => {
    const now = action.value;

    if (!now) {
        return 'none';
    }

    if ('to' in now) {
        return now.overlay ? 'overlay' : 'to';
    }

    for (const each of [
        'back',
        'close',
        'show',
        'hide',
        'toggle',
        'set',
        'if',
        'all',
        'save',
        'remove',
    ] as const) {
        if (each in now) {
            return each;
        }
    }

    return 'none';
});

const set = (next: Action | null) => emit('update:modelValue', next);

// How a move looks, slide when it says nothing
const transition = computed(
    () =>
        (action.value as { transition?: Transition } | null)?.transition ??
        'slide',
);

/** What a new action of a kind starts as: the first of what it needs. */
const fresh = (next: Kind): Action | null => {
    const screen = props.screens[0]?.id;
    const thing = props.things[0]?.id;
    const name = props.names[0] ?? 'value';

    switch (next) {
        case 'to':
            return screen ? { to: screen } : null;
        case 'overlay':
            return screen ? { to: screen, overlay: 'center' } : null;
        case 'back':
            return { back: true };
        case 'close':
            return { close: true };
        case 'show':
        case 'hide':
        case 'toggle':
            return thing ? ({ [next]: [thing] } as Action) : null;
        case 'set':
            return { set: name, value: true };
        case 'if':
            return { if: name, then: { back: true } };
        case 'all':
            return { all: [{ back: true }] };
        case 'save':
            return props.tables[0] ? { save: props.tables[0].ref_id } : null;
        case 'remove':
            return props.tables[0] ? { remove: props.tables[0].ref_id } : null;
        default:
            return null;
    }
};

const pickKind = (next: unknown) => set(fresh(next as Kind));

// Which kinds cannot be had here, and why: no screens, nothing to show
const unavailable = (each: Kind) =>
    ((each === 'to' || each === 'overlay') && !props.screens.length) ||
    ((each === 'show' || each === 'hide' || each === 'toggle') &&
        !props.things.length) ||
    ((each === 'save' || each === 'remove') && !props.tables.length);

const patch = (fields: Record<string, unknown>) =>
    set({ ...(action.value as object), ...fields } as Action);

const without = (field: string) => {
    const copy = { ...(action.value as Record<string, unknown>) };
    delete copy[field];
    set(copy as Action);
};

// --------------------------------------------------- show, hide, flip
const chosen = computed<string[]>(() => {
    const now = action.value as Record<string, unknown> | null;

    return now && Array.isArray(now[kind.value])
        ? (now[kind.value] as string[])
        : [];
});

const thingsSaid = computed(() => {
    const names = chosen.value.map(
        (id) => props.things.find((thing) => thing.id === id)?.name ?? '?',
    );

    return names.length === 1 ? names[0] : `${names.length} things`;
});

const flipThing = (id: string) => {
    const next = chosen.value.includes(id)
        ? chosen.value.filter((each) => each !== id)
        : [...chosen.value, id];

    // The last one taken off leaves nothing to do
    set(next.length ? ({ [kind.value]: next } as Action) : null);
};

// ------------------------------------------------------------ set, if
const valueKind = (value: Value | undefined) =>
    value === true ? 'on' : value === false ? 'off' : 'text';

const setValue = (which: unknown) =>
    patch({
        value: which === 'on' ? true : which === 'off' ? false : '',
    });

const ifTest = computed(() =>
    action.value && 'if' in action.value && 'equals' in action.value
        ? 'equals'
        : 'set',
);

const pickIfTest = (which: unknown) =>
    which === 'equals' ? patch({ equals: '' }) : without('equals');

// ----------------------------------------------------- save to a table
const savedTo = computed(() => {
    const now = action.value as Record<string, unknown> | null;
    const ref = (now?.save ?? now?.remove) as string | undefined;

    return tableOf(props.tables, ref);
});

const saved = computed<[string, string][]>(() =>
    action.value && 'save' in action.value
        ? Object.entries(action.value.values ?? {})
        : [],
);

const setSaved = (next: [string, string][]) => {
    const values = Object.fromEntries(
        next.filter(([column]) => column),
    ) as Record<string, string>;

    if (Object.keys(values).length) {
        patch({ values });
    } else {
        without('values');
    }
};

// A column not yet said, to say next
const unsaid = computed(
    () =>
        savedTo.value?.columns.find(
            (column) =>
                column.label !== 'ID' &&
                !saved.value.some(([said]) => said === column.label),
        )?.label,
);

// The columns a row is saved in: the row's own number is not one of them
const savable = computed(
    () =>
        savedTo.value?.columns.filter((column) => column.label !== 'ID') ?? [],
);

// What words may say, each once: a field or a value, or a column of the row
// in hand -- offered under the words being written, not under every one
const chips = computed(() => [
    ...new Set([
        ...props.names,
        ...savable.value.map((column) => column.label),
    ]),
]);
const writing = ref<number | null>(null);

const addChip = (at: number, name: string) =>
    setSaved(
        saved.value.map(([column, words], index) => [
            column,
            index === at ? `${words}{${name}}` : words,
        ]),
    );

// ------------------------------------------------------------ several
const steps = computed<Action[]>(() =>
    action.value && 'all' in action.value ? action.value.all : [],
);

const setStep = (at: number, step: Action | null) => {
    const next = steps.value
        .map((each, index) => (index === at ? step : each))
        .filter((each): each is Action => !!each);

    set(next.length ? { all: next } : null);
};

const addStep = () => set({ all: [...steps.value, { back: true }] });
</script>

<template>
    <div class="action-picker" :data-depth="depth">
        <Select :model-value="kind" @update:model-value="pickKind">
            <SelectTrigger
                size="sm"
                class="h-8 w-full text-xs"
                :aria-label="`What it does`"
                :data-test="`${test}-kind`"
                :data-value="kind"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="each in kinds"
                    :key="each.value"
                    :value="each.value"
                    :disabled="unavailable(each.value)"
                    class="text-xs"
                    :data-test="`${test}-kind-${each.value}`"
                    >{{ each.label }}</SelectItem
                >
            </SelectContent>
        </Select>

        <!-- Go to, open over: which screen, and how -->
        <div v-if="action && 'to' in action" class="action-details">
            <Select
                :model-value="action.to"
                @update:model-value="patch({ to: String($event) })"
            >
                <SelectTrigger
                    size="sm"
                    class="h-8 w-full text-xs"
                    aria-label="Which screen"
                    :data-test="`${test}-screen`"
                    :data-value="action.to"
                >
                    <SelectValue placeholder="A screen" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="each in screens"
                        :key="each.id"
                        :value="each.id"
                        class="text-xs"
                        :data-test="`${test}-screen-${each.id}`"
                        >{{ each.title }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select
                v-if="action.overlay"
                :model-value="action.overlay"
                @update:model-value="patch({ overlay: $event })"
            >
                <SelectTrigger
                    size="sm"
                    class="h-8 w-full text-xs"
                    aria-label="Where it opens"
                    :data-test="`${test}-place`"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="place in OVERLAY_PLACES"
                        :key="place"
                        :value="place"
                        class="text-xs"
                        >{{ PLACE_WORDS[place] }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </div>

        <!-- Go to, back: how the move looks -->
        <div
            v-if="action && (kind === 'to' || kind === 'back')"
            class="action-details"
        >
            <Select
                :model-value="transition"
                @update:model-value="
                    $event === 'slide'
                        ? without('transition')
                        : patch({ transition: $event })
                "
            >
                <SelectTrigger
                    size="sm"
                    class="h-8 w-full text-xs"
                    aria-label="How it moves"
                    :data-test="`${test}-transition`"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="transition in TRANSITIONS"
                        :key="transition"
                        :value="transition"
                        class="text-xs"
                        >{{ TRANSITION_WORDS[transition] }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </div>

        <!-- Show, hide, flip: which of what is on this screen -->
        <Popover v-if="kind === 'show' || kind === 'hide' || kind === 'toggle'">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="action-details action-things"
                    :data-test="`${test}-things`"
                >
                    <span class="truncate">{{ thingsSaid }}</span>
                    <ChevronDown class="size-3.5 shrink-0 opacity-60" />
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                class="max-h-72 w-56 overflow-y-auto p-1"
            >
                <button
                    v-for="thing in things"
                    :key="thing.id"
                    type="button"
                    class="action-thing"
                    :data-test="`${test}-thing-${thing.id}`"
                    @click="flipThing(thing.id)"
                >
                    <Check
                        class="size-3.5 shrink-0"
                        :class="
                            chosen.includes(thing.id)
                                ? 'opacity-100'
                                : 'opacity-0'
                        "
                    />
                    <span class="truncate">{{ thing.name }}</span>
                </button>
            </PopoverContent>
        </Popover>

        <!-- Set: a name, and on, off or words -->
        <div v-if="action && 'set' in action" class="action-details">
            <PickOrAdd
                :model-value="action.set"
                :options="names"
                label="Value"
                placeholder="A name"
                add-label="Name it"
                :max-length="40"
                class="h-8 text-xs"
                :data-test="`${test}-name`"
                @update:model-value="$event && patch({ set: $event })"
            />
            <Select
                :model-value="valueKind(action.value)"
                @update:model-value="setValue"
            >
                <SelectTrigger
                    size="sm"
                    class="h-8 w-full text-xs"
                    aria-label="To what"
                    :data-test="`${test}-value-kind`"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="on" class="text-xs">to on</SelectItem>
                    <SelectItem value="off" class="text-xs">to off</SelectItem>
                    <SelectItem value="text" class="text-xs"
                        >to words…</SelectItem
                    >
                </SelectContent>
            </Select>
            <Input
                v-if="typeof action.value === 'string'"
                :model-value="action.value"
                class="h-8 text-xs"
                placeholder="The words"
                aria-label="The words"
                :data-test="`${test}-value`"
                @change="
                    patch({ value: ($event.target as HTMLInputElement).value })
                "
            />
        </div>

        <!-- Save to a table, or delete its current row: which table, and
             for a save, a new row or the current one, and what goes in it -->
        <div v-if="kind === 'save' || kind === 'remove'" class="action-details">
            <Select
                :model-value="savedTo?.ref_id"
                @update:model-value="
                    patch(
                        kind === 'save'
                            ? { save: String($event) }
                            : { remove: String($event) },
                    )
                "
            >
                <SelectTrigger
                    size="sm"
                    class="h-8 w-full text-xs"
                    aria-label="Which table"
                    :data-test="`${test}-table`"
                    :data-value="savedTo?.ref_id ?? ''"
                >
                    <SelectValue placeholder="A table" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="table in tables"
                        :key="table.ref_id"
                        :value="table.ref_id"
                        class="text-xs"
                        :data-test="`${test}-table-${table.ref_id}`"
                        >{{ table.title }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <template v-if="action && 'save' in action">
                <Select
                    :model-value="action.row ?? 'new'"
                    @update:model-value="
                        $event === 'current'
                            ? patch({ row: 'current' })
                            : without('row')
                    "
                >
                    <SelectTrigger
                        size="sm"
                        class="h-8 w-full text-xs"
                        aria-label="Which row"
                        :data-test="`${test}-row`"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="new" class="text-xs"
                            >as a new row</SelectItem
                        >
                        <SelectItem value="current" class="text-xs"
                            >over the current row</SelectItem
                        >
                    </SelectContent>
                </Select>
                <div
                    v-for="([column, words], at) in saved"
                    :key="at"
                    class="action-saved"
                >
                    <div class="action-step">
                        <Select
                            :model-value="column"
                            @update:model-value="
                                setSaved(
                                    saved.map(([was, said], index) => [
                                        index === at ? String($event) : was,
                                        said,
                                    ]),
                                )
                            "
                        >
                            <SelectTrigger
                                size="sm"
                                class="h-8 min-w-0 flex-1 text-xs"
                                aria-label="Column"
                                :data-test="`${test}-column-${at}`"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="each in savable"
                                    :key="each.label"
                                    :value="each.label"
                                    class="text-xs"
                                    >{{ each.label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <Tip text="Not this column">
                            <button
                                type="button"
                                class="action-remove"
                                aria-label="Not this column"
                                @click="
                                    setSaved(
                                        saved.filter(
                                            (_, index) => index !== at,
                                        ),
                                    )
                                "
                            >
                                <X class="size-3.5" />
                            </button>
                        </Tip>
                    </div>
                    <Input
                        :model-value="words"
                        class="h-8 text-xs"
                        placeholder="Words, or {a name}"
                        :aria-label="`What goes in ${column}`"
                        :data-test="`${test}-words-${at}`"
                        @focus="writing = at"
                        @change="
                            setSaved(
                                saved.map(([was, said], index) => [
                                    was,
                                    index === at
                                        ? ($event.target as HTMLInputElement)
                                              .value
                                        : said,
                                ]),
                            )
                        "
                    />
                    <div
                        v-if="chips.length && writing === at"
                        class="action-chips"
                    >
                        <button
                            v-for="name in chips"
                            :key="name"
                            type="button"
                            class="action-chip"
                            :data-test="`${test}-chip-${name}`"
                            @click="addChip(at, name)"
                        >
                            {{ '{' + name + '}' }}
                        </button>
                    </div>
                </div>
                <button
                    v-if="unsaid"
                    type="button"
                    class="action-add"
                    :data-test="`${test}-add-column`"
                    @click="setSaved([...saved, [unsaid, '']])"
                >
                    <Plus class="size-3.5" /> A column
                </button>
                <p class="action-word">
                    Columns left out take the fields named like them.
                </p>
            </template>
        </div>

        <!-- If: a value, then one thing, otherwise another -->
        <div v-if="action && 'if' in action" class="action-details">
            <PickOrAdd
                :model-value="action.if"
                :options="names"
                label="Value"
                placeholder="A name"
                add-label="Name it"
                :max-length="40"
                class="h-8 text-xs"
                :data-test="`${test}-if`"
                @update:model-value="$event && patch({ if: $event })"
            />
            <Select :model-value="ifTest" @update:model-value="pickIfTest">
                <SelectTrigger
                    size="sm"
                    class="h-8 w-full text-xs"
                    aria-label="Is"
                    :data-test="`${test}-if-test`"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="set" class="text-xs">is on</SelectItem>
                    <SelectItem value="equals" class="text-xs"
                        >is exactly…</SelectItem
                    >
                </SelectContent>
            </Select>
            <Input
                v-if="'equals' in action"
                :model-value="String(action.equals ?? '')"
                class="h-8 text-xs"
                placeholder="What it is"
                aria-label="What it is"
                :data-test="`${test}-equals`"
                @change="
                    patch({ equals: ($event.target as HTMLInputElement).value })
                "
            />
            <p class="action-word">then</p>
            <ActionPicker
                :model-value="action.then"
                :screens="screens"
                :things="things"
                :names="names"
                :tables="tables"
                :depth="depth + 1"
                :test="`${test}-then`"
                @update:model-value="
                    $event ? patch({ then: $event }) : set(null)
                "
            />
            <template v-if="action.else">
                <p class="action-word">otherwise</p>
                <ActionPicker
                    :model-value="action.else"
                    :screens="screens"
                    :things="things"
                    :names="names"
                    :tables="tables"
                    :depth="depth + 1"
                    :test="`${test}-else`"
                    @update:model-value="
                        $event ? patch({ else: $event }) : without('else')
                    "
                />
            </template>
            <button
                v-else
                type="button"
                class="action-add"
                :data-test="`${test}-add-else`"
                @click="patch({ else: { back: true } })"
            >
                <Plus class="size-3.5" /> Otherwise
            </button>
        </div>

        <!-- Several: each step in turn -->
        <div v-if="kind === 'all'" class="action-details">
            <div v-for="(step, at) in steps" :key="at" class="action-step">
                <ActionPicker
                    class="min-w-0 flex-1"
                    :model-value="step"
                    :screens="screens"
                    :things="things"
                    :names="names"
                    :tables="tables"
                    :depth="depth + 1"
                    :test="`${test}-step-${at}`"
                    @update:model-value="setStep(at, $event)"
                />
                <Tip text="Take this step out">
                    <button
                        type="button"
                        class="action-remove"
                        aria-label="Take this step out"
                        @click="setStep(at, null)"
                    >
                        <X class="size-3.5" />
                    </button>
                </Tip>
            </div>
            <button
                type="button"
                class="action-add"
                :data-test="`${test}-add-step`"
                @click="addStep"
            >
                <Plus class="size-3.5" /> Another step
            </button>
        </div>
    </div>
</template>

<style scoped>
.action-picker {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 0.25rem;
}

/* What a kind needs, under it and set in a little */
.action-details {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin-left: 0.5rem;
    padding-left: 0.5rem;
    border-left: 1px solid var(--border);
}

.action-things {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    height: 2rem;
    padding: 0 0.5rem;
    font-size: 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
}

.action-thing {
    display: flex;
    width: 100%;
    align-items: center;
    gap: 0.5rem;
    padding: 0.375rem 0.5rem;
    font-size: 0.75rem;
    text-align: left;
    border-radius: var(--radius-sm);
}

.action-thing:hover {
    background-color: var(--muted);
}

.action-word {
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.action-saved {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px dashed var(--border);
}

.action-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
}

.action-chip {
    padding: 0 0.375rem;
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 0.625rem;
    line-height: 1.25rem;
    color: var(--muted-foreground);
    background-color: var(--muted);
    border-radius: 999px;
}

.action-chip:hover {
    color: var(--foreground);
}

.action-step {
    display: flex;
    align-items: flex-start;
    gap: 0.25rem;
}

.action-add,
.action-remove {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.75rem;
    color: var(--muted-foreground);
    border-radius: var(--radius-sm);
}

.action-add {
    align-self: flex-start;
    padding: 0.125rem 0.25rem;
}

.action-remove {
    justify-content: center;
    width: 1.75rem;
    height: 2rem;
}

.action-add:hover,
.action-remove:hover {
    color: var(--foreground);
    background-color: var(--muted);
}
</style>
