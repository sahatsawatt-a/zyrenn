<script setup lang="ts">
import { Link as InertiaLink, router } from '@inertiajs/vue3';
import { LoaderCircle, Sparkles, X } from '@lucide/vue';
import { useLocalStorage } from '@vueuse/core';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AnswerActions from '@/features/chat/components/AnswerActions.vue';
import type { AnswerAction } from '@/features/chat/components/AnswerActions.vue';
import { KINDS, UNDERSTANDING } from '@/features/product/product';
import type { Element } from '@/features/product/product';
import TicketPick from '@/features/tickets/components/TicketPick.vue';
import { xsrfToken } from '@/lib/utils';
import { workOut } from '@/routes/product/requests';
import { store as keepRequirements } from '@/routes/product/requests/requirements';
import { index as projectAgents } from '@/routes/projects/agents';

// A request worked out by one of the project's agents into requirements: a
// draft to read and change here, kept only when someone keeps it -- and then
// as they left it, each still to be checked.

const props = defineProps<{
    request: string;
    project: string;
    agents: { ref_id: string; name: string }[];
    elements: Element[];
    reloaded: string[];
}>();

type Drafted = {
    keep: boolean;
    title: string;
    need: string;
    kind: string;
    understanding: string;
    // One a line, as they are written
    criteria: string;
    questions: string;
    assumptions: string;
    parts: string[];
};

const chosen = useLocalStorage('product.analyst', '');
const agent = computed(() =>
    props.agents.some((one) => one.ref_id === chosen.value)
        ? chosen.value
        : (props.agents[0]?.ref_id ?? ''),
);

const working = ref(false);
const failed = ref<string | null>(null);
const draft = ref<Drafted[] | null>(null);
const about = ref<{
    note: string | null;
    agent: string;
    version: number;
    model: string;
    actions: AnswerAction[];
} | null>(null);

const lines = (list: string[]) => list.join('\n');
const listed = (text: string) =>
    text
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);

async function ask() {
    working.value = true;
    failed.value = null;

    try {
        const response = await fetch(workOut(props.request).url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ agent: agent.value }),
        });
        const body = await response.json();

        if (!response.ok || body.failed) {
            failed.value =
                body.failed ??
                body.message ??
                'The agent could not work it out.';

            return;
        }

        draft.value = body.requirements.map((one: Record<string, never>) => ({
            keep: true,
            title: one.title,
            need: one.need ?? '',
            kind: one.kind,
            understanding: one.understanding,
            criteria: lines(one.criteria),
            questions: lines(one.questions),
            assumptions: lines(one.assumptions),
            parts: one.parts,
        }));
        about.value = {
            note: body.note,
            agent: body.agent,
            version: body.version,
            model: body.model,
            actions: body.actions ?? [],
        };
    } catch {
        failed.value = 'The agent could not be asked.';
    } finally {
        working.value = false;
    }
}

const kept = computed(
    () => draft.value?.filter((one) => one.keep && one.title.trim()) ?? [],
);

const keeping = ref(false);

function keep() {
    keeping.value = true;
    router.post(
        keepRequirements(props.request).url,
        {
            requirements: kept.value.map((one) => ({
                title: one.title.trim(),
                need: one.need.trim() || null,
                kind: one.kind,
                understanding: one.understanding,
                criteria: listed(one.criteria),
                questions: listed(one.questions),
                assumptions: listed(one.assumptions),
                parts: one.parts,
            })),
        },
        {
            preserveScroll: true,
            only: props.reloaded,
            onSuccess: () => {
                draft.value = null;
                about.value = null;
            },
            onError: (problems) =>
                (failed.value = Object.values(problems)[0] ?? null),
            onFinish: () => (keeping.value = false),
        },
    );
}

const nameOf = (code: string) =>
    props.elements.find((one) => one.code === code)?.name ?? '';

const agentOptions = computed(() =>
    props.agents.map((one) => ({ value: one.ref_id, label: one.name })),
);
</script>

