<script setup lang="ts">
import { Check, ChevronsUpDown, Search } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';

// Models can number in the hundreds (OpenRouter), so only the first of them are drawn
const SHOWN = 80;

const props = defineProps<{
    // '' means the connection's usual model
    modelValue: string;
    // What the connection offers
    models: string[];
    // Which of them cost nothing; null when the host does not say, so there is nothing to filter by
    free?: string[] | null;
    loading?: boolean;
    // Why the list could not be had; a name can still be typed
    error?: string | null;
    // The connection's own choice, used when none is made
    usual?: string;
    // What to call leaving it empty, when there is no usual model to name
    none?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const open = ref(false);
const query = ref('');
const active = ref(0);
const list = ref<HTMLElement | null>(null);
const search = ref<HTMLInputElement | null>(null);

// Kept between visits, like a filter on a list; where storage is not to be had it is just off
const storageKey = 'model-picker-free-only';

function readFreeOnly(): boolean {
    try {
        return localStorage.getItem(storageKey) === '1';
    } catch {
        return false;
    }
}

const freeOnlyChosen = ref(readFreeOnly());
const freeNames = computed(() => new Set(props.free ?? []));

// The switch only means something while the host says what things cost
const freeOnly = computed(() => freeOnlyChosen.value && props.free != null);

function toggleFree() {
    freeOnlyChosen.value = !freeOnlyChosen.value;

    try {
        localStorage.setItem(storageKey, freeOnlyChosen.value ? '1' : '0');
    } catch {
        // Not remembered; it still works for now
    }

    search.value?.focus();
}

// What is on offer: everything, or only what costs nothing
const pool = computed(() =>
    freeOnly.value
        ? props.models.filter((name) => freeNames.value.has(name))
        : props.models,
);

const matches = computed(() => {
    const terms = query.value.toLowerCase().split(/\s+/).filter(Boolean);

    return pool.value.filter((name) => {
        const lower = name.toLowerCase();

        return terms.every((term) => lower.includes(term));
    });
});

const typed = computed(() => query.value.trim());

// A name the host does not list can still be asked for: a model it has not been told of yet
const custom = computed(() =>
    typed.value !== '' && !props.models.includes(typed.value)
        ? typed.value
        : null,
);

// What can be picked, in the order drawn: the usual model, what matches, then what was typed
const options = computed<
    { value: string; kind: 'usual' | 'model' | 'custom' }[]
>(() => [
    ...((props.usual || props.none) && typed.value === ''
        ? [{ value: '', kind: 'usual' as const }]
        : []),
    ...matches.value
        .slice(0, SHOWN)
        .map((value) => ({ value, kind: 'model' as const })),
    ...(custom.value ? [{ value: custom.value, kind: 'custom' as const }] : []),
]);

const hidden = computed(() => Math.max(0, matches.value.length - SHOWN));

// "openai/gpt-4o" is drawn as a quiet "openai/" and the name
const split = (name: string): [string, string] => {
    const slash = name.indexOf('/');

    return slash === -1
        ? ['', name]
        : [name.slice(0, slash + 1), name.slice(slash + 1)];
};

watch(open, async (isOpen) => {
    if (!isOpen) {
        return;
    }

    query.value = '';
    // Start on what is chosen now, so Enter keeps it and the arrows move from it
    active.value = Math.max(
        0,
        options.value.findIndex((option) => option.value === props.modelValue),
    );
    await nextTick();
    search.value?.focus();
    scrollToActive();
});

watch([query, freeOnly], () => {
    active.value = 0;
    list.value?.scrollTo({ top: 0 });
});

function scrollToActive() {
    nextTick(() =>
        list.value
            ?.querySelector('[data-active="true"]')
            ?.scrollIntoView({ block: 'nearest' }),
    );
}

function move(by: number) {
    const count = options.value.length;

    if (count) {
        active.value = (active.value + by + count) % count;
        scrollToActive();
    }
}

function pick(value: string) {
    emit('update:modelValue', value);
    open.value = false;
}

function onKey(event: KeyboardEvent) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
    } else if (event.key === 'Enter' && !event.isComposing) {
        event.preventDefault();
        const option = options.value[active.value];

        if (option) {
            pick(option.value);
        }
    }
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                class="w-full justify-between font-normal"
                data-model-picker
            >
                <span v-if="modelValue" class="truncate">{{ modelValue }}</span>
                <span v-else-if="usual" class="truncate">
                    {{ usual }}
                    <span class="text-muted-foreground">· the usual</span>
                </span>
                <span v-else class="text-muted-foreground">{{
                    none ?? 'Choose a model'
                }}</span>
                <ChevronsUpDown
                    class="text-muted-foreground ml-2 size-4 shrink-0"
                />
            </Button>
        </PopoverTrigger>

        <PopoverContent
            align="start"
            class="w-(--reka-popover-trigger-width) min-w-72 p-0"
            data-model-list
        >
            <div class="flex items-center gap-2 border-b px-3">
                <Search class="text-muted-foreground size-4 shrink-0" />
                <input
                    ref="search"
                    v-model="query"
                    class="placeholder:text-muted-foreground h-10 w-full bg-transparent text-sm outline-none"
                    :placeholder="
                        pool.length
                            ? `Search ${pool.length} ${freeOnly ? 'free ' : ''}models, or type a name`
                            : 'Type a model name'
                    "
                    spellcheck="false"
                    autocomplete="off"
                    aria-label="Search models"
                    @keydown="onKey"
                />
            </div>

            <button
                v-if="free != null"
                type="button"
                role="switch"
                :aria-checked="freeOnly"
                class="hover:bg-accent/50 flex w-full items-center justify-between gap-2 border-b px-3 py-2 text-left text-sm"
                data-free-toggle
                @click="toggleFree"
            >
                <span>
                    Free models only
                    <span class="text-muted-foreground text-xs"
                        >({{ free.length }})</span
                    >
                </span>
                <span
                    class="inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors"
                    :class="freeOnly ? 'bg-primary' : 'bg-input'"
                >
                    <span
                        class="bg-background size-4 rounded-full shadow transition-transform"
                        :class="freeOnly ? 'translate-x-4' : 'translate-x-0'"
                    />
                </span>
            </button>

            <div ref="list" class="max-h-64 overflow-y-auto p-1" role="listbox">
                <p
                    v-if="loading"
                    class="text-muted-foreground flex items-center gap-2 px-2 py-3 text-xs"
                >
                    <Spinner class="size-3.5" /> Asking the host for its models…
                </p>
                <p v-else-if="error" class="text-destructive px-2 py-2 text-xs">
                    {{ error }} A name can still be typed above.
                </p>

                <button
                    v-for="(option, index) in options"
                    :key="`${option.kind}:${option.value}`"
                    type="button"
                    role="option"
                    :aria-selected="option.value === modelValue"
                    :data-active="index === active"
                    class="data-[active=true]:bg-accent data-[active=true]:text-accent-foreground flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm"
                    @mousemove="active = index"
                    @click="pick(option.value)"
                >
                    <Check
                        class="size-4 shrink-0"
                        :class="
                            option.value === modelValue
                                ? 'opacity-100'
                                : 'opacity-0'
                        "
                    />
                    <span
                        v-if="option.kind === 'usual'"
                        class="min-w-0 flex-1 truncate"
                    >
                        {{ usual ? `The usual: ${usual}` : none }}
                    </span>
                    <span
                        v-else-if="option.kind === 'custom'"
                        class="min-w-0 flex-1 truncate"
                    >
                        Use “{{ option.value }}”
                    </span>
                    <span v-else class="min-w-0 flex-1 truncate">
                        <span class="text-muted-foreground">{{
                            split(option.value)[0]
                        }}</span
                        >{{ split(option.value)[1] }}
                    </span>
                    <span
                        v-if="
                            option.kind === 'model' &&
                            !freeOnly &&
                            freeNames.has(option.value)
                        "
                        class="shrink-0 rounded border border-emerald-600/30 px-1 text-[10px] font-medium text-emerald-700 dark:text-emerald-400"
                    >
                        free
                    </span>
                </button>

                <p
                    v-if="!loading && !error && !options.length"
                    class="text-muted-foreground px-2 py-3 text-xs"
                >
                    This host lists no models.
                </p>
                <p
                    v-else-if="
                        !loading && !matches.length && models.length && !custom
                    "
                    class="text-muted-foreground px-2 py-3 text-xs"
                >
                    No model matches.
                </p>
                <p
                    v-if="hidden"
                    class="text-muted-foreground px-2 py-2 text-xs"
                >
                    {{ hidden }} more: keep typing to narrow them.
                </p>
            </div>
        </PopoverContent>
    </Popover>
</template>
