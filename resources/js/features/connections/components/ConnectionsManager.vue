<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Check,
    CircleCheck,
    CircleX,
    Copy,
    Loader2,
    MoreHorizontal,
    Pencil,
    Plug,
    Plus,
    Trash2,
    Zap,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import ActivityList from '@/features/audit/ActivityList.vue';
import type { Activity } from '@/features/audit/ActivityList.vue';
import ConnectionForm from '@/features/connections/components/ConnectionForm.vue';
import { detailOf, GROUPS, lookOf } from '@/features/connections/services';
import type {
    CheckResult,
    SavedConnection,
    Service,
} from '@/features/connections/services';
import { destroy, test } from '@/routes/connections';

const props = defineProps<{
    connections: SavedConnection[];
    services: Record<string, Service>;
    can: { manage: boolean };
    urls: { store: string; check: string };
    // Said of a project's, where it matters who added each
    shared?: boolean;
    // Who added, changed or removed one lately
    activity?: Activity[];
}>();

const groups = computed(() =>
    GROUPS.map((group) => ({
        ...group,
        connections: props.connections.filter(
            (c) => props.services[c.service]?.group === group.key,
        ),
    })).filter((group) => group.connections.length),
);

// --- adding and changing, in a panel at the side ---
const open = ref(false);
const editing = ref<SavedConnection | null>(null);
const start = ref<string | null>(null);
// A new form for each opening, so nothing typed lingers
const opened = ref(0);

function add(service: string | null = null) {
    editing.value = null;
    start.value = service;
    opened.value++;
    open.value = true;
}

function edit(connection: SavedConnection) {
    editing.value = connection;
    start.value = null;
    opened.value++;
    open.value = true;
}

// Ways to begin when there is nothing yet
const starters = [
    {
        service: 'ollama',
        title: 'A model of your own',
        text: 'Ollama or LM Studio on your machine. No key needed.',
    },
    {
        service: 'openrouter',
        title: 'A hosted model',
        text: 'OpenRouter, OpenAI, Groq or Gemini, with your key.',
    },
    {
        service: 'telegram',
        title: 'A Telegram bot',
        text: 'For workflows that hear from and answer people.',
    },
];

// --- whether each still works ---
const checks = ref<Record<string, CheckResult | 'checking'>>({});

// A webhook address copied, to paste where the service asks for it
const copied = ref<string | null>(null);

async function copyHook(connection: SavedConnection) {
    if (!connection.hook?.url) {
        return;
    }

    await navigator.clipboard.writeText(connection.hook.url);
    copied.value = connection.ref_id;
    setTimeout(() => (copied.value = null), 1500);
}

async function check(connection: SavedConnection) {
    checks.value[connection.ref_id] = 'checking';

    try {
        const response = await fetch(test(connection.ref_id).url, {
            headers: { Accept: 'application/json' },
        });

        checks.value[connection.ref_id] = response.ok
            ? ((await response.json()) as CheckResult)
            : { ok: false, message: 'Could not check it just now.' };
    } catch {
        checks.value[connection.ref_id] = {
            ok: false,
            message: 'Could not check it just now.',
        };
    }
}

// --- removing ---
const removing = ref<SavedConnection | null>(null);
const removeOpen = ref(false);
const busy = ref(false);

function askRemove(connection: SavedConnection) {
    removing.value = connection;
    removeOpen.value = true;
}

function remove() {
    if (!removing.value) {
        return;
    }

    router.delete(destroy(removing.value.ref_id).url, {
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => {
            busy.value = false;
            removeOpen.value = false;
        },
    });
}
</script>

