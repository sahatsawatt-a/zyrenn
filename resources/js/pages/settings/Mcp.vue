<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import McpController from '@/actions/App/Http/Controllers/Settings/McpController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { copyToClipboard } from '@/lib/utils';
import { edit } from '@/routes/mcp';

type McpToken = {
    id: number;
    name: string;
    created_at_diff: string | null;
    last_used_at_diff: string | null;
};

const props = defineProps<{
    endpoint: string;
    tokens: McpToken[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'MCP settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();

// Only present on the response right after creating a token
const newToken = computed(() => {
    const token = page.flash?.mcpToken;

    return typeof token === 'string' ? token : null;
});

const tokenValue = computed(() => newToken.value ?? '<your-token>');

type Transport = 'http' | 'stdio';

const transports: { value: Transport; label: string; hint: string }[] = [
    {
        value: 'http',
        label: 'HTTP',
        hint: 'Works from any device that can reach this app, e.g. over your LAN.',
    },
    {
        value: 'stdio',
        label: 'stdio',
        hint: 'Runs the server inside the app container. Only on the machine running the Docker stack, from the project folder.',
    },
];

const transportKey = 'mcp-connect-transport';

function readTransport(): Transport {
    try {
        return localStorage.getItem(transportKey) === 'stdio'
            ? 'stdio'
            : 'http';
    } catch {
        return 'http';
    }
}

const transport = ref<Transport>(readTransport());

watch(transport, (value) => {
    try {
        localStorage.setItem(transportKey, value);
    } catch {
        // Storage unavailable (private mode): the choice just isn't remembered
    }
});

const transportHint = computed(
    () => transports.find((item) => item.value === transport.value)?.hint,
);

// The personal stdio server reads the token from MCP_TOKEN (see routes/ai.php)
const stdioArgs = [
    'compose',
    'exec',
    '-T',
    '-e',
    'MCP_TOKEN',
    'app',
    'php',
    'artisan',
    'mcp:start',
    'zyrenn',
];

const claudeCommand = computed(() =>
    transport.value === 'http'
        ? `claude mcp add --transport http zyrenn ${props.endpoint} --header "Authorization: Bearer ${tokenValue.value}"`
        : `claude mcp add zyrenn -e MCP_TOKEN=${tokenValue.value} -- docker ${stdioArgs.join(' ')}`,
);

const jsonConfig = computed(() =>
    JSON.stringify(
        {
            mcpServers: {
                zyrenn:
                    transport.value === 'http'
                        ? {
                              type: 'http',
                              url: props.endpoint,
                              headers: {
                                  Authorization: `Bearer ${tokenValue.value}`,
                              },
                          }
                        : {
                              type: 'stdio',
                              command: 'docker',
                              args: stdioArgs,
                              env: { MCP_TOKEN: tokenValue.value },
                          },
            },
        },
        null,
        2,
    ),
);

const copied = ref<string | null>(null);

async function copy(key: string, text: string): Promise<void> {
    try {
        await copyToClipboard(text);
        copied.value = key;
        setTimeout(() => {
            if (copied.value === key) {
                copied.value = null;
            }
        }, 2000);
    } catch (error) {
        console.error('Failed to copy: ', error);
    }
}
</script>

<template>
    <Head title="MCP settings" />

    <h1 class="sr-only">MCP settings</h1>

    <div class="space-y-10">
        <div class="space-y-6">
            <Heading
                variant="small"
                title="MCP access"
                description="Connect an AI client (Claude Code, Claude Desktop, …) to your notes. Tokens only ever see your own notes."
            />

            <Form
                v-bind="McpController.store.form()"
                reset-on-success
                class="flex flex-col gap-2 sm:flex-row sm:items-end"
                v-slot="{ errors, processing }"
            >
                <div class="grid flex-1 gap-2">
                    <Label for="token-name">Token name</Label>
                    <Input
                        id="token-name"
                        name="name"
                        required
                        maxlength="60"
                        placeholder="e.g. Claude Code on laptop"
                    />
                    <InputError :message="errors.name" />
                </div>
                <Button type="submit" :disabled="processing">
                    <KeyRound />
                    Create token
                </Button>
            </Form>

            <div
                v-if="newToken"
                class="space-y-2 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-900/50 dark:bg-green-950/30"
            >
                <p class="text-sm font-medium">
                    Copy your new token now — it won't be shown again.
                </p>
                <div class="flex items-center gap-2">
                    <code
                        class="bg-background min-w-0 flex-1 truncate rounded border px-2 py-1.5 font-mono text-xs"
                        data-test="new-mcp-token"
                        >{{ newToken }}</code
                    >
                    <Button
                        variant="outline"
                        size="sm"
                        @click="copy('token', newToken)"
                    >
                        <Check v-if="copied === 'token'" />
                        <Copy v-else />
                        {{ copied === 'token' ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <Heading
                variant="small"
                title="Connect a client"
                :description="
                    newToken
                        ? 'Your new token is already filled in below.'
                        : 'Replace <your-token> with a token you created.'
                "
            />

            <div class="space-y-2">
                <div
                    class="bg-muted inline-flex rounded-lg p-1"
                    role="group"
                    aria-label="Transport"
                >
                    <button
                        v-for="item in transports"
                        :key="item.value"
                        type="button"
                        class="rounded-md px-3 py-1 text-sm font-medium transition-colors"
                        :class="
                            transport === item.value
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        :aria-pressed="transport === item.value"
                        :data-test="`transport-${item.value}`"
                        @click="transport = item.value"
                    >
                        {{ item.label }}
                    </button>
                </div>
                <p class="text-muted-foreground text-xs">
                    {{ transportHint }}
                </p>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <Label>Claude Code</Label>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="copy('command', claudeCommand)"
                    >
                        <Check v-if="copied === 'command'" />
                        <Copy v-else />
                        {{ copied === 'command' ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
                <pre
                    class="bg-muted overflow-x-auto rounded-md p-3 font-mono text-xs break-all whitespace-pre-wrap"
                    >{{ claudeCommand }}</pre>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <Label>JSON config (other clients)</Label>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="copy('json', jsonConfig)"
                    >
                        <Check v-if="copied === 'json'" />
                        <Copy v-else />
                        {{ copied === 'json' ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
                <pre
                    class="bg-muted overflow-x-auto rounded-md p-3 font-mono text-xs"
                    >{{ jsonConfig }}</pre>
            </div>
        </div>

        <div class="space-y-4">
            <Heading
                variant="small"
                title="Active tokens"
                description="Revoke a token to disconnect the client using it."
            />

            <p v-if="!tokens.length" class="text-muted-foreground text-sm">
                No tokens yet.
            </p>

            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="token in tokens"
                    :key="token.id"
                    class="flex items-center justify-between gap-4 px-4 py-3"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ token.name }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            Created {{ token.created_at_diff }} ·
                            {{
                                token.last_used_at_diff
                                    ? `Last used ${token.last_used_at_diff}`
                                    : 'Never used'
                            }}
                        </p>
                    </div>
                    <Form
                        v-bind="McpController.destroy.form(token.id)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            class="text-destructive"
                            :disabled="processing"
                        >
                            Revoke
                        </Button>
                    </Form>
                </li>
            </ul>
        </div>
    </div>
</template>
