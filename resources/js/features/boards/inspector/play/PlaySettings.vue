<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import FoldSection from '@/components/common/FoldSection.vue';
import PickOrAdd from '@/components/common/PickOrAdd.vue';
import Tip from '@/components/common/Tip.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import type {
    Action,
    ItemOn,
    Trigger,
} from '@/features/prototypes/interactions';
import type { Item } from '../../model/items';
import { nameOf } from '../../model/items';
import { groupKeys } from '../../model/layers';
import {
    TRIGGERS,
    TRIGGER_WORDS,
    linkOf,
    repeatOf,
    rowCardOf,
    valueNames,
    whenOf,
} from '../../model/links';
import ActionPicker from './ActionPicker.vue';
import RepeatSettings from './RepeatSettings.vue';
import type { ProjectTable } from './tables';
import { tableOf } from './tables';

// What what is selected does in Play: a tap first, then any other trigger
// asked for -- a long press, a swipe, hover -- each an action. A text field
// is named for what is typed into it, a radio or a tab for the choice it is
// one of; and, folded away, kept in place while the screen scrolls, or
// hidden until something shows it. Set on every selected item at once.
const props = withDefaults(
    defineProps<{
        selection: Item[];
        // Every item on the board, for its screens, what is on the same
        // screen, and the values already named
        items: Item[];
        // The project's tables: a card repeated for each row, a row saved
        tables?: ProjectTable[];
    }>(),
    { tables: () => [] },
);

const emit = defineEmits<{ update: [patch: Partial<Item>] }>();

const first = computed(() => props.selection[0]);

const screens = computed(() =>
    props.items
        .filter((item) => item.kind === 'frame')
        .map((frame, at) => ({
            id: frame.id,
            title: frame.text.trim().split('\n')[0] || `Screen ${at + 1}`,
        })),
);

// What is on the same screen as the first selected item
const things = computed(() => {
    const keys = groupKeys(props.items);
    const home = keys.get(first.value.id);

    return props.items
        .filter(
            (item) =>
                item.kind !== 'frame' &&
                item.kind !== 'arrow' &&
                keys.get(item.id) === home,
        )
        .map((item) => ({ id: item.id, name: nameOf(item) }));
});

const names = computed(() => valueNames(props.items));

const tap = computed(() => linkOf(first.value));
const when = computed(() => whenOf(first.value));

const setWhen = (trigger: Trigger, action: Action | null) => {
    const next: ItemOn = { ...when.value };

    if (action) {
        next[trigger] = action;
    } else {
        delete next[trigger];
    }

    emit('update', { when: Object.keys(next).length ? next : null });
};

const unused = computed(() =>
    TRIGGERS.filter((trigger) => !(trigger in when.value)),
);

// A trigger asked for starts by going back: something to change, not nothing
const addTrigger = (trigger: Trigger) => setWhen(trigger, { back: true });

const typed = computed(() =>
    ['text_field', 'search_field'].includes(first.value.kind),
);
const chosen = computed(() => ['radio', 'tab'].includes(first.value.kind));

// A kit switch, box, radio or tab: on or off, and with nothing else to do,
// flipped by a tap in Play
const switching = computed(() =>
    ['toggle', 'checkbox', 'radio', 'tab'].includes(first.value.kind),
);

// A card -- a rectangle, a kit card or list row, a note -- can be made a row
// of a table; anything already one keeps its settings in reach
const ROW_CARDS = ['rect', 'ui_card', 'list_row', 'sticky'];
const repeats = computed(
    () =>
        props.selection.length === 1 &&
        (ROW_CARDS.includes(first.value.kind) || !!repeatOf(first.value)),
);

// Inside a row card -- or the card itself -- words may say a column
const rowTable = computed(() => {
    const card = repeatOf(first.value)
        ? first.value
        : rowCardOf(first.value, props.items);

    return card ? tableOf(props.tables, repeatOf(card)?.table) : null;
});

// "{Title} {Status}": what a row's words may say
const rowColumns = computed(
    () =>
        rowTable.value?.columns
            .filter((column) => column.label !== 'ID')
            .map((column) => '{' + column.label + '}')
            .join(' ') ?? '',
);

const moreOpen = ref(false);
const more = computed(() => {
    const said = [
        first.value.fixed ? `kept at the ${first.value.fixed}` : '',
        first.value.startsHidden ? 'hidden at first' : '',
    ].filter(Boolean);

    return said.join(', ') || undefined;
});
</script>

