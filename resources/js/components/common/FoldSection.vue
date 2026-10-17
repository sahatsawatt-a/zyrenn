<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

// Something folded away until it is wanted, the app's own way: its chevron
// turning as it opens, and what is inside counted on the line -- in place of
// the browser's <details>, whose small black triangle ignores the app.

const open = defineModel<boolean>('open', { default: false });

const props = defineProps<{
    title: string;
    // Said after the title, quieter: "2 lines", "3 steps"
    hint?: string;
    // A border round it, or flat in a panel
    boxed?: boolean;
    class?: string;
}>();
</script>

<template>
    <Collapsible
        v-model:open="open"
        :class="cn(boxed && 'rounded-md border', props.class)"
        data-fold
    >
        <CollapsibleTrigger
            class="hover:text-foreground flex w-full items-center gap-1.5 text-left text-sm font-medium"
            :class="boxed ? 'px-3 py-2' : 'py-1'"
            data-fold-toggle
        >
            <ChevronRight
                class="text-muted-foreground size-4 shrink-0 transition-transform duration-150 motion-reduce:transition-none"
                :class="{ 'rotate-90': open }"
            />
            <span class="min-w-0 truncate">{{ title }}</span>
            <span
                v-if="hint"
                class="text-muted-foreground shrink-0 text-xs font-normal"
                >{{ hint }}</span
            >
            <slot name="aside" />
        </CollapsibleTrigger>
        <CollapsibleContent :class="boxed ? 'px-3 pb-3' : 'pt-1 pl-5.5'">
            <slot />
        </CollapsibleContent>
    </Collapsible>
</template>
