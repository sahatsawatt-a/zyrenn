<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronDown,
    History,
    LoaderCircle,
    RotateCcw,
    Send,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type {
    Agent,
    AgentAnswer,
    AgentChoices,
    AgentTool,
    ToolServer,
} from '@/features/agents/agents';
import AnswerActions from '@/features/chat/components/AnswerActions.vue';
import { Switch } from '@/components/ui/switch';
import AnswerSources from '@/features/chat/components/AnswerSources.vue';
import ModelPicker from '@/features/chat/components/ModelPicker.vue';
import TicketPick from '@/features/tickets/components/TicketPick.vue';
import { formatRelativeTime, xsrfToken } from '@/lib/utils';
import {
    restore,
    tools as toolsOf,
    tryMethod as tryAgent,
    update,
} from '@/routes/agents';
import { models } from '@/routes/connections';

// One agent, opened beside the list: what it is -- its model, instructions
// and knowledge -- changed and kept as a new version; tried with a message;
// and every version before, any of which can be made the latest again.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    agent: Agent;
    choices: AgentChoices;
    canManage: boolean;
}>();

const NONE = '__none__';

// --------------------------------------------------- What it is, as written
const draft = ref({
    name: '',
    connection: '',
    model: '',
    knowledge: '',
    instructions: '',
    note: '',
    tools: [] as AgentTool[],
});
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const fill = () => {
    draft.value = {
        name: props.agent.name,
        connection: props.agent.connection ?? NONE,
        model: props.agent.model ?? '',
        knowledge: props.agent.knowledge ?? NONE,
        instructions: props.agent.instructions ?? '',
        note: '',
        tools: [...props.agent.tools],
    };
    errors.value = {};
};

// Fresh when it opens, and when a version is made from somewhere else
watch(
    () => [open.value, props.agent.version],
    () => open.value && fill(),
    { immediate: true },
);

const changed = computed(
    () =>
        draft.value.name !== props.agent.name ||
        draft.value.connection !== (props.agent.connection ?? NONE) ||
        draft.value.model !== (props.agent.model ?? '') ||
        draft.value.knowledge !== (props.agent.knowledge ?? NONE) ||
        draft.value.instructions !== (props.agent.instructions ?? '') ||
        keyOf(draft.value.tools) !== keyOf(props.agent.tools),
);

// Two lists of tools are the same whatever their order
const keyOf = (tools: AgentTool[]) =>
    tools
        .map((tool) => `${tool.server}:${tool.name}`)
        .sort()
        .join('|');

// ----------------------------------------------------------------- Tools
const servers = ref<ToolServer[] | null>(null);
const toolsFailed = ref('');

async function loadTools() {
    toolsFailed.value = '';

    try {
        const response = await fetch(toolsOf(props.agent.ref_id).url, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('not listed');
        }

        servers.value = (await response.json()).servers;
    } catch {
        toolsFailed.value = 'Its tools could not be listed.';
    }
}

watch(open, (isOpen) => isOpen && servers.value === null && void loadTools(), {
    immediate: true,
});

const given = (server: string, name: string) =>
    draft.value.tools.some(
        (tool) => tool.server === server && tool.name === name,
    );

function give(server: string, name: string, on: boolean) {
    draft.value.tools = on
        ? [...draft.value.tools, { server, name }]
        : draft.value.tools.filter(
              (tool) => !(tool.server === server && tool.name === name),
          );
}

const toolsOpen = ref(false);

function save() {
    saving.value = true;
    router.patch(
        update(props.agent.ref_id).url,
        {
            name: draft.value.name,
            connection:
                draft.value.connection === NONE ? null : draft.value.connection,
            model: draft.value.model || null,
            knowledge:
                draft.value.knowledge === NONE ? null : draft.value.knowledge,
            instructions: draft.value.instructions,
            tools: draft.value.tools,
            note: draft.value.note || null,
        },
        {
            preserveScroll: true,
            only: ['agents'],
            onError: (problems) => (errors.value = problems),
            onFinish: () => (saving.value = false),
        },
    );
}

