<script setup lang="ts">
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { Problem } from '@/features/workflow/types';

// What stands in the way of publishing, each one a way to the step it is in

defineProps<{
    problems: Problem[];
    // A step's name, by its id
    labelOf: (id: string) => string | undefined;
}>();

const emit = defineEmits<{ focus: [id: string | undefined] }>();
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <Button
                size="sm"
                variant="ghost"
                :class="
                    problems.length
                        ? 'text-destructive hover:text-destructive'
                        : 'text-emerald-600 dark:text-emerald-400'
                "
                data-problems
            >
                <CircleAlert v-if="problems.length" />
                <CircleCheck v-else />
                {{ problems.length ? `${problems.length} to fix` : 'Ready' }}
            </Button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-80 p-0">
            <p class="border-b px-3 py-2 text-sm font-medium">
                {{
                    problems.length
                        ? 'Before it can be published'
                        : 'Nothing stands in the way of publishing.'
                }}
            </p>
            <ul v-if="problems.length" class="max-h-72 overflow-y-auto">
                <li v-for="(problem, at) in problems" :key="at">
                    <button
                        type="button"
                        class="hover:bg-muted/60 flex w-full gap-2 px-3 py-2 text-left text-xs"
                        :disabled="!problem.node"
                        @click="emit('focus', problem.node)"
                    >
                        <CircleAlert
                            class="text-destructive mt-px size-3.5 shrink-0"
                        />
                        <span>
                            <span v-if="problem.node" class="font-medium"
                                >{{ labelOf(problem.node) }}:
                            </span>
                            {{ problem.message }}
                        </span>
                    </button>
                </li>
            </ul>
        </PopoverContent>
    </Popover>
</template>
