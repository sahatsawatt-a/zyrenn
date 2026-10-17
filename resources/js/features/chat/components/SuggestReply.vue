<script setup lang="ts">
import { LoaderCircle, Sparkles } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { xsrfToken } from '@/lib/utils';
import type { AnswerAction } from './AnswerActions.vue';
import type { AnswerSource } from './AnswerSources.vue';
import { suggest } from '@/routes/chats';
import Tip from '@/components/common/Tip.vue';

// A reply an agent writes for what the customer last asked, put in what is
// being written for a person to read, change, and send -- or not. Nothing
// goes to the customer until they do.

const props = defineProps<{
    room: string;
    agents: { ref_id: string; name: string }[];
    disabled?: boolean;
}>();

export type Suggested = {
    agent: string;
    version: number;
    knows: boolean;
    sources: AnswerSource[];
    actions: AnswerAction[];
};

const emit = defineEmits<{
    pick: [text: string, about: Suggested];
    failed: [why: string];
}>();

const working = ref(false);

async function ask(agent: string) {
    working.value = true;

    try {
        const response = await fetch(suggest(props.room).url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ agent }),
        });
        const body = await response.json();

        if (response.ok && body.text) {
            emit('pick', body.text, {
                agent: body.agent,
                version: body.version,
                knows: body.knows,
                sources: body.sources ?? [],
                actions: body.actions ?? [],
            });
        } else {
            emit('failed', body.message ?? 'The agent had nothing to suggest.');
        }
    } catch {
        emit('failed', 'The agent could not be asked.');
    } finally {
        working.value = false;
    }
}
</script>

<template>
    <!-- One agent: asked at once; several: chosen first -->
    <Tip
        v-if="agents.length === 1"
        :text="`Suggest a reply, by ${agents[0].name}`"
    >
        <Button
            type="button"
            size="icon-sm"
            variant="ghost"
            :aria-label="`Suggest a reply, by ${agents[0].name}`"
            :disabled="disabled || working"
            data-test="suggest-reply"
            @click="ask(agents[0].ref_id)"
        >
            <LoaderCircle v-if="working" class="animate-spin" />
            <Sparkles v-else />
        </Button>
    </Tip>
    <Tip v-else-if="agents.length" text="Suggest a reply">
        <span class="inline-flex">
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Suggest a reply"
                        :disabled="disabled || working"
                        data-test="suggest-reply"
                    >
                        <LoaderCircle v-if="working" class="animate-spin" />
                        <Sparkles v-else />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" side="top">
                    <DropdownMenuLabel class="text-xs"
                        >Suggest a reply, by</DropdownMenuLabel
                    >
                    <DropdownMenuItem
                        v-for="agent in agents"
                        :key="agent.ref_id"
                        :data-pick="agent.ref_id"
                        @select="ask(agent.ref_id)"
                    >
                        {{ agent.name }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </span>
    </Tip>
</template>