// The models its connection offers, asked for when it is chosen
const offered = ref<string[]>([]);
const offeredFree = ref<string[] | null>(null);
const offeredFailure = ref<string | null>(null);
const loadingModels = ref(false);

async function loadModels(connection: string) {
    offered.value = [];
    offeredFree.value = null;
    offeredFailure.value = null;

    if (!connection || connection === NONE) {
        return;
    }

    loadingModels.value = true;

    try {
        const response = await fetch(models(connection).url, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json();

        if (response.ok) {
            offered.value = body.models;
            offeredFree.value = body.free;
        } else {
            offeredFailure.value = body.message;
        }
    } catch {
        offeredFailure.value = 'Could not ask for its models.';
    } finally {
        loadingModels.value = false;
    }
}

watch(
    () => (open.value ? draft.value.connection : ''),
    (connection) => void loadModels(connection),
    { immediate: true },
);

const usual = computed(
    () =>
        props.choices.connections.find(
            (c) => c.ref_id === draft.value.connection,
        )?.default_model ?? undefined,
);

const connectionOptions = computed(() => [
    { value: NONE, label: 'None yet' },
    ...props.choices.connections.map((c) => ({
        value: c.ref_id,
        label: `${c.name} · ${c.label}`,
    })),
]);

const knowledgeOptions = computed(() => [
    { value: NONE, label: 'None: it answers from what it is told alone' },
    ...props.choices.knowledge.map((k) => ({
        value: k.ref_id,
        label: k.title,
    })),
]);

// ------------------------------------------------------------- Trying it
const message = ref('');
const trying = ref<number | null>(null);
const answer = ref<AgentAnswer | null>(null);
const failed = ref('');

async function tryIt(version: number | null = null) {
    if (!message.value.trim()) {
        return;
    }

    trying.value = version ?? props.agent.version;
    failed.value = '';
    answer.value = null;

    try {
        const response = await fetch(tryAgent(props.agent.ref_id).url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ message: message.value, version }),
        });
        const body = await response.json();

        if (response.ok) {
            answer.value = body;
        } else {
            failed.value = body.message ?? 'It could not be asked.';
        }
    } catch {
        failed.value = 'It could not be asked.';
    } finally {
        trying.value = null;
    }
}

// --------------------------------------------------------------- History
const shown = ref<number | null>(null);

