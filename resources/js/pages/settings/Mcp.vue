<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import McpController from '@/actions/App/Http/Controllers/Settings/McpController';
import Heading from '@/components/common/Heading.vue';
import InputError from '@/components/common/InputError.vue';
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

type ClientId =
    | 'claude-code'
    | 'claude-desktop'
    | 'cursor'
    | 'vscode'
    | 'windsurf'
    | 'antigravity'
    | 'gemini'
    | 'codex'
    | 'zed'
    | 'cline'
    | 'other';

// What to paste for a client: a shell command, or the contents of a file
type Snippet = { where: string; text: string; note?: string };

const clients: { value: ClientId; label: string }[] = [
    { value: 'claude-code', label: 'Claude Code' },
    { value: 'claude-desktop', label: 'Claude Desktop' },
    { value: 'cursor', label: 'Cursor' },
    { value: 'vscode', label: 'VS Code' },
    { value: 'windsurf', label: 'Windsurf' },
    { value: 'antigravity', label: 'Antigravity' },
    { value: 'gemini', label: 'Gemini CLI' },
    { value: 'codex', label: 'Codex' },
    { value: 'zed', label: 'Zed' },
    { value: 'cline', label: 'Cline' },
    { value: 'other', label: 'Other' },
];

const clientKey = 'mcp-connect-client';

function readClient(): ClientId {
    try {
        const saved = localStorage.getItem(clientKey);

        return clients.some((item) => item.value === saved)
            ? (saved as ClientId)
            : 'claude-code';
    } catch {
        return 'claude-code';
    }
}

const client = ref<ClientId>(readClient());

watch(client, (value) => {
    try {
        localStorage.setItem(clientKey, value);
    } catch {
        // Storage unavailable (private mode): the choice just isn't remembered
    }
});

const json = (value: unknown): string => JSON.stringify(value, null, 2);

const bearer = computed(() => `Bearer ${tokenValue.value}`);

// The same server in the shape most clients take under "mcpServers"
const stdioServer = computed(() => ({
    command: 'docker',
    args: stdioArgs,
    env: { MCP_TOKEN: tokenValue.value },
}));