<template>
    <div class="space-y-8">
        <div v-if="connections.length && can.manage" class="flex justify-end">
            <Button data-connection-add @click="add()">
                <Plus /> Add connection
            </Button>
        </div>

        <!-- Nothing yet: ways to begin -->
        <div
            v-if="!connections.length"
            class="rounded-xl border border-dashed p-6 text-center"
        >
            <div
                class="bg-primary/10 text-primary mx-auto mb-3 flex size-11 items-center justify-center rounded-xl"
            >
                <Plug class="size-5" />
            </div>
            <h3 class="font-medium">No connections yet</h3>
            <p class="text-muted-foreground mx-auto mt-1 max-w-sm text-sm">
                {{
                    can.manage
                        ? 'Connect a model to have chats answered, or a bot and keys for workflows to use.'
                        : 'The project’s owners and editors can add them.'
                }}
            </p>
            <template v-if="can.manage">
                <div class="mt-5 grid gap-2 text-left sm:grid-cols-3">
                    <button
                        v-for="starter in starters"
                        :key="starter.service"
                        type="button"
                        class="hover:border-primary/40 hover:bg-muted/50 flex flex-col items-start rounded-lg border p-3 text-left transition-colors"
                        :data-starter="starter.service"
                        @click="add(starter.service)"
                    >
                        <span
                            class="mb-2 flex size-8 items-center justify-center rounded-md"
                            :class="lookOf(starter.service).tone"
                        >
                            <component
                                :is="lookOf(starter.service).icon"
                                class="size-4"
                            />
                        </span>
                        <span class="block text-sm font-medium">{{
                            starter.title
                        }}</span>
                        <span class="text-muted-foreground block text-xs">{{
                            starter.text
                        }}</span>
                    </button>
                </div>
                <Button variant="link" class="mt-2" @click="add()"
                    >Or choose from everything</Button
                >
            </template>
        </div>

        <section
            v-for="group in groups"
            :key="group.key"
            class="space-y-3"
            :data-group="group.key"
        >
            <div>
                <h3 class="text-sm font-medium">{{ group.title }}</h3>
                <p class="text-muted-foreground text-xs">
                    {{ group.description }}
                </p>
            </div>

            <ul class="divide-y rounded-xl border">
                <li
                    v-for="connection in group.connections"
                    :key="connection.ref_id"
                    class="flex items-center gap-3 p-3"
                    data-connection
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                        :class="lookOf(connection.service, group.key).tone"
                    >
                        <component
                            :is="lookOf(connection.service, group.key).icon"
                            class="size-4.5"
                        />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 truncate text-sm">
                            <span class="truncate font-medium">{{
                                connection.name
                            }}</span>
                            <span
                                class="text-muted-foreground shrink-0 text-xs"
                            >
                                {{ services[connection.service]?.label }}
                            </span>
                        </p>
                        <p class="text-muted-foreground truncate text-xs">
                            {{
                                detailOf(
                                    connection,
                                    services[connection.service],
                                )
                            }}
                            <template v-if="shared && connection.added_by">
                                · added by {{ connection.added_by }}</template
                            >
                        </p>
                        <div
                            v-if="connection.hook"
                            class="mt-1.5 grid grid-cols-1 gap-1"
                            data-connection-hook
                        >
                            <div
                                v-if="connection.hook.url"
                                class="flex items-center gap-1.5"
                            >
                                <code
                                    class="bg-muted/50 min-w-0 flex-1 truncate rounded border px-1.5 py-0.5 text-[11px]"
                                    :title="connection.hook.url"
                                    >{{ connection.hook.url }}</code
                                >
                                <Button
                                    size="icon-sm"
                                    variant="ghost"
                                    :aria-label="`Copy ${connection.name}'s webhook address`"
                                    @click="copyHook(connection)"
                                >
                                    <Check
                                        v-if="copied === connection.ref_id"
                                    />
                                    <Copy v-else />
                                </Button>
                            </div>
                            <p class="text-muted-foreground text-xs">
                                <template v-if="!connection.hook.url">
                                    Its webhook address shows here once
                                    WORKFLOWS_PUBLIC_URL says where the app is
                                    reached from outside.
                                </template>
                                <template
                                    v-else-if="
                                        connection.hook.set === 'by_hand'
                                    "
                                >
                                    Paste it in the LINE Developers console:
                                    Messaging API → Webhook URL, with “Use
                                    webhook” on.
                                </template>
                                <template v-else>
                                    Set for you when a workflow using this bot
                                    is switched on.
                                </template>
                            </p>
                        </div>
                        <p
                            v-if="checks[connection.ref_id]"
                            class="mt-1 flex items-center gap-1 text-xs"
                            :class="
                                checks[connection.ref_id] === 'checking'
                                    ? 'text-muted-foreground'
                                    : (checks[connection.ref_id] as CheckResult)
                                            .ok
                                      ? 'text-emerald-600 dark:text-emerald-400'
                                      : 'text-destructive'
                            "
                            data-connection-check
                        >
                            <template
                                v-if="checks[connection.ref_id] === 'checking'"
                            >
                                <Loader2 class="size-3.5 animate-spin" />
                                Checking…
                            </template>
                            <template v-else>
                                <CircleCheck
                                    v-if="
                                        (
                                            checks[
                                                connection.ref_id
                                            ] as CheckResult
                                        ).ok
                                    "
                                    class="size-3.5"
                                />
                                <CircleX v-else class="size-3.5" />
                                {{
                                    (checks[connection.ref_id] as CheckResult)
                                        .message
                                }}
                            </template>
                        </p>
                    </div>

                    <template v-if="can.manage">
                        <Button
                            v-if="services[connection.service]?.testable"
                            size="sm"
                            variant="ghost"
                            class="hidden sm:inline-flex"
                            :disabled="checks[connection.ref_id] === 'checking'"
                            @click="check(connection)"
                        >
                            <Zap /> Test
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    size="icon-sm"
                                    variant="ghost"
                                    :aria-label="`More for ${connection.name}`"
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem @select="edit(connection)">
                                    <Pencil /> Edit
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="
                                        services[connection.service]?.testable
                                    "
                                    class="sm:hidden"
                                    @select="check(connection)"
                                >
                                    <Zap /> Test
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    @select="askRemove(connection)"
                                >
                                    <Trash2 /> Remove
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                </li>
            </ul>
        </section>

        <p
            v-if="connections.length && !can.manage"
            class="text-muted-foreground text-xs"
        >
            Only the project’s owners and editors can add or change connections.
        </p>

        <section v-if="activity?.length" data-connections-activity>
            <h3 class="mb-2 text-sm font-medium">Recent changes</h3>
            <ActivityList :activity="activity" named />
        </section>
    </div>

    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-md">
            <SheetHeader>
                <SheetTitle>{{
                    editing ? `Edit “${editing.name}”` : 'Add a connection'
                }}</SheetTitle>
                <SheetDescription>
                    {{
                        editing
                            ? 'Change what it is called or where it points. Secrets left blank stay as they are.'
                            : 'Choose what to connect, then give what it needs.'
                    }}
                </SheetDescription>
            </SheetHeader>
            <ConnectionForm
                :key="opened"
                :services="services"
                :connection="editing"
                :start="start"
                :urls="urls"
                @done="open = false"
                @cancel="open = false"
            />
        </SheetContent>
    </Sheet>

    <DeleteDialog
        v-model:open="removeOpen"
        :name="removing?.name ?? ''"
        :busy="busy"
        description="Chats that used it stay, but are no longer answered. Workflows that use it stop working until they are given another."
        @confirm="remove"
    />
</template>