<template>
    <div class="inspector-align" data-test="play-config">
        <p class="inspector-label">In Play</p>

        <div class="play-trigger">
            <span class="play-trigger-name">Tap</span>
            <ActionPicker
                class="min-w-0 flex-1"
                :model-value="tap"
                :screens="screens"
                :things="things"
                :names="names"
                :tables="tables"
                test="tap"
                @update:model-value="emit('update', { link: $event })"
            />
        </div>

        <p v-if="switching && !tap" class="play-said">
            A tap turns it on or off.
        </p>

        <div
            v-for="(action, trigger) in when"
            :key="trigger"
            class="play-trigger"
        >
            <span class="play-trigger-name">{{ TRIGGER_WORDS[trigger] }}</span>
            <ActionPicker
                class="min-w-0 flex-1"
                :model-value="action!"
                :screens="screens"
                :things="things"
                :names="names"
                :tables="tables"
                :test="trigger"
                @update:model-value="setWhen(trigger, $event)"
            />
            <Tip :text="`No ${TRIGGER_WORDS[trigger].toLowerCase()}`">
                <button
                    type="button"
                    class="play-remove"
                    :aria-label="`No ${TRIGGER_WORDS[trigger].toLowerCase()}`"
                    :data-test="`${trigger}-remove`"
                    @click="setWhen(trigger, null)"
                >
                    <X class="size-3.5" />
                </button>
            </Tip>
        </div>

        <DropdownMenu v-if="unused.length">
            <DropdownMenuTrigger as-child>
                <button type="button" class="play-add" data-test="add-trigger">
                    <Plus class="size-3.5" /> Another trigger
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-44">
                <DropdownMenuItem
                    v-for="trigger in unused"
                    :key="trigger"
                    class="text-xs"
                    :data-test="`add-trigger-${trigger}`"
                    @select="addTrigger(trigger)"
                    >{{ TRIGGER_WORDS[trigger] }}</DropdownMenuItem
                >
            </DropdownMenuContent>
        </DropdownMenu>

        <!-- A card made a row: shown once for each row of a table -->
        <RepeatSettings
            v-if="repeats"
            :card="first"
            :tables="tables"
            @update="emit('update', $event)"
        />
        <p
            v-if="rowTable && !repeatOf(first)"
            class="play-row"
            data-test="row-columns"
        >
            In a row of {{ rowTable.title }}: its words may say
            <span class="font-mono">{{ rowColumns }}</span>
        </p>

        <!-- A text field: typed into in Play, kept by a name {name} shows -->
        <label v-if="typed" class="play-field">
            Typed in as
            <PickOrAdd
                :model-value="first.field ?? ''"
                :options="names"
                label="Name for what is typed"
                placeholder="Not typed into"
                add-label="Name it"
                clearable
                :max-length="40"
                class="h-8 text-xs"
                data-test="field-name"
                @update:model-value="emit('update', { field: $event || null })"
            />
        </label>

        <!-- A switch, a box, a radio or a tab: how it starts -->
        <label v-if="switching" class="inspector-switch">
            On at first
            <Switch
                :model-value="first.on"
                data-test="starts-on"
                @update:model-value="emit('update', { on: $event })"
            />
        </label>

        <!-- A radio or a tab: one of a choice -->
        <label v-if="chosen" class="play-field">
            One choice with
            <PickOrAdd
                :model-value="first.choice ?? ''"
                :options="[
                    ...new Set(
                        items
                            .map((item) => item.choice)
                            .filter((name): name is string => !!name),
                    ),
                ]"
                label="Choice"
                placeholder="On its own"
                add-label="Name it"
                clearable
                :max-length="40"
                class="h-8 text-xs"
                data-test="choice-name"
                @update:model-value="emit('update', { choice: $event || null })"
            />
        </label>

        <FoldSection
            v-model:open="moreOpen"
            title="More"
            :hint="more"
            data-test="play-more"
        >
            <div class="flex flex-col gap-2">
                <label class="play-field">
                    While the screen scrolls
                    <Select
                        :model-value="first.fixed ?? 'scrolls'"
                        @update:model-value="
                            emit('update', {
                                fixed:
                                    $event === 'scrolls'
                                        ? null
                                        : ($event as 'top' | 'bottom'),
                            })
                        "
                    >
                        <SelectTrigger
                            size="sm"
                            class="h-8 w-full text-xs"
                            data-test="fixed"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="scrolls" class="text-xs"
                                >It scrolls with it</SelectItem
                            >
                            <SelectItem value="top" class="text-xs"
                                >It stays at the top</SelectItem
                            >
                            <SelectItem value="bottom" class="text-xs"
                                >It stays at the bottom</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </label>
                <label class="inspector-switch">
                    Hidden until shown
                    <Switch
                        :model-value="!!first.startsHidden"
                        data-test="starts-hidden"
                        @update:model-value="
                            emit('update', {
                                startsHidden: $event || undefined,
                            })
                        "
                    />
                </label>
            </div>
        </FoldSection>
    </div>
</template>

<style scoped>
.play-trigger {
    display: flex;
    align-items: flex-start;
    gap: 0.375rem;
}

.play-trigger-name {
    width: 4.25rem;
    flex-shrink: 0;
    padding-top: 0.5rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.play-row {
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.play-said {
    margin: -0.125rem 0 0 4.625rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.play-field {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.play-add,
.play-remove {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.75rem;
    color: var(--muted-foreground);
    border-radius: var(--radius-sm);
}

.play-add {
    align-self: flex-start;
    padding: 0.125rem 0.25rem;
}

.play-remove {
    justify-content: center;
    width: 1.75rem;
    height: 2rem;
    flex-shrink: 0;
}

.play-add:hover,
.play-remove:hover {
    color: var(--foreground);
    background-color: var(--muted);
}
</style>
