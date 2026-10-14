<script setup lang="ts">
import { Head, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { BookOpenText, Bot, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watchEffect } from 'vue';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AgentSheet from '@/features/agents/AgentSheet.vue';
import type { Agent, AgentChoices } from '@/features/agents/agents';
import { destroy } from '@/routes/agents';
import { index as ownAgents } from '@/routes/agents';
import { index as projectAgents } from '@/routes/projects/agents';
import { owned } from '@/lib/projects';

const props = defineProps<{
    agents: Agent[];
    choices: AgentChoices;
    can: { manage: boolean };
    urls: { store: string };
}>();

// Its name in the bar at the top, as every page has
watchEffect(() =>
    setLayoutProps({
        breadcrumbs: [
            { title: 'Agents', href: owned(ownAgents, projectAgents)() },
        ],
    }),
);

const page = usePage();
const where = computed(() => page.props.project?.name ?? 'your own');

// One opened beside the list, by its ref_id: the list's copy stays the one shown as it changes
const openRef = ref<string | null>(null);
const opened = computed(
    () => props.agents.find((agent) => agent.ref_id === openRef.value) ?? null,
);

const making = ref(false);

function make(preset: string) {
    const known = new Set(props.agents.map((agent) => agent.ref_id));

    router.post(
        props.urls.store,
        { preset },
        {
            preserveScroll: true,
            onSuccess: () => {
                making.value = false;
                // Opened at once, to be set up
                openRef.value =
                    props.agents.find((agent) => !known.has(agent.ref_id))
                        ?.ref_id ?? null;
            },
        },
    );
}

const knowledgeOf = (agent: Agent) =>
    props.choices.knowledge.find((k) => k.ref_id === agent.knowledge)?.title;

const connectionOf = (agent: Agent) =>
    props.choices.connections.find((c) => c.ref_id === agent.connection)?.name;

const removing = ref<Agent | null>(null);

function remove() {
    if (removing.value) {
        router.delete(destroy(removing.value.ref_id).url, {
            preserveScroll: true,
            onFinish: () => (removing.value = null),
        });
    }
}
</script>

<template>
    <Head title="Agents" />

    <div class="mx-auto w-full max-w-4xl space-y-5 p-4 md:p-6">
        <PageHeader
            :icon="Bot"
            title="Agents"
            :description="`Who answers, in ${where}: a model with its instructions and what it knows. Every change is kept, to go back to.`"
        >
            <Button
                v-if="can.manage"
                data-test="agent-new"
                @click="making = true"
            >
                <Plus /> New agent
            </Button>
        </PageHeader>

        <EmptyState v-if="!agents.length" :icon="Bot" title="No agents yet">
            <p class="text-muted-foreground max-w-md text-sm">
                Start from "Customer desk" to answer customers from a knowledge
                base and pass their requests to a person.
            </p>
        </EmptyState>

        <ul v-else class="grid gap-3 sm:grid-cols-2">
            <li
                v-for="agent in agents"
                :key="agent.ref_id"
                class="bg-card flex flex-col gap-3 rounded-xl border p-4"
                data-test="agent"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg"
                    >
                        <Bot class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ agent.name }}</p>
                        <p
                            class="text-muted-foreground truncate text-xs"
                            data-test="agent-summary"
                        >
                            v{{ agent.version }} ·
                            {{ agent.model ?? 'no model yet' }}
                            <template v-if="connectionOf(agent)">
                                · {{ connectionOf(agent) }}</template
                            >
                        </p>
                    </div>
                    <Button
                        v-if="can.manage"
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="`Delete ${agent.name}`"
                        @click="removing = agent"
                    >
                        <Trash2 />
                    </Button>
                </div>
                <p
                    v-if="knowledgeOf(agent)"
                    class="text-muted-foreground flex items-center gap-1.5 text-xs"
                >
                    <BookOpenText class="size-3.5" /> Knows
                    {{ knowledgeOf(agent) }}
                </p>
                <p
                    class="text-muted-foreground line-clamp-2 font-mono text-[11px]"
                >
                    {{ agent.instructions || 'No instructions yet.' }}
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    class="mt-auto"
                    data-test="agent-open"
                    @click="openRef = agent.ref_id"
                >
                    {{
                        can.manage ? 'Change, try, or go back' : 'Look, and try'
                    }}
                </Button>
            </li>
        </ul>

        <AgentSheet
            v-if="opened"
            :open="openRef !== null"
            :agent="opened"
            :choices="choices"
            :can-manage="can.manage"
            @update:open="$event || (openRef = null)"
        />

        <Dialog v-model:open="making">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>New agent</DialogTitle>
                    <DialogDescription>
                        Start from instructions that work, and change them to
                        suit.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <button
                        v-for="preset in choices.presets"
                        :key="preset.key"
                        type="button"
                        class="hover:bg-accent rounded-lg border px-4 py-3 text-left"
                        :data-test="`agent-preset-${preset.key}`"
                        @click="make(preset.key)"
                    >
                        <span class="block font-medium">{{ preset.name }}</span>
                        <span class="text-muted-foreground text-sm">{{
                            preset.description
                        }}</span>
                    </button>
                </div>
            </DialogContent>
        </Dialog>

        <DeleteDialog
            :open="removing !== null"
            :name="removing?.name ?? ''"
            description="It goes with every version of it. A workflow step that asks it then fails until another is chosen."
            @update:open="$event || (removing = null)"
            @confirm="remove"
        />
    </div>
</template>
