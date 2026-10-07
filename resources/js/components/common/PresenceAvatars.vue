<script setup lang="ts">
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getInitials } from '@/composables/useInitials';
import { colourFor } from '@/lib/live';
import type { Member } from '@/lib/live';

// Who else has this open, as a row of coloured initials
const props = defineProps<{ others: Member[] }>();

const SHOWN = 4;
const shown = computed(() => props.others.slice(0, SHOWN));
const more = computed(() => props.others.slice(SHOWN));
</script>

<template>
    <TooltipProvider v-if="others.length" :delay-duration="150">
        <div class="flex items-center -space-x-1.5" data-test="presence">
            <Tooltip v-for="member in shown" :key="member.id">
                <TooltipTrigger as-child>
                    <span
                        class="ring-background flex size-7 items-center justify-center rounded-full text-[11px] font-semibold text-white ring-2"
                        :style="{ backgroundColor: colourFor(member.id) }"
                        :aria-label="`${member.name} has this open`"
                        data-test="presence-member"
                    >
                        {{ getInitials(member.name) }}
                    </span>
                </TooltipTrigger>
                <TooltipContent>{{ member.name }} has this open</TooltipContent>
            </Tooltip>
            <Tooltip v-if="more.length">
                <TooltipTrigger as-child>
                    <span
                        class="bg-muted text-muted-foreground ring-background flex size-7 items-center justify-center rounded-full text-[11px] font-semibold ring-2"
                    >
                        +{{ more.length }}
                    </span>
                </TooltipTrigger>
                <TooltipContent>
                    {{ more.map((member) => member.name).join(', ') }}
                </TooltipContent>
            </Tooltip>
        </div>
    </TooltipProvider>
</template>