<template>
    <section class="grid grid-cols-1 gap-3" data-test="work-out">
        <div class="flex flex-wrap items-center gap-2">
            <h3 class="flex items-center gap-1.5 text-sm font-medium">
                <Sparkles class="size-4 text-violet-500" /> Work it out with an
                agent
            </h3>
        </div>

        <p v-if="!agents.length" class="text-muted-foreground text-xs">
            No agents in this project yet:
            <InertiaLink
                :href="projectAgents(project).url"
                class="text-foreground underline underline-offset-2"
                >make one</InertiaLink
            >
            from "Product analyst".
        </p>

        <div v-else-if="!draft" class="flex flex-wrap items-center gap-2">
            <TicketPick
                v-if="agents.length > 1"
                :model-value="agent"
                :options="agentOptions"
                label="Agent"
                compact
                data-test="work-out-agent"
                @update:model-value="chosen = $event"
            />
            <Button
                size="sm"
                variant="outline"
                :disabled="working"
                data-test="work-out-ask"
                @click="ask"
            >
                <LoaderCircle v-if="working" class="animate-spin" />
                <Sparkles v-else />
                {{
                    working
                        ? 'Reading the request and the product…'
                        : agents.length > 1
                          ? 'Work it out'
                          : `Work it out with ${agents[0].name}`
                }}
            </Button>
        </div>

        <p
            v-if="failed"
            class="text-destructive text-xs"
            data-test="work-out-failed"
        >
            {{ failed }}
        </p>

        <template v-if="draft && about">
            <p class="text-muted-foreground text-xs">
                A draft by {{ about.agent }} (version {{ about.version }},
                {{ about.model }}): nothing is kept until you keep it.
                <template v-if="about.note">
                    <br /><span class="text-foreground">{{ about.note }}</span>
                </template>
            </p>
            <AnswerActions
                v-if="about.actions.length"
                :actions="about.actions"
            />

            <article
                v-for="(one, at) in draft"
                :key="at"
                class="grid grid-cols-1 gap-2 rounded-lg border p-3"
                :class="{ 'opacity-50': !one.keep }"
                data-test="work-out-requirement"
            >
                <div class="flex items-start gap-2">
                    <Checkbox
                        :model-value="one.keep"
                        @update:model-value="one.keep = $event === true"
                        class="mt-2"
                        :aria-label="`Keep: ${one.title}`"
                        data-test="work-out-keep-one"
                    />
                    <div class="grid min-w-0 flex-1 gap-2">
                        <!-- Each grows to what it says: a draft is read before it is kept -->
                        <textarea
                            v-model="one.title"
                            maxlength="200"
                            aria-label="What the product must do"
                            class="border-input focus-visible:ring-ring/50 field-sizing-content max-h-60 min-h-9 resize-none rounded-md border bg-transparent px-2.5 py-1.5 text-sm font-medium outline-none focus-visible:ring-[3px]"
                            data-test="work-out-title"
                            @keydown.enter.prevent
                        />
                        <textarea
                            v-model="one.need"
                            maxlength="5000"
                            placeholder="The need behind it"
                            aria-label="The need behind it"
                            class="border-input focus-visible:ring-ring/50 field-sizing-content max-h-60 min-h-9 resize-none rounded-md border bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:ring-[3px]"
                        />
                        <p class="text-muted-foreground text-[11px]">
                            {{
                                KINDS[one.kind as keyof typeof KINDS] ??
                                one.kind
                            }}
                            ·
                            {{
                                UNDERSTANDING[
                                    one.understanding as keyof typeof UNDERSTANDING
                                ] ?? one.understanding
                            }}
                        </p>
                        <label class="grid grid-cols-1 gap-1 text-xs">
                            <span class="text-muted-foreground"
                                >Checked against, one a line</span
                            >
                            <textarea
                                v-model="one.criteria"
                                class="border-input focus-visible:ring-ring/50 field-sizing-content max-h-60 min-h-9 resize-none rounded-md border bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:ring-[3px]"
                                data-test="work-out-criteria"
                            />
                        </label>
                        <label
                            v-if="one.questions"
                            class="grid grid-cols-1 gap-1 text-xs"
                        >
                            <span class="text-muted-foreground"
                                >Still to be asked</span
                            >
                            <textarea
                                v-model="one.questions"
                                class="border-input focus-visible:ring-ring/50 field-sizing-content max-h-60 min-h-9 resize-none rounded-md border bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:ring-[3px]"
                            />
                        </label>
                        <label
                            v-if="one.assumptions"
                            class="grid grid-cols-1 gap-1 text-xs"
                        >
                            <span class="text-muted-foreground"
                                >Assumed, not confirmed</span
                            >
                            <textarea
                                v-model="one.assumptions"
                                class="border-input focus-visible:ring-ring/50 field-sizing-content max-h-60 min-h-9 resize-none rounded-md border bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:ring-[3px]"
                            />
                        </label>
                        <div
                            v-if="one.parts.length"
                            class="flex flex-wrap gap-1.5"
                        >
                            <span
                                v-for="code in one.parts"
                                :key="code"
                                class="flex h-6 items-center gap-1 rounded-full border px-2 text-xs"
                                data-test="work-out-part"
                            >
                                <span
                                    class="text-muted-foreground font-mono text-[10px]"
                                    >{{ code }}</span
                                >
                                {{ nameOf(code) }}
                                <button
                                    type="button"
                                    class="text-muted-foreground"
                                    :aria-label="`Not about ${code}`"
                                    @click="
                                        one.parts = one.parts.filter(
                                            (c) => c !== code,
                                        )
                                    "
                                >
                                    <X class="size-3" />
                                </button>
                            </span>
                        </div>
                    </div>
                </div>
            </article>

            <div class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    :disabled="!kept.length || keeping"
                    data-test="work-out-keep"
                    @click="keep"
                >
                    Keep {{ kept.length }}
                    {{ kept.length === 1 ? 'requirement' : 'requirements' }}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    @click="((draft = null), (about = null))"
                >
                    Put it aside
                </Button>
            </div>
        </template>
    </section>
</template>
