<script setup lang="ts">
import { Check, ChevronsUpDown, Plus, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

// One of a list, or a new one: the app's own field for a value that is
// usually one already in use -- the part a ticket is about, a column's
// type -- but may be one nobody has used yet. Typing narrows the list;
// what was typed and is not in it can be added as itself.

export type PickOrAddOption =
    | string
    | { value: string; label?: string; hint?: string };

// The field itself is no element: what is said of it goes on its button
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue: string;
        options: PickOrAddOption[];
        // What it chooses, for whoever cannot see it
        label: string;
        // Said while nothing is chosen
        placeholder?: string;
        // Said beside a new one, as it is offered
        addLabel?: string;
        // Whether it may be left empty, with a way to clear it
        clearable?: boolean;
        maxLength?: number;
        disabled?: boolean;
        mono?: boolean;
        id?: string;
        class?: string;
    }>(),
    { placeholder: 'Choose…', addLabel: 'Add', maxLength: 255 },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const open = ref(false);
const typed = ref('');
const active = ref(0);
const search = ref<HTMLInputElement | null>(null);
const list = ref<HTMLElement | null>(null);

const all = computed(() =>
    props.options.map((option) =>
        typeof option === 'string'
            ? { value: option, label: option, hint: undefined }
            : {
                  value: option.value,
                  label: option.label ?? option.value,
                  hint: option.hint,
              },
    ),
);

const shown = computed(() => {
    const words = typed.value.trim().toLowerCase();

    return words === ''
        ? all.value
        : all.value.filter(
              (option) =>
                  option.label.toLowerCase().includes(words) ||
                  option.value.toLowerCase().includes(words),
          );
});

// What was typed, offered as a new one when nothing is already called that
const fresh = computed(() => {
    const text = typed.value.trim().slice(0, props.maxLength);

    return text !== '' &&
        !all.value.some(
            (option) =>
                option.value.toLowerCase() === text.toLowerCase() ||
                option.label.toLowerCase() === text.toLowerCase(),
        )
        ? text
        : null;
});

// The rows, in order: those that match, then the new one
const rows = computed(() => [
    ...shown.value.map((option) => ({ ...option, fresh: false })),
    ...(fresh.value === null
        ? []
        : [
              {
                  value: fresh.value,
                  label: fresh.value,
                  hint: undefined,
                  fresh: true,
              },
          ]),
]);

const chosenLabel = computed(
    () =>
        all.value.find((option) => option.value === props.modelValue)?.label ??
        props.modelValue,
);

watch(open, async (now) => {
    if (!now) {
        return;
    }

    typed.value = '';
    active.value = Math.max(
        0,
        rows.value.findIndex((row) => row.value === props.modelValue),
    );
    await nextTick();
    search.value?.focus();
    showActive();
});

// The highlighted row follows what is typed: the best match first
watch(typed, () => (active.value = 0));

function showActive() {
    list.value
        ?.querySelector(`[data-row="${active.value}"]`)
        ?.scrollIntoView({ block: 'nearest' });
}

function move(by: number) {
    if (rows.value.length) {
        active.value =
            (active.value + by + rows.value.length) % rows.value.length;
        nextTick(showActive);
    }
}

function choose(value: string) {
    emit('update:modelValue', value);
    open.value = false;
}

function enter() {
    const row = rows.value[active.value];

    if (row) {
        choose(row.value);
    }
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child :disabled="disabled">
            <Button
                v-bind="$attrs"
                :id="id"
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                :aria-label="label"
                :disabled="disabled"
                :class="
                    cn(
                        'dark:bg-input/30 w-full justify-between px-3 font-normal',
                        mono && 'font-mono text-xs',
                        props.class,
                    )
                "
            >
                <span
                    class="truncate"
                    :class="{ 'text-muted-foreground': !modelValue }"
                    data-pick-chosen
                    >{{ modelValue ? chosenLabel : placeholder }}</span
                >
                <ChevronsUpDown class="text-muted-foreground size-4 shrink-0" />
            </Button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            class="w-(--reka-popover-trigger-width) min-w-56 p-0"
            data-pick-or-add
            @open-auto-focus.prevent
        >
            <input
                ref="search"
                v-model="typed"
                type="text"
                :maxlength="maxLength"
                class="placeholder:text-muted-foreground w-full border-b bg-transparent px-3 py-2.5 text-sm outline-none"
                :class="{ 'font-mono text-xs': mono }"
                :placeholder="`Find or add ${label.toLowerCase()}…`"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                role="searchbox"
                :aria-label="`Find or add ${label.toLowerCase()}`"
                data-pick-search
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="enter"
            />
            <div
                ref="list"
                class="max-h-64 overflow-y-auto p-1"
                role="listbox"
                :aria-label="label"
            >
                <button
                    v-for="(row, index) in rows"
                    :key="`${row.fresh ? 'new:' : ''}${row.value}`"
                    type="button"
                    role="option"
                    :aria-selected="row.value === modelValue"
                    class="flex w-full cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm"
                    :class="[
                        index === active
                            ? 'bg-accent text-accent-foreground'
                            : '',
                        mono && !row.fresh ? 'font-mono text-xs' : '',
                    ]"
                    :data-row="index"
                    :data-pick="row.fresh ? undefined : row.value"
                    :data-pick-new="row.fresh ? row.value : undefined"
                    @mousemove="active = index"
                    @click="choose(row.value)"
                >
                    <Plus
                        v-if="row.fresh"
                        class="text-primary size-4 shrink-0"
                    />
                    <Check
                        v-else
                        class="size-4 shrink-0"
                        :class="
                            row.value === modelValue
                                ? 'opacity-100'
                                : 'opacity-0'
                        "
                    />
                    <span v-if="row.fresh" class="min-w-0 truncate">
                        {{ addLabel }}
                        <span class="font-medium">“{{ row.label }}”</span>
                    </span>
                    <span v-else class="min-w-0 flex-1 truncate">{{
                        row.label
                    }}</span>
                    <span
                        v-if="row.hint"
                        class="text-muted-foreground ml-auto shrink-0 text-xs"
                        >{{ row.hint }}</span
                    >
                </button>
                <p
                    v-if="!rows.length"
                    class="text-muted-foreground px-2 py-3 text-center text-sm"
                >
                    Type to add one.
                </p>
            </div>
            <button
                v-if="clearable && modelValue"
                type="button"
                class="text-muted-foreground hover:text-foreground flex w-full items-center gap-2 border-t px-3 py-2 text-left text-sm"
                data-pick-clear
                @click="choose('')"
            >
                <X class="size-4" /> None
            </button>
        </PopoverContent>
    </Popover>
</template>
