<script setup lang="ts">
import { Loader2 } from '@lucide/vue';
import { computed } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import PageThings from './PageThings.vue';
import type { Look, StepKind, Thing } from './steps';

// Picking what a step is about from the page itself: the page as the steps
// before it leave it, each field, button, link and piece of writing marked on
// a picture of it and listed by the name the step will go by. Something to
// type into is only ever a field.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    // Which step is being written, and what it does
    at: number;
    kind: StepKind;
    look: Look | null;
    loading: boolean;
    problem: string | null;
}>();

const emit = defineEmits<{ pick: [thing: Thing] }>();

// What a step of this kind can be about
const allowed = computed<Thing['kind'][]>(() =>
    props.kind === 'type'
        ? ['field']
        : props.kind === 'choose'
          ? ['list']
          : [
                'field',
                'button',
                'link',
                'tab',
                'checkbox',
                'radio',
                'picture',
                'list',
                'words',
            ],
);

function pick(thing: Thing) {
    emit('pick', thing);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[92vh] w-full flex-col gap-3 p-4 sm:max-w-5xl"
            data-test="pick-dialog"
        >
            <DialogTitle class="pr-8 text-base">
                What is step {{ at + 1 }} about?
            </DialogTitle>
            <DialogDescription class="text-xs">
                The page as
                {{ at === 1 ? 'it opens' : `steps 1 to ${at} leave it` }}. Pick
                one and the step goes by its name.
                <template v-if="kind === 'type'"
                    >Only fields can be typed into.</template
                >
                <template v-if="kind === 'choose'"
                    >Only lists can be chosen from.</template
                >
            </DialogDescription>

            <div
                v-if="loading"
                class="text-muted-foreground flex items-center gap-2 py-16 text-sm"
                data-test="pick-loading"
            >
                <Loader2 class="size-4 animate-spin" />
                Opening the page in the browser…
            </div>

            <p
                v-else-if="problem"
                class="text-destructive py-6 text-sm"
                data-test="pick-problem"
            >
                {{ problem }}
            </p>

            <PageThings
                v-else-if="look"
                :look="look"
                :kinds="allowed"
                @pick="pick"
            />
        </DialogContent>
    </Dialog>
</template>
