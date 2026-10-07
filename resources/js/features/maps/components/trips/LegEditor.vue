<script setup lang="ts">
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { legModes } from '@/features/maps/lib/format';
import type { LegOverride } from '@/features/maps/lib/trip';

// Typing in a leg as you'll really make it: how, how long, what it costs,
// and a note -- "Line 2 to Lujiazui, exit 6".

const props = defineProps<{
    /** What was typed before, or the routed minutes to start from. */
    initial: LegOverride;
    currency: string;
    /** Whether there is something typed in to clear. */
    typed: boolean;
}>();
const emit = defineEmits<{ save: [leg: LegOverride]; clear: []; cancel: [] }>();

const draft = reactive<LegOverride>({ ...props.initial });

const save = () =>
    emit('save', {
        ...draft,
        minutes: Math.max(0, Number(draft.minutes) || 0),
        cost: Math.max(0, Number(draft.cost) || 0),
        note: draft.note.trim(),
    });

const field =
    'h-8 rounded-md border bg-background px-2 text-sm dark:bg-input/30';
</script>

<template>
    <form class="space-y-3" data-test="leg-form" @submit.prevent="save">
        <div class="text-sm font-medium">How you'll get there</div>
        <div class="grid grid-cols-3 gap-1">
            <button
                v-for="mode in legModes"
                :key="mode.id"
                type="button"
                class="flex flex-col items-center gap-1 rounded-md border px-1 py-1.5 text-xs"
                :class="
                    draft.mode === mode.id
                        ? 'border-primary bg-primary/10 text-primary'
                        : 'hover:bg-accent'
                "
                :data-test="`leg-mode-${mode.id}`"
                @click="draft.mode = mode.id"
            >
                <component :is="mode.icon" class="size-4" />
                {{ mode.label }}
            </button>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <label class="text-muted-foreground space-y-1 text-xs">
                Minutes
                <input
                    v-model.number="draft.minutes"
                    type="number"
                    min="0"
                    :class="field"
                    class="w-full"
                    data-test="leg-minutes"
                />
            </label>
            <label class="text-muted-foreground space-y-1 text-xs">
                Fare ({{ currency }})
                <input
                    v-model.number="draft.cost"
                    type="number"
                    min="0"
                    step="any"
                    :class="field"
                    class="w-full"
                    data-test="leg-cost"
                />
            </label>
        </div>
        <label class="text-muted-foreground block space-y-1 text-xs">
            Note
            <input
                v-model="draft.note"
                placeholder="Line 2 to Lujiazui, exit 6"
                :class="field"
                class="w-full"
                data-test="leg-note"
            />
        </label>
        <div class="flex items-center gap-2">
            <Button size="sm" type="submit" data-test="leg-save">Save</Button>
            <Button
                size="sm"
                variant="ghost"
                type="button"
                @click="emit('cancel')"
                >Cancel</Button
            >
            <button
                v-if="typed"
                type="button"
                class="text-muted-foreground hover:text-destructive ml-auto text-xs"
                data-test="leg-clear"
                @click="emit('clear')"
            >
                Use the routed time
            </button>
        </div>
    </form>
</template>
