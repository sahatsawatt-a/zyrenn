<script setup lang="ts">
import { Head, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import {
    BookOpenText,
    ChevronDown,
    LoaderCircle,
    Plus,
    RefreshCw,
    Search,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch, watchEffect } from 'vue';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
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
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import TicketPick from '@/features/tickets/components/TicketPick.vue';
import { formatRelativeTime, xsrfToken } from '@/lib/utils';
import { ask, destroy, read, update } from '@/routes/knowledge';
import { index as ownKnowledge } from '@/routes/knowledge';
import { index as projectKnowledge } from '@/routes/projects/knowledge';
import { owned } from '@/lib/projects';

type Kind = 'notes' | 'table' | 'drive';
type Source = { kind: Kind; ref: string; label: string };

type Base = {
    ref_id: string;
    title: string;
    sources: Source[];
    connection: string | null;
    embed_model: string | null;
    state: 'idle' | 'reading' | 'failed';
    problem: string | null;
    read_at: string | null;
    passages: number;
};

type Found = {
    title: string;
    url: string | null;
    kind: string;
    text: string;
    score: number;
};

const props = defineProps<{
    bases: Base[];
    choices: {
        notes: string[];
        tables: { ref: string; title: string }[];
        drive: string[];
        connections: { ref_id: string; name: string; label: string }[];
    };
    can: { manage: boolean };
    urls: { store: string };
}>();

// Its name in the bar at the top, as every page has
watchEffect(() =>
    setLayoutProps({
        breadcrumbs: [
            {
                title: 'Knowledge',
                href: owned(ownKnowledge, projectKnowledge)(),
            },
        ],
    }),
);

const page = usePage();
const where = computed(() => page.props.project?.name ?? 'your own');

// ------------------------------------------------------------ Making one
const making = ref(false);
const title = ref('');

function make() {
    router.post(
        props.urls.store,
        { title: title.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                making.value = false;
                title.value = '';
            },
        },
    );
}

// --------------------------------------------------- Changing what it is
const change = (base: Base, changes: Record<string, unknown>) =>
    router.patch(update(base.ref_id).url, changes as never, {
        preserveScroll: true,
        only: ['bases'],
    });

const sourcesOf = (base: Base) =>
    base.sources.map(({ kind, ref }) => ({ kind, ref }));

const dropSource = (base: Base, at: number) =>
    change(base, { sources: sourcesOf(base).filter((_, i) => i !== at) });

// A source being added: what kind, then which
const adding = ref<string | null>(null);
const kind = ref<Kind>('notes');
const which = ref('');
const ALL = '__all__';

const KINDS = [
    { value: 'notes', label: 'Notes in a folder' },
    { value: 'table', label: 'A table' },
    { value: 'drive', label: 'Text files in a Drive folder' },
];

const whichOptions = computed(() =>
    kind.value === 'table'
        ? props.choices.tables.map((t) => ({ value: t.ref, label: t.title }))
        : [
              {
                  value: ALL,
                  label: kind.value === 'notes' ? 'All notes' : 'All files',
              },
              ...(kind.value === 'notes'
                  ? props.choices.notes
                  : props.choices.drive
              ).map((path) => ({ value: path, label: path })),
          ],
);

watch(kind, () => (which.value = whichOptions.value[0]?.value ?? ''));

function startAdding(base: Base) {
    kind.value = 'notes';
    which.value = ALL;
    adding.value = base.ref_id;
}

function addSource(base: Base) {
    if (!which.value) {
        return;
    }

    change(base, {
        sources: [
            ...sourcesOf(base),
            { kind: kind.value, ref: which.value === ALL ? '' : which.value },
        ],
    });
    adding.value = null;
}

const NONE = '__none__';

const connectionOptions = computed(() => [
    { value: NONE, label: 'None: find passages by their words' },
    ...props.choices.connections.map((c) => ({
        value: c.ref_id,
        label: `${c.name} · ${c.label}`,
    })),
]);

const readNow = (base: Base) =>
    router.post(read(base.ref_id).url, {}, { preserveScroll: true });

const removing = ref<Base | null>(null);

function remove() {
    if (removing.value) {
        router.delete(destroy(removing.value.ref_id).url, {
            preserveScroll: true,
            onFinish: () => (removing.value = null),
        });
    }
}

// While one is being read, the page asks how it is going
let polling: ReturnType<typeof setInterval> | null = null;