const snippet = computed<Snippet>(() => {
    const http = transport.value === 'http';
    const url = props.endpoint;
    const headers = { Authorization: bearer.value };

    switch (client.value) {
        case 'claude-code':
            return {
                where: 'Run in a terminal',
                text: http
                    ? `claude mcp add --transport http zyrenn ${url} --header "Authorization: ${bearer.value}"`
                    : `claude mcp add zyrenn -e MCP_TOKEN=${tokenValue.value} -- docker ${stdioArgs.join(' ')}`,
            };
        case 'claude-desktop':
            return {
                where: 'Settings → Developer → Edit Config (claude_desktop_config.json)',
                text: json({
                    mcpServers: {
                        zyrenn: http
                            ? {
                                  command: 'npx',
                                  args: [
                                      '-y',
                                      'mcp-remote',
                                      url,
                                      '--header',
                                      'Authorization:${ZYRENN_AUTH}',
                                  ],
                                  env: { ZYRENN_AUTH: bearer.value },
                              }
                            : stdioServer.value,
                    },
                }),
                note: http
                    ? 'Claude Desktop runs local commands, so this reaches the app through the mcp-remote bridge (needs Node.js). Restart Claude Desktop afterwards.'
                    : 'Restart Claude Desktop afterwards.',
            };
        case 'cursor':
            return {
                where: '~/.cursor/mcp.json, or Settings → MCP',
                text: json({
                    mcpServers: {
                        zyrenn: http ? { url, headers } : stdioServer.value,
                    },
                }),
            };
        case 'vscode':
            return {
                where: '.vscode/mcp.json, or “MCP: Open User Configuration”',
                text: json({
                    servers: {
                        zyrenn: http
                            ? { type: 'http', url, headers }
                            : { type: 'stdio', ...stdioServer.value },
                    },
                }),
                note: 'The top-level key is “servers”, not “mcpServers”.',
            };
        case 'windsurf':
            return {
                where: '~/.codeium/windsurf/mcp_config.json',
                text: json({
                    mcpServers: {
                        zyrenn: http
                            ? { serverUrl: url, headers }
                            : stdioServer.value,
                    },
                }),
                note: 'Press Refresh in the MCP panel afterwards.',
            };
        case 'antigravity':
            return {
                where: 'Agent panel → ⋯ → MCP Servers → Manage MCP Servers → View raw config',
                text: json({
                    mcpServers: {
                        zyrenn: http
                            ? { serverUrl: url, headers }
                            : stdioServer.value,
                    },
                }),
                note: 'Remote servers need “serverUrl”; Refresh after saving.',
            };
        case 'gemini':
            return {
                where: '~/.gemini/settings.json',
                text: json({
                    mcpServers: {
                        zyrenn: http
                            ? { httpUrl: url, headers }
                            : stdioServer.value,
                    },
                }),
            };
        case 'codex':
            return {
                where: '~/.codex/config.toml',
                text: http
                    ? `[mcp_servers.zyrenn]\nurl = "${url}"\nbearer_token_env_var = "ZYRENN_TOKEN"\n\n# and in your shell profile:\n# export ZYRENN_TOKEN=${tokenValue.value}`
                    : `[mcp_servers.zyrenn]\ncommand = "docker"\nargs = ${JSON.stringify(stdioArgs)}\nenv = { MCP_TOKEN = "${tokenValue.value}" }`,
            };
        case 'zed':
            return {
                where: 'Zed settings (zed: open settings)',
                text: json({
                    context_servers: {
                        zyrenn: http ? { url, headers } : stdioServer.value,
                    },
                }),
            };
        case 'cline':
            return {
                where: 'MCP Servers → Configure (cline_mcp_settings.json)',
                text: json({
                    mcpServers: {
                        zyrenn: http
                            ? { type: 'streamableHttp', url, headers }
                            : { type: 'stdio', ...stdioServer.value },
                    },
                }),
            };
        default:
            return {
                where: 'Any client that takes an MCP server',
                text: http
                    ? `URL:     ${url}\nHeader:  Authorization: ${bearer.value}`
                    : `Command: docker ${stdioArgs.join(' ')}\nEnv:     MCP_TOKEN=${tokenValue.value}`,
                note: 'Use a remote or streamable-HTTP server with a custom header. For a client that only runs local commands, bridge it: npx -y mcp-remote <url> --header "Authorization:Bearer <token>".',
            };
    }
});

// A bearer token over plain HTTP is readable on the network; fine on this machine only
const insecureRemote = computed(() => {
    try {
        const url = new URL(props.endpoint);

        return (
            transport.value === 'http' &&
            url.protocol === 'http:' &&
            !['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
        );
    } catch {
        return false;
    }
});

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
                description="Connect an AI client (Claude, Cursor, VS Code, Gemini, Codex, …) to your notes, boards and Drive. A token only ever sees your own content."
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
                <Label>Client</Label>
                <div
                    class="flex flex-wrap gap-1.5"
                    role="group"
                    aria-label="Client"
                >
                    <button
                        v-for="item in clients"
                        :key="item.value"
                        type="button"
                        class="rounded-md border px-2.5 py-1 text-sm transition-colors"
                        :class="
                            client === item.value
                                ? 'bg-foreground text-background border-foreground'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        :aria-pressed="client === item.value"
                        :data-test="`client-${item.value}`"
                        @click="client = item.value"
                    >
                        {{ item.label }}
                    </button>
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-muted-foreground min-w-0 text-xs">
                        {{ snippet.where }}
                    </p>
                    <Button
                        variant="ghost"
                        size="sm"
                        data-test="copy-snippet"
                        @click="copy('snippet', snippet.text)"
                    >
                        <Check v-if="copied === 'snippet'" />
                        <Copy v-else />
                        {{ copied === 'snippet' ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
                <pre
                    class="bg-muted overflow-x-auto rounded-md p-3 font-mono text-xs break-all whitespace-pre-wrap"
                    data-test="mcp-snippet"
                    >{{ snippet.text }}</pre>
                <p v-if="snippet.note" class="text-muted-foreground text-xs">
                    {{ snippet.note }}
                </p>
                <p
                    v-if="insecureRemote"
                    class="text-xs text-amber-700 dark:text-amber-400"
                    data-test="mcp-insecure"
                >
                    This address is plain http, so the token can be read on the
                    network. Serve the app over https before connecting from
                    another machine.
                </p>
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
