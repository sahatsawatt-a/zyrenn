<script setup lang="ts">
import SearchField from '@/components/common/SearchField.vue';
import { router } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    LogIn,
    MoreHorizontal,
    Pencil,
    Plug,
    Plus,
    Search,
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
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import ActivityList from '@/features/audit/ActivityList.vue';
import type { Activity } from '@/features/audit/ActivityList.vue';
import { ago } from '@/features/browser/time';
import ConnectionForm from '@/features/connections/components/ConnectionForm.vue';
import {
    detailOf,
    GROUPS,
    lookOf,
    statusOf,
} from '@/features/connections/services';
import type {
    CheckResult,
    SavedConnection,
    Service,
    Status,
} from '@/features/connections/services';
import { destroy, test } from '@/routes/connections';
import Tip from '@/components/common/Tip.vue';

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

// --- finding one, once there are enough to need it ---
const query = ref('');
const searchable = computed(() => props.connections.length > 6);

const shown = computed(() => {
    const words = query.value.trim().toLowerCase().split(/\s+/).filter(Boolean);

    return words.length
        ? props.connections.filter((c) => {
              const said = [
                  c.name,
                  c.service,
                  props.services[c.service]?.label ?? '',
              ]
                  .join(' ')
                  .toLowerCase();

              return words.every((word) => said.includes(word));
          })
        : props.connections;
});