watch(
    () => props.bases.some((base) => base.state === 'reading'),
    (reading) => {
        if (reading && !polling) {
            polling = setInterval(
                () => router.reload({ only: ['bases'] }),
                2000,
            );
        } else if (!reading && polling) {
            clearInterval(polling);
            polling = null;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => polling && clearInterval(polling));

// -------------------------------------------------------- Trying it out
const questions = ref<Record<string, string>>({});
const answers = ref<Record<string, Found[] | string>>({});
const asking = ref<string | null>(null);

async function tryIt(base: Base) {
    const question = (questions.value[base.ref_id] ?? '').trim();

    if (!question) {
        return;
    }

    asking.value = base.ref_id;

    try {
        const response = await fetch(ask(base.ref_id).url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ question }),
        });
        const answer = await response.json();

        answers.value[base.ref_id] = response.ok
            ? answer.found
            : (answer.message ?? 'That could not be asked.');
    } catch {
        answers.value[base.ref_id] = 'That could not be asked.';
    } finally {
        asking.value = null;
    }
}

const settingsOpen = ref<Record<string, boolean>>({});
</script>

<template>
    <Head title="Knowledge" />

    <div class="mx-auto w-full max-w-4xl space-y-5 p-4 md:p-6">
        <PageHeader
            :icon="BookOpenText"
            title="Knowledge"
            :description="`What AI answers from, in ${where}: notes, tables and Drive files, read again every hour.`"
        >
            <Button
                v-if="can.manage"
                data-test="knowledge-new"
                @click="making = true"
            >
                <Plus /> New knowledge base
            </Button>
        </PageHeader>

        <EmptyState
            v-if="!bases.length"
            :icon="BookOpenText"
            title="No knowledge yet"
        >
            <p class="text-muted-foreground max-w-md text-sm">
                Gather what customers ask about -- opening hours, prices, how
                things work -- in notes or a table, then make a knowledge base
                of them for the "Search knowledge" step.
            </p>
        </EmptyState>

        <section
            v-for="base in bases"
            :key="base.ref_id"
            class="bg-card space-y-4 rounded-xl border p-4"
            data-test="knowledge-base"
        >
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="min-w-0 flex-1 truncate font-medium">
                    {{ base.title }}
                </h2>
                <span
                    class="flex items-center gap-1.5 text-xs"
                    :class="
                        base.state === 'failed'
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                    data-test="knowledge-state"
                >
                    <LoaderCircle
                        v-if="base.state === 'reading'"
                        class="size-3.5 animate-spin"
                    />
                    {{
                        base.state === 'reading'
                            ? 'Reading…'
                            : base.state === 'failed'
                              ? `Could not read: ${base.problem}`
                              : base.read_at
                                ? `${base.passages} passages · read ${formatRelativeTime(base.read_at)}`
                                : 'Not read yet'
                    }}
                </span>
                <Button
                    v-if="can.manage"
                    variant="outline"
                    size="sm"
                    :disabled="base.state === 'reading'"
                    data-test="knowledge-read"
                    @click="readNow(base)"
                >
                    <RefreshCw /> Read now
                </Button>
                <Button
                    v-if="can.manage"
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="`Delete ${base.title}`"
                    @click="removing = base"
                >
                    <Trash2 />
                </Button>
            </div>

            <!-- What it reads -->
            <div class="flex flex-wrap items-center gap-1.5">
                <span
                    v-for="(source, at) in base.sources"
                    :key="`${source.kind}:${source.ref}`"
                    class="bg-muted flex h-7 items-center gap-1 rounded-full pr-1 pl-3 text-xs"
                    data-test="knowledge-source"
                >
                    {{ source.label }}
                    <button
                        v-if="can.manage"
                        type="button"
                        class="text-muted-foreground hover:text-foreground rounded-full p-0.5"
                        :aria-label="`Stop reading ${source.label}`"
                        @click="dropSource(base, at)"
                    >
                        <X class="size-3" />
                    </button>
                </span>
                <Popover
                    v-if="can.manage"
                    :open="adding === base.ref_id"
                    @update:open="adding = $event ? base.ref_id : null"
                >
                    <PopoverTrigger as-child>
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground hover:bg-accent flex h-7 items-center gap-1 rounded-full border border-dashed px-3 text-xs"
                            data-test="knowledge-add-source"
                            @click="startAdding(base)"
                        >
                            <Plus class="size-3" /> Something to read
                        </button>
                    </PopoverTrigger>
                    <PopoverContent align="start" class="grid w-72 gap-2 p-3">
                        <TicketPick
                            v-model="kind"
                            :options="KINDS"
                            label="What"
                            data-test="knowledge-kind"
                        />
                        <TicketPick
                            v-if="whichOptions.length"
                            v-model="which"
                            :options="whichOptions"
                            label="Which"
                            data-test="knowledge-which"
                        />
                        <p v-else class="text-muted-foreground text-xs">
                            There is no table here yet.
                        </p>
                        <Button
                            size="sm"
                            :disabled="!which"
                            data-test="knowledge-add"
                            @click="addSource(base)"
                        >
                            Add
                        </Button>
                    </PopoverContent>
                </Popover>
            </div>

            <!-- How passages are found: by meaning, through a model, or by their words -->
            <div v-if="can.manage">
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground flex items-center gap-1 text-xs"
                    :aria-expanded="!!settingsOpen[base.ref_id]"
                    data-test="knowledge-settings"
                    @click="
                        settingsOpen[base.ref_id] = !settingsOpen[base.ref_id]
                    "
                >
                    <ChevronDown
                        class="size-3.5 transition-transform"
                        :class="{ 'rotate-180': settingsOpen[base.ref_id] }"
                    />
                    {{
                        base.connection && base.embed_model
                            ? `Found by meaning, with ${base.embed_model}`
                            : 'Found by their words'
                    }}
                </button>
                <div
                    v-if="settingsOpen[base.ref_id]"
                    class="mt-2 grid gap-3 sm:grid-cols-2"
                >
                    <div class="grid gap-1.5">
                        <Label class="text-xs">AI connection</Label>
                        <TicketPick
                            :model-value="base.connection ?? NONE"
                            :options="connectionOptions"
                            label="AI connection"
                            data-test="knowledge-connection"
                            @update:model-value="
                                change(base, {
                                    connection: $event === NONE ? null : $event,
                                })
                            "
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label class="text-xs" :for="`embed-${base.ref_id}`"
                            >Embedding model</Label
                        >
                        <Input
                            :id="`embed-${base.ref_id}`"
                            :model-value="base.embed_model ?? ''"
                            placeholder="nomic-embed-text"
                            data-test="knowledge-embed"
                            @change="
                                change(base, {
                                    embed_model: (
                                        $event.target as HTMLInputElement
                                    ).value,
                                })
                            "
                        />
                    </div>
                </div>
            </div>

            <!-- Trying it: what a question finds -->
            <form class="relative" @submit.prevent="tryIt(base)">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="questions[base.ref_id]"
                    class="pl-8"
                    placeholder="Try a question a customer might ask"
                    :disabled="!base.read_at"
                    data-test="knowledge-question"
                />
            </form>
            <LoaderCircle
                v-if="asking === base.ref_id"
                class="text-muted-foreground size-4 animate-spin"
            />
            <p
                v-else-if="typeof answers[base.ref_id] === 'string'"
                class="text-destructive text-xs"
            >
                {{ answers[base.ref_id] }}
            </p>
            <ol
                v-else-if="answers[base.ref_id]"
                class="grid gap-2"
                data-test="knowledge-found"
            >
                <li
                    v-for="(found, at) in answers[base.ref_id] as Found[]"
                    :key="at"
                    class="rounded-lg border px-3 py-2"
                >
                    <p class="flex items-center gap-2 text-xs">
                        <a
                            v-if="found.url"
                            :href="found.url"
                            class="text-primary truncate font-medium underline-offset-2 hover:underline"
                            >{{ found.title }}</a
                        >
                        <span v-else class="truncate font-medium">{{
                            found.title
                        }}</span>
                        <span class="text-muted-foreground ml-auto tabular-nums"
                            >{{ Math.round(found.score * 100) }}%</span
                        >
                    </p>
                    <p
                        class="text-muted-foreground mt-1 line-clamp-3 text-xs whitespace-pre-line"
                    >
                        {{ found.text }}
                    </p>
                </li>
                <li
                    v-if="!(answers[base.ref_id] as Found[]).length"
                    class="text-muted-foreground text-xs"
                >
                    Nothing in it answers that.
                </li>
            </ol>
        </section>

        <Dialog v-model:open="making">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>New knowledge base</DialogTitle>
                    <DialogDescription>
                        It starts by reading every note; choose what else it
                        reads after.
                    </DialogDescription>
                </DialogHeader>
                <form id="knowledge-form" @submit.prevent="make">
                    <Label for="knowledge-title" class="sr-only">Name</Label>
                    <Input
                        id="knowledge-title"
                        v-model="title"
                        required
                        maxlength="120"
                        placeholder="e.g. Shop answers"
                        data-test="knowledge-title"
                    />
                </form>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="making = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        form="knowledge-form"
                        :disabled="!title.trim()"
                        data-test="knowledge-make"
                    >
                        Make it
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <DeleteDialog
            :open="removing !== null"
            :name="removing?.title ?? ''"
            description="What it read stays where it is: only what it found goes."
            @update:open="$event || (removing = null)"
            @confirm="remove"
        />
    </div>
</template>
