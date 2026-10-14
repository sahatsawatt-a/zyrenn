<script setup lang="ts">
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';

// A model chosen from what a connection offers, in the app's own dropdown
// -- typing a name's first letters goes to it. A host that says what each
// costs (OpenRouter) gets a switch beside it for only the free ones, kept
// between visits. The one chosen is always there to see, listed or not;
// and when the host cannot be asked, a name is typed instead.

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

// The empty choice, as a dropdown cannot hold "" as a value
const USUAL = '__usual__';

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

function setFreeOnly(on: boolean) {
    freeOnlyChosen.value = on;

    try {
        localStorage.setItem(storageKey, on ? '1' : '0');
    } catch {
        // Not remembered; it still works for now
    }
}

// What is offered: everything, or only what costs nothing -- and the one chosen, listed or not
const listed = computed(() => {
    const pool = freeOnly.value
        ? props.models.filter((name) => freeNames.value.has(name))
        : props.models;

    return props.modelValue && !pool.includes(props.modelValue)
        ? [props.modelValue, ...pool]
        : pool;
});

const hasEmpty = computed(() => !!(props.usual || props.none));

const emptyLabel = computed(() =>
    props.usual ? `${props.usual} (its usual)` : (props.none ?? ''),
);

// Nothing to choose from: the host could not be asked, so a name is typed
const typing = computed(
    () => !props.loading && !!props.error && props.models.length === 0,
);

function choose(value: unknown) {
    emit('update:modelValue', value === USUAL ? '' : String(value ?? ''));
}
</script>

<template>
    <div class="grid gap-1.5">
        <Input
            v-if="typing"
            :model-value="modelValue"
            placeholder="A model's name, as the host knows it"
            aria-label="Model"
            data-model-picker
            @update:model-value="emit('update:modelValue', String($event))"
        />
        <Select
            v-else
            :model-value="modelValue || (hasEmpty ? USUAL : undefined)"
            :disabled="loading"
            @update:model-value="choose"
        >
            <SelectTrigger class="w-full" aria-label="Model" data-model-picker>
                <SelectValue
                    :placeholder="
                        loading ? 'Asking for its models…' : 'Choose a model'
                    "
                >
                    <span class="truncate">{{
                        modelValue || (hasEmpty ? emptyLabel : '')
                    }}</span>
                </SelectValue>
            </SelectTrigger>
            <SelectContent class="max-h-80" data-model-list>
                <SelectItem v-if="hasEmpty" :value="USUAL">
                    {{ emptyLabel }}
                </SelectItem>
                <SelectItem
                    v-for="name in listed"
                    :key="name"
                    :value="name"
                    :data-model="name"
                >
                    <span class="truncate">{{ name }}</span>
                    <span
                        v-if="freeNames.has(name)"
                        class="text-muted-foreground ml-auto text-[10px]"
                        >free</span
                    >
                </SelectItem>
            </SelectContent>
        </Select>

        <label
            v-if="free != null && !typing"
            class="text-muted-foreground flex items-center gap-2 text-xs"
        >
            <Switch
                :model-value="freeOnly"
                data-free-toggle
                @update:model-value="setFreeOnly(!!$event)"
            />
            Only free models
            <span class="tabular-nums">({{ free.length }})</span>
        </label>
        <p v-if="error" class="text-muted-foreground text-xs">
            {{ error }}
            <template v-if="typing"> Type its name instead.</template>
        </p>
    </div>
</template>