const useAgain = (number: number) =>
    router.post(
        restore(props.agent.ref_id).url,
        { version: number },
        { preserveScroll: true, only: ['agents'] },
    );
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-xl"
            data-test="agent-sheet"
        >
            <SheetHeader class="border-b px-5 py-4 text-left">
                <SheetTitle>{{ agent.name }}</SheetTitle>
                <SheetDescription>
                    Version {{ agent.version }}. Every change is kept: an
                    earlier one can be used again below.
                </SheetDescription>
            </SheetHeader>

            <div class="space-y-6 px-5 py-5">
                <!-- What it is -->
                <form class="grid gap-4" @submit.prevent="save">
                    <fieldset :disabled="!canManage" class="grid gap-4">
                        <div class="grid gap-1.5">
                            <Label for="agent-name">Name</Label>
                            <Input
                                id="agent-name"
                                v-model="draft.name"
                                maxlength="80"
                                data-test="agent-name"
                            />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label>AI connection</Label>
                                <TicketPick
                                    v-model="draft.connection"
                                    :options="connectionOptions"
                                    label="AI connection"
                                    data-test="agent-connection"
                                />
                                <InputError :message="errors.connection" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label>Model</Label>
                                <ModelPicker
                                    v-model="draft.model"
                                    :models="offered"
                                    :free="offeredFree"
                                    :loading="loadingModels"
                                    :error="offeredFailure"
                                    :usual="usual"
                                />
                            </div>
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Knowledge it answers from</Label>
                            <TicketPick
                                v-model="draft.knowledge"
                                :options="knowledgeOptions"
                                label="Knowledge"
                                data-test="agent-knowledge"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="agent-instructions">Instructions</Label>
                            <textarea
                                id="agent-instructions"
                                v-model="draft.instructions"
                                rows="12"
                                maxlength="20000"
                                class="border-input focus-visible:ring-ring/50 rounded-md border bg-transparent px-3 py-2 font-mono text-xs leading-relaxed outline-none focus-visible:ring-[3px]"
                                data-test="agent-instructions"
                            />
                        </div>
                        <!-- What it may use: Zyrenn's own tools, and those of MCP servers -->
                        <div class="grid gap-2" data-test="agent-tools">
                            <button
                                type="button"
                                class="flex items-center gap-1 text-left text-sm font-medium"
                                :aria-expanded="toolsOpen"
                                data-test="agent-tools-toggle"
                                @click="toolsOpen = !toolsOpen"
                            >
                                <ChevronDown
                                    class="size-4 transition-transform"
                                    :class="{ 'rotate-180': toolsOpen }"
                                />
                                Tools
                                <span class="text-muted-foreground font-normal"
                                    >· {{ draft.tools.length }} given</span
                                >
                            </button>
                            <p
                                v-if="toolsOpen"
                                class="text-muted-foreground text-xs"
                            >
                                It uses them for whoever it works for. One that
                                changes something is used only in a workflow:
                                trying it, or a suggested reply, only looks
                                things up.
                            </p>
                            <p
                                v-if="toolsOpen && toolsFailed"
                                class="text-destructive text-xs"
                            >
                                {{ toolsFailed }}
                            </p>
                            <LoaderCircle
                                v-else-if="toolsOpen && servers === null"
                                class="text-muted-foreground size-4 animate-spin"
                            />
                            <template v-else-if="toolsOpen && servers">
                                <section
                                    v-for="server in servers"
                                    :key="server.server"
                                    class="rounded-lg border"
                                >
                                    <h4
                                        class="bg-muted/40 border-b px-3 py-1.5 text-xs font-medium"
                                    >
                                        {{ server.name }}
                                        <span
                                            v-if="server.problem"
                                            class="text-destructive font-normal"
                                            >· {{ server.problem }}</span
                                        >
                                    </h4>
                                    <ul class="divide-y">
                                        <li
                                            v-for="tool in server.tools"
                                            :key="tool.name"
                                            class="flex items-start gap-3 px-3 py-2"
                                        >
                                            <Switch
                                                class="mt-0.5"
                                                :model-value="
                                                    given(
                                                        server.server,
                                                        tool.name,
                                                    )
                                                "
                                                :data-tool="tool.name"
                                                @update:model-value="
                                                    give(
                                                        server.server,
                                                        tool.name,
                                                        !!$event,
                                                    )
                                                "
                                            />
                                            <div class="min-w-0 flex-1">
                                                <p
                                                    class="flex items-center gap-1.5 text-sm"
                                                >
                                                    <span class="truncate">{{
                                                        tool.name
                                                    }}</span>
                                                    <span
                                                        v-if="!tool.reads"
                                                        class="shrink-0 rounded-full bg-amber-500/15 px-1.5 py-0.5 text-[10px] text-amber-700 dark:text-amber-400"
                                                        >changes things</span
                                                    >
                                                </p>
                                                <p
                                                    class="text-muted-foreground line-clamp-2 text-xs"
                                                >
                                                    {{ tool.description }}
                                                </p>
                                            </div>
                                        </li>
                                    </ul>
                                </section>
                            </template>
                        </div>
                        <div v-if="changed" class="grid gap-1.5">
                            <Label for="agent-note">What changed</Label>
                            <Input
                                id="agent-note"
                                v-model="draft.note"
                                maxlength="200"
                                placeholder="e.g. stricter about opening days"
                                data-test="agent-note"
                            />
                        </div>
                    </fieldset>
                    <div v-if="canManage" class="flex justify-end">
                        <Button
                            type="submit"
                            :disabled="!changed || saving"
                            data-test="agent-save"
                        >
                            Save as version {{ agent.version + 1 }}
                        </Button>
                    </div>
                </form>

                <!-- Trying it -->
                <section class="grid gap-2 rounded-xl border p-3">
                    <Label for="agent-try">Try it</Label>
                    <form class="flex gap-2" @submit.prevent="tryIt()">
                        <Input
                            id="agent-try"
                            v-model="message"
                            placeholder="What a customer might write"
                            data-test="agent-try-message"
                        />
                        <Button
                            type="submit"
                            size="icon"
                            :disabled="!message.trim() || trying !== null"
                            aria-label="Ask it"
                            data-test="agent-try"
                        >
                            <LoaderCircle
                                v-if="trying !== null"
                                class="animate-spin"
                            />
                            <Send v-else />
                        </Button>
                    </form>
                    <p v-if="failed" class="text-destructive text-xs">
                        {{ failed }}
                    </p>
                    <div
                        v-if="answer"
                        class="bg-muted/50 rounded-lg px-3 py-2"
                        data-test="agent-answer"
                    >
                        <p class="text-muted-foreground mb-1 text-[11px]">
                            Version {{ answer.version }} · {{ answer.model }} ·
                            {{ answer.found }} passages found
                        </p>
                        <p class="text-sm whitespace-pre-wrap">
                            {{ answer.text }}
                        </p>
                        <AnswerSources
                            :sources="answer.sources"
                            :knows="answer.knows"
                            :looked="answer.actions.length > 0"
                            class="mt-2"
                        />
                        <AnswerActions
                            :actions="answer.actions"
                            class="mt-1.5"
                        />
                    </div>
                </section>

                <!-- Every version -->
                <section class="grid gap-2">
                    <h3
                        class="text-muted-foreground flex items-center gap-1.5 text-xs font-medium tracking-wide uppercase"
                    >
                        <History class="size-3.5" /> Versions
                    </h3>
                    <ol class="divide-y rounded-xl border">
                        <li
                            v-for="version in agent.versions"
                            :key="version.number"
                            class="grid gap-1 px-3 py-2.5"
                            data-test="agent-version"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums"
                                    :class="
                                        version.number === agent.version
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted'
                                    "
                                    >v{{ version.number }}</span
                                >
                                <span class="min-w-0 flex-1 truncate text-sm">
                                    {{ version.model ?? 'no model' }}
                                    <span class="text-muted-foreground text-xs"
                                        >·
                                        {{
                                            version.at
                                                ? formatRelativeTime(version.at)
                                                : ''
                                        }}<template v-if="version.by">
                                            · {{ version.by }}</template
                                        ></span
                                    >
                                </span>
                                <button
                                    type="button"
                                    class="text-muted-foreground hover:text-foreground rounded-md p-1"
                                    :aria-label="`Show version ${version.number}`"
                                    :aria-expanded="shown === version.number"
                                    @click="
                                        shown =
                                            shown === version.number
                                                ? null
                                                : version.number
                                    "
                                >
                                    <ChevronDown
                                        class="size-4 transition-transform"
                                        :class="{
                                            'rotate-180':
                                                shown === version.number,
                                        }"
                                    />
                                </button>
                                <Button
                                    v-if="
                                        canManage &&
                                        version.number !== agent.version
                                    "
                                    variant="outline"
                                    size="sm"
                                    :data-test="`agent-use-${version.number}`"
                                    @click="useAgain(version.number)"
                                >
                                    <RotateCcw /> Use again
                                </Button>
                            </div>
                            <p
                                v-if="version.note"
                                class="text-muted-foreground text-xs"
                            >
                                {{ version.note }}
                            </p>
                            <div
                                v-if="shown === version.number"
                                class="grid gap-2 pt-1"
                            >
                                <p class="text-muted-foreground text-xs">
                                    {{ version.connection ?? 'No connection' }}
                                    · knows
                                    {{ version.knowledge ?? 'nothing more' }}
                                </p>
                                <pre
                                    class="bg-muted/50 max-h-60 overflow-auto rounded-md p-2 text-[11px] whitespace-pre-wrap"
                                    >{{
                                        version.instructions || '(none)'
                                    }}</pre>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="justify-self-start"
                                    :disabled="
                                        !message.trim() || trying !== null
                                    "
                                    :data-test="`agent-try-${version.number}`"
                                    @click="tryIt(version.number)"
                                >
                                    <Send /> Try this version with the message
                                    above
                                </Button>
                            </div>
                        </li>
                    </ol>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>
