<script setup lang="ts">
import { Plus, Trash2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    COLUMN_TYPES,
    SUMMABLE,
} from '@/features/tables/composables/columnTypes';
import { SUMMARIES } from '@/features/tables/composables/formulas';
import type { Tone } from '@/features/tables/composables/tones';
import {
    TONES,
    chipClass,
    nextTone,
    swatchClass,
} from '@/features/tables/composables/tones';
import type {
    ColumnMeta,
    ColumnOption,
    ColumnSummary,
    ColumnType,
} from '@/types';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    column: ColumnMeta | null;
    // Why the server wouldn't take a formula, and whether it is still being asked
    error?: string | null;
    busy?: boolean;
}>();

const emit = defineEmits<{
    (
        e: 'save',
        payload: {
            name: string;
            label: string;
            type: ColumnType;
            options?: ColumnOption[];
            extra?: Partial<ColumnMeta>;
        },
    ): void;
    (e: 'delete', columnName: string): void;
}>();

const CURRENCIES = ['$', '€', '£', '฿', '¥'];
const RATINGS = [3, 5, 10];

const isEditing = computed(() => !!props.column);
const label = ref('');
const type = ref<ColumnType>('varchar');
const options = ref<ColumnOption[]>([]);
const newOption = ref('');
const tone = ref<Tone>(TONES[0]);
const currencySymbol = ref('$');
const maxRating = ref(5);
const expression = ref('');
const summary = ref<ColumnSummary | null>(null);

const hasChoices = computed(
    () => type.value === 'select' || type.value === 'multi_select',
);

// The dialog starts from the column being edited, or from nothing for a new one
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    label.value = props.column?.label ?? '';
    type.value = props.column?.type ?? 'varchar';
    options.value = structuredClone(props.column?.options ?? []);
    // A formula is shown as money only when asked to be
    currencySymbol.value =
        props.column?.currencySymbol || (type.value === 'formula' ? '' : '$');
    maxRating.value = props.column?.maxRating || 5;
    expression.value = props.column?.expression ?? '';
    summary.value = props.column?.summary ?? null;
    newOption.value = '';
    tone.value = TONES[0];
});

// A new formula starts as a plain number; money is opted into
watch(type, (kind) => {
    if (kind === 'formula' && !props.column) {
        currencySymbol.value = '';
    }
});

const addOption = () => {
    const value = newOption.value.trim();
    newOption.value = '';

    if (
        !value ||
        options.value.some(
            (option) => option.value.toLowerCase() === value.toLowerCase(),
        )
    ) {
        return;
    }

    options.value.push({ id: String(Date.now()), value, color: tone.value });
    tone.value = nextTone(tone.value);
};

const removeOption = (id: string) => {
    options.value = options.value.filter((option) => option.id !== id);
};

const save = () => {
    const cleanLabel = label.value.trim();

    if (!cleanLabel) {
        return;
    }

    emit('save', {
        // The server names a new column from its label; this is only a hint
        name:
            props.column?.name ??
            cleanLabel.toLowerCase().replace(/[^a-z0-9]+/g, '_'),
        label: cleanLabel,
        type: type.value,
        options: hasChoices.value ? options.value : undefined,
        extra: {
            currencySymbol: currencySymbol.value,
            maxRating: maxRating.value,
            expression: type.value === 'formula' ? expression.value : null,
            summary: SUMMABLE.includes(type.value) ? summary.value : null,
        },
    });

    // A formula has to be taken by the server first: the page closes this when it is
    if (type.value !== 'formula') {
        open.value = false;
    }
};

const remove = () => {
    if (props.column && !props.column.isPrimary) {
        emit('delete', props.column.name);
        open.value = false;
    }
};

// A kind can only become another that is kept the same way, and never for the
// id; a formula is kept nowhere, so it stays a formula and nothing becomes one
const canPick = (kind: ColumnType) =>
    !props.column ||
    (!props.column.isPrimary &&
        (kind === 'formula') === (props.column.type === 'formula')) ||
    kind === props.column.type;