const groups = computed(() =>
    GROUPS.map((group) => ({
        ...group,
        connections: shown.value.filter(
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
// A test made on this page; the server keeps it too, and says it on the next visit
const checks = ref<Record<string, CheckResult | 'checking'>>({});

const statusFor = (connection: SavedConnection): Status | null =>
    props.services[connection.service]?.testable
        ? statusOf(
              checks.value[connection.ref_id],
              connection.check ?? null,
              ago,
          )
        : null;

const failing = computed(
    () =>
        props.connections.filter((c) => statusFor(c)?.kind === 'failing')
            .length,
);

const TONE: Record<Status['kind'], string> = {
    ok: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    failing: 'bg-destructive/10 text-destructive',
    untested: 'bg-muted text-muted-foreground',
    checking: 'bg-muted text-muted-foreground',
};
const DOT: Record<Status['kind'], string> = {
    ok: 'bg-emerald-500',
    failing: 'bg-destructive',
    untested: 'bg-muted-foreground/50',
    checking: 'bg-muted-foreground/50 animate-pulse',
};

async function check(connection: SavedConnection) {
    checks.value[connection.ref_id] = 'checking';

    try {
        const response = await fetch(test(connection.ref_id).url, {
            headers: { Accept: 'application/json' },
        });

        checks.value[connection.ref_id] = response.ok
            ? ((await response.json()) as CheckResult)
            : { ok: false, message: 'Could not test it just now.' };
    } catch {
        checks.value[connection.ref_id] = {
            ok: false,
            message: 'Could not test it just now.',
        };
    }
}

// An address of a connection's copied -- its webhook's, or the one it comes
// back to from signing in -- to paste where the service asks for it
const copied = ref<string | null>(null);

async function copyAddress(connection: SavedConnection, address: string) {
    await navigator.clipboard.writeText(address);
    copied.value = connection.ref_id;
    setTimeout(() => (copied.value = null), 1500);
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
    <div class="space-y-6">
        <!-- How many, how many need a look, finding one, adding one -->
        <div
            v-if="connections.length"
            class="flex flex-wrap items-center gap-x-3 gap-y-2"
        >
            <p class="text-muted-foreground text-sm">
                {{ connections.length }}
                {{ connections.length === 1 ? 'connection' : 'connections' }}
                <template v-if="failing">
                    ·
                    <span class="text-destructive font-medium"
                        >{{ failing }} failing</span
                    >
                </template>
            </p>
            <div
                class="ml-auto flex w-full items-center gap-2 sm:w-auto"
                :class="searchable ? '' : 'justify-end'"
            >
                <SearchField
                    v-if="searchable"
                    v-model="query"
                    class="flex-1 sm:w-56"
                    placeholder="Find a connection"
                    data-connections-find
                />
                <Button
                    v-if="can.manage"
                    class="shrink-0"
                    data-connection-add
                    @click="add()"
                >
                    <Plus /> Add connection
                </Button>
            </div>
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

        <p
            v-if="connections.length && !groups.length"
            class="text-muted-foreground py-6 text-center text-sm"
        >
            No connection is called “{{ query }}”.
        </p>

        <section
            v-for="group in groups"
            :key="group.key"
            class="space-y-2"
            :data-group="group.key"
        >
            <div class="flex items-baseline gap-2">
                <h3 class="text-sm font-medium">{{ group.title }}</h3>
                <span class="text-muted-foreground text-xs tabular-nums">{{
                    group.connections.length
                }}</span>
                <p
                    class="text-muted-foreground hidden truncate text-xs sm:block"
                >
                    · {{ group.description }}
                </p>
            </div>

            <ul class="divide-y rounded-xl border">
                <li
                    v-for="connection in group.connections"
                    :key="connection.ref_id"
                    class="p-3"
                    data-connection
                >
                    <div class="flex items-start gap-3">
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
                            <div
                                class="flex flex-wrap items-center gap-x-2 gap-y-1"
                            >
                                <span
                                    class="max-w-full truncate text-sm font-medium"
                                    >{{ connection.name }}</span
                                >
                                <span class="text-muted-foreground text-xs">
                                    {{ services[connection.service]?.label }}
                                </span>
                                <Tip
                                    v-if="statusFor(connection)"
                                    :text="
                                        statusFor(connection)!.message ??
                                        undefined
                                    "
                                >
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            TONE[statusFor(connection)!.kind]
                                        "
                                        data-connection-status
                                        :data-status="
                                            statusFor(connection)!.kind
                                        "
                                    >
                                        <span
                                            class="size-1.5 rounded-full"
                                            :class="
                                                DOT[statusFor(connection)!.kind]
                                            "
                                        />
                                        {{ statusFor(connection)!.text }}
                                    </span>
                                </Tip>
                            </div>
                            <p class="text-muted-foreground truncate text-xs">
                                {{
                                    detailOf(
                                        connection,
                                        services[connection.service],
                                    )
                                }}
                                <template v-if="shared && connection.added_by">
                                    · added by
                                    {{ connection.added_by }}</template
                                >
                            </p>
                            <!-- What a failing test said, or what one made just now said -->
                            <p
                                v-if="
                                    statusFor(connection)?.message &&
                                    (checks[connection.ref_id] ||
                                        statusFor(connection)!.kind ===
                                            'failing')
                                "
                                class="mt-1 text-xs"
                                :class="
                                    statusFor(connection)!.kind === 'failing'
                                        ? 'text-destructive'
                                        : 'text-emerald-700 dark:text-emerald-400'
                                "
                                data-connection-check
                            >
                                {{ statusFor(connection)!.message }}
                            </p>
                        </div>

                        <div
                            v-if="can.manage"
                            class="flex shrink-0 items-center"
                        >
                            <Button
                                v-if="services[connection.service]?.testable"
                                size="sm"
                                variant="ghost"
                                class="hidden sm:inline-flex"
                                :disabled="
                                    checks[connection.ref_id] === 'checking'
                                "
                                @click="check(connection)"
                            >
                                <Zap /> Test
                            </Button>
                            <Tip :text="`More for ${connection.name}`">
                                <span class="inline-flex">
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
                                            <DropdownMenuItem
                                                @select="edit(connection)"
                                            >
                                                <Pencil /> Edit
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="connection.sign_in"
                                                as-child
                                            >
                                                <a
                                                    :href="
                                                        connection.sign_in.url
                                                    "
                                                >
                                                    <LogIn />
                                                    {{
                                                        connection.sign_in
                                                            .state === 'no'
                                                            ? 'Sign in'
                                                            : 'Sign in again'
                                                    }}
                                                </a>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="
                                                    services[connection.service]
                                                        ?.testable
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
                                </span>
                            </Tip>
                        </div>
                    </div>

                    <!-- Its webhook: the address, and where it goes -->
                    <ol
                        v-if="connection.hook"
                        class="bg-muted/30 mt-3 space-y-2 rounded-lg border p-3 text-xs sm:ml-12"
                        data-connection-hook
                    >
                        <li class="flex gap-2">
                            <span
                                class="bg-background text-muted-foreground flex size-5 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium"
                                >1</span
                            >
                            <div class="min-w-0 flex-1 space-y-1">
                                <p class="font-medium">
                                    {{
                                        connection.hook.url
                                            ? 'Its webhook address'
                                            : 'Its webhook address is not ready yet'
                                    }}
                                </p>
                                <div
                                    v-if="connection.hook.url"
                                    class="flex items-center gap-1"
                                >
                                    <code
                                        class="bg-background min-w-0 flex-1 truncate rounded border px-1.5 py-1 text-[11px]"
                                        :title="connection.hook.url"
                                        >{{ connection.hook.url }}</code
                                    >
                                    <Tip
                                        :text="`Copy ${connection.name}'s webhook address`"
                                    >
                                        <Button
                                            size="icon-sm"
                                            variant="ghost"
                                            :aria-label="`Copy ${connection.name}'s webhook address`"
                                            @click="
                                                copyAddress(
                                                    connection,
                                                    connection.hook.url,
                                                )
                                            "
                                        >
                                            <Check
                                                v-if="
                                                    copied === connection.ref_id
                                                "
                                            />
                                            <Copy v-else />
                                        </Button>
                                    </Tip>
                                </div>
                                <p v-else class="text-muted-foreground">
                                    It shows here once WORKFLOWS_PUBLIC_URL says
                                    where the app is reached from outside.
                                </p>
                            </div>
                        </li>
                        <li v-if="connection.hook.url" class="flex gap-2">
                            <span
                                class="bg-background text-muted-foreground flex size-5 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium"
                                >2</span
                            >
                            <p class="text-muted-foreground min-w-0 flex-1">
                                <template v-if="connection.hook.hint">
                                    {{ connection.hook.hint }}
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
                                    Nothing to do: it is set for you when a
                                    workflow using this bot is switched on.
                                </template>
                            </p>
                        </li>
                    </ol>

                    <!-- Not signed in, or no longer: what the service has to be told, then the way there -->
                    <ol
                        v-if="
                            connection.sign_in &&
                            can.manage &&
                            connection.sign_in.state !== 'yes'
                        "
                        class="bg-muted/30 mt-3 space-y-2 rounded-lg border p-3 text-xs sm:ml-12"
                        data-connection-sign-in
                    >
                        <li class="flex gap-2">
                            <span
                                class="bg-background text-muted-foreground flex size-5 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium"
                                >1</span
                            >
                            <div class="min-w-0 flex-1 space-y-1">
                                <p class="text-muted-foreground">
                                    {{ connection.sign_in.hint }}
                                </p>
                                <div class="flex items-center gap-1">
                                    <code
                                        class="bg-background min-w-0 flex-1 truncate rounded border px-1.5 py-1 text-[11px]"
                                        :title="connection.sign_in.callback"
                                        >{{ connection.sign_in.callback }}</code
                                    >
                                    <Tip
                                        :text="`Copy the address ${connection.name} comes back to`"
                                    >
                                        <Button
                                            size="icon-sm"
                                            variant="ghost"
                                            :aria-label="`Copy the address ${connection.name} comes back to`"
                                            @click="
                                                copyAddress(
                                                    connection,
                                                    connection.sign_in.callback,
                                                )
                                            "
                                        >
                                            <Check
                                                v-if="
                                                    copied === connection.ref_id
                                                "
                                            />
                                            <Copy v-else />
                                        </Button>
                                    </Tip>
                                </div>
                            </div>
                        </li>
                        <li class="flex items-center gap-2">
                            <span
                                class="bg-background text-muted-foreground flex size-5 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium"
                                >2</span
                            >
                            <Button size="sm" as-child>
                                <a :href="connection.sign_in.url">
                                    <LogIn /> Sign in with
                                    {{ connection.sign_in.service }}
                                </a>
                            </Button>
                        </li>
                    </ol>
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