const segment = (active: boolean) =>
    active
        ? 'bg-background text-foreground shadow-xs'
        : 'text-muted-foreground hover:text-foreground';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-5 sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>
                    {{ isEditing ? 'Edit field' : 'New field' }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        isEditing
                            ? 'Rename this field, or change how it is shown.'
                            : 'Name the field, then pick what it holds.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-5" @submit.prevent="save">
                <div class="grid gap-2">
                    <Label for="column-label">Name</Label>
                    <Input
                        id="column-label"
                        v-model="label"
                        placeholder="e.g. Status, Due date, Budget"
                        autocomplete="off"
                        data-test="column-label"
                    />
                </div>

                <div class="grid gap-2">
                    <Label>Kind</Label>
                    <div
                        class="grid max-h-56 grid-cols-2 gap-1.5 overflow-y-auto sm:grid-cols-3"
                    >
                        <button
                            v-for="kind in COLUMN_TYPES"
                            :key="kind.type"
                            type="button"
                            :disabled="!canPick(kind.type)"
                            :data-test="`column-type-${kind.type}`"
                            class="flex items-center gap-2 rounded-md border px-2.5 py-2 text-left text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                            :class="
                                type === kind.type
                                    ? 'border-primary bg-primary/10 text-foreground'
                                    : 'hover:bg-muted'
                            "
                            @click="type = kind.type"
                        >
                            <component
                                :is="kind.icon"
                                class="size-4 shrink-0"
                                :class="
                                    type === kind.type
                                        ? 'text-primary'
                                        : 'text-muted-foreground'
                                "
                            />
                            <span class="truncate">{{ kind.label }}</span>
                        </button>
                    </div>
                </div>

                <div v-if="hasChoices" class="grid gap-2">
                    <Label>Choices</Label>
                    <div
                        class="flex min-h-10 flex-wrap gap-1.5 rounded-md border p-2"
                    >
                        <span
                            v-for="option in options"
                            :key="option.id"
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="chipClass(option.color)"
                        >
                            {{ option.value }}
                            <button
                                type="button"
                                class="opacity-60 hover:opacity-100"
                                :title="`Remove ${option.value}`"
                                @click="removeOption(option.id)"
                            >
                                <X class="size-3" />
                            </button>
                        </span>
                        <span
                            v-if="!options.length"
                            class="text-muted-foreground text-xs"
                        >
                            No choices yet.
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1">
                            <button
                                v-for="swatch in TONES"
                                :key="swatch"
                                type="button"
                                class="size-4 rounded-full transition-transform"
                                :class="[
                                    swatchClass(swatch),
                                    tone === swatch
                                        ? 'ring-ring ring-offset-background ring-2 ring-offset-2'
                                        : 'opacity-60 hover:opacity-100',
                                ]"
                                :title="swatch"
                                @click="tone = swatch"
                            />
                        </div>
                        <Input
                            v-model="newOption"
                            class="h-8 flex-1"
                            placeholder="Add a choice"
                            @keydown.enter.prevent="addOption"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addOption"
                        >
                            <Plus /> Add
                        </Button>
                    </div>
                </div>

                <div v-else-if="type === 'formula'" class="grid gap-2">
                    <Label for="column-expression">Formula</Label>
                    <textarea
                        id="column-expression"
                        v-model="expression"
                        rows="3"
                        spellcheck="false"
                        placeholder="e.g. cny * rate"
                        class="border-input bg-background focus-visible:ring-ring/50 rounded-md border px-3 py-2 font-mono text-sm outline-none focus-visible:ring-2"
                        :aria-invalid="!!error"
                        data-test="column-expression"
                        @keydown.enter.exact.prevent="save"
                    />
                    <p
                        v-if="error"
                        class="text-destructive text-xs"
                        data-test="column-expression-error"
                    >
                        {{ error }}
                    </p>
                    <p v-else class="text-muted-foreground text-xs leading-5">
                        Worked out for each row, like a spreadsheet. Name a
                        field by its name or, in brackets, its label:
                        <code>[Cost (THB)] / people</code>. Parameters above the
                        table can be named too. Functions include
                        <code>if</code>, <code>round</code>, <code>sum</code>,
                        <code>text</code> and <code>date</code>. Reach a trip or
                        another table by its title:
                        <code>trip("Shanghai").day(day).cost</code>,
                        <code>sum(table("Budget").thb)</code>.
                    </p>
                    <div class="flex items-center gap-2">
                        <span class="text-muted-foreground text-xs">
                            Show as money
                        </span>
                        <div class="bg-muted flex gap-1 rounded-md p-1">
                            <button
                                type="button"
                                class="rounded px-2 py-1 text-xs font-medium transition-colors"
                                :class="segment(!currencySymbol)"
                                @click="currencySymbol = ''"
                            >
                                No
                            </button>
                            <button
                                v-for="symbol in CURRENCIES"
                                :key="symbol"
                                type="button"
                                class="w-8 rounded py-1 text-sm font-medium transition-colors"
                                :class="segment(currencySymbol === symbol)"
                                @click="currencySymbol = symbol"
                            >
                                {{ symbol }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else-if="type === 'currency'" class="grid gap-2">
                    <Label>Currency</Label>
                    <div class="flex items-center gap-2">
                        <div class="bg-muted flex gap-1 rounded-md p-1">
                            <button
                                v-for="symbol in CURRENCIES"
                                :key="symbol"
                                type="button"
                                class="w-8 rounded py-1 text-sm font-medium transition-colors"
                                :class="segment(currencySymbol === symbol)"
                                @click="currencySymbol = symbol"
                            >
                                {{ symbol }}
                            </button>
                        </div>
                        <Input
                            v-model="currencySymbol"
                            class="h-8 w-20 text-center"
                            placeholder="Other"
                        />
                    </div>
                </div>

                <div v-else-if="type === 'rating'" class="grid gap-2">
                    <Label>Out of</Label>
                    <div class="bg-muted flex w-fit gap-1 rounded-md p-1">
                        <button
                            v-for="stars in RATINGS"
                            :key="stars"
                            type="button"
                            class="rounded px-3 py-1 text-sm font-medium transition-colors"
                            :class="segment(maxRating === stars)"
                            @click="maxRating = stars"
                        >
                            {{ stars }} stars
                        </button>
                    </div>
                </div>

                <div v-if="SUMMABLE.includes(type)" class="grid gap-2">
                    <Label>Under the column</Label>
                    <div
                        class="bg-muted flex w-fit flex-wrap gap-1 rounded-md p-1"
                    >
                        <button
                            type="button"
                            class="rounded px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="segment(summary === null)"
                            @click="summary = null"
                        >
                            Nothing
                        </button>
                        <button
                            v-for="option in SUMMARIES"
                            :key="option.id"
                            type="button"
                            class="rounded px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="segment(summary === option.id)"
                            :data-test="`column-summary-${option.id}`"
                            @click="summary = option.id"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>

                <DialogFooter class="items-center sm:justify-between">
                    <Button
                        v-if="isEditing && !column?.isPrimary"
                        type="button"
                        variant="ghost"
                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        @click="remove"
                    >
                        <Trash2 /> Delete field
                    </Button>
                    <span v-else />
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="open = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="
                                !label.trim() ||
                                busy ||
                                (type === 'formula' && !expression.trim())
                            "
                            data-test="column-save"
                        >
                            {{ isEditing ? 'Save' : 'Add field' }}
                        </Button>
                    </div>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
