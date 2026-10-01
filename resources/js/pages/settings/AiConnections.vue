<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Pencil, Plug, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ModelPicker from '@/components/chat/ModelPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { xsrfToken } from '@/lib/utils';
import {
    check as checkRoute,
    destroy,
    index,
    models,
    store,
    update,
} from '@/routes/ai-connections';

type Preset = {
    label: string;
    // Where the kind usually lives; empty when there is no usual place
    url: string;
    key_required: boolean;
    hint: string;
};

type Connection = {
    ref_id: string;
    name: string;
    kind: string;
    label: string;
    base_url: string;
    default_model: string | null;
    has_key: boolean;
};

const props = defineProps<{
    connections: Connection[];
    presets: Record<string, Preset>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'AI connections', href: index() }] },
});

// 'new' while adding; a ref id while changing that one
const editing = ref<string | 'new' | null>(null);

const form = useForm({
    name: '',
    kind: 'ollama',
    base_url: '',
    api_key: '',
    default_model: '',
});

const preset = computed(() => props.presets[form.kind]);
const changing = computed(() =>
    props.connections.find((c) => c.ref_id === editing.value),
);

// --- trying the form before it is kept ---
// What a try found, for the exact values it was made with: change one and it no longer applies
type Verdict = {
    signature: string;
    ok: boolean;
    text: string;
    models: string[];
    // Which of them cost nothing, when the host says
    free: string[] | null;
};

const verdict = ref<Verdict | null>(null);
const checking = ref(false);

const signature = () =>
    JSON.stringify([
        form.kind,
        form.base_url.trim(),
        form.api_key,
        editing.value,
    ]);

const current = computed(() =>
    verdict.value?.signature === signature() ? verdict.value : null,
);

async function tryIt(): Promise<boolean> {
    const asked = signature();

    checking.value = true;

    try {
        const response = await fetch(checkRoute().url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                kind: form.kind,
                base_url: form.base_url.trim() || null,
                api_key: form.api_key || null,
                connection: changing.value?.ref_id ?? null,
            }),
        });
        const body = await response.json();
        const message =
            body.message ??
            Object.values(body.errors ?? {})
                .flat()
                .join(' ');

        verdict.value =
            response.ok && body.ok
                ? {
                      signature: asked,
                      ok: true,
                      models: body.models,
                      free: body.free,
                      text: `Works: ${body.models.length} model${body.models.length === 1 ? '' : 's'} available.`,
                  }
                : {
                      signature: asked,
                      ok: false,
                      models: [],
                      free: null,
                      text: message || 'It did not answer.',
                  };
    } catch {
        verdict.value = {
            signature: asked,
            ok: false,
            models: [],
            free: null,
            text: 'Could not try it just now.',
        };
    } finally {
        checking.value = false;
    }

    return verdict.value?.ok ?? false;
}

function add() {
    form.reset();
    form.clearErrors();
    verdict.value = null;
    editing.value = 'new';
}

function edit(connection: Connection) {
    form.clearErrors();
    form.name = connection.name;
    form.kind = connection.kind;
    form.base_url = connection.base_url;
    form.api_key = '';
    form.default_model = connection.default_model ?? '';
    verdict.value = null;
    editing.value = connection.ref_id;
    void tryIt();
}

function pickKind(kind: string) {
    form.kind = kind;
    // The usual host of the old kind is not the new one's
    form.base_url = '';
}

function keep() {
    const done = {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    };

    if (editing.value === 'new') {
        form.post(store().url, done);
    } else if (editing.value) {
        form.patch(update(editing.value).url, done);
    }
}

// Saving tries it first. A host that fails is not kept unless asked again: it
// may be one that is switched off for now, or one that never lists its models.
async function save() {
    if (checking.value) {
        return;
    }

    if (!current.value && !(await tryIt())) {
        return;
    }

    keep();
}

function remove(connection: Connection) {
    if (
        confirm(
            `Remove “${connection.name}”? Chats that used it stay, but no longer get answers.`,
        )
    ) {
        router.delete(destroy(connection.ref_id).url, {
            preserveScroll: true,
        });
    }
}

// What a check of each saved connection found: its models, or why it failed
const checks = ref<Record<string, { ok: boolean; text: string }>>({});

async function checkSaved(connection: Connection) {
    checks.value[connection.ref_id] = { ok: true, text: 'Checking…' };

    try {
        const response = await fetch(models(connection.ref_id).url, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json();

        checks.value[connection.ref_id] = response.ok
            ? {
                  ok: true,
                  text: `Works: ${body.models.length} model${body.models.length === 1 ? '' : 's'} available.`,
              }
            : { ok: false, text: body.message ?? 'It did not answer.' };
    } catch {
        checks.value[connection.ref_id] = {
            ok: false,
            text: 'Could not check it just now.',
        };
    }
}
</script>

<template>
    <Head title="AI connections" />

    <h1 class="sr-only">AI connections</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="AI connections"
            description="Where your chats reach a model: a host, and your own key if it needs one. A key is kept encrypted and is only ever used for your chats."
        />

        <ul v-if="connections.length" class="divide-y rounded-lg border">
            <li
                v-for="connection in connections"
                :key="connection.ref_id"
                class="space-y-1 p-4"
                data-connection
            >
                <div class="flex items-center gap-3">
                    <Plug class="text-muted-foreground size-4 shrink-0" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">
                            {{ connection.name }}
                            <span
                                class="text-muted-foreground text-xs font-normal"
                            >
                                {{ connection.label }}
                                <template v-if="connection.has_key">
                                    · key saved</template
                                >
                            </span>
                        </p>
                        <p class="text-muted-foreground truncate text-xs">
                            {{ connection.base_url }}
                            <template v-if="connection.default_model"
                                >· {{ connection.default_model }}</template
                            >
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="checkSaved(connection)"
                        >Check</Button
                    >
                    <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Edit"
                        @click="edit(connection)"
                    >
                        <Pencil />
                    </Button>
                    <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Remove"
                        @click="remove(connection)"
                    >
                        <Trash2 />
                    </Button>
                </div>
                <p
                    v-if="checks[connection.ref_id]"
                    class="flex items-center gap-1.5 pl-7 text-xs"
                    :class="
                        checks[connection.ref_id].ok
                            ? 'text-muted-foreground'
                            : 'text-destructive'
                    "
                >
                    <Check
                        v-if="checks[connection.ref_id].ok"
                        class="size-3.5"
                    />
                    <X v-else class="size-3.5" />
                    {{ checks[connection.ref_id].text }}
                </p>
            </li>
        </ul>
        <p v-else-if="editing !== 'new'" class="text-muted-foreground text-sm">
            No connections yet. Add your Ollama, or a key for a hosted service,
            to have a chat answer.
        </p>

        <Button v-if="!editing" variant="outline" @click="add"
            >Add a connection</Button
        >

        <form
            v-else
            class="bg-muted/30 space-y-4 rounded-lg border p-4"
            data-connection-form
            @submit.prevent="save"
        >
            <h2 class="text-sm font-medium">
                {{ changing ? `Change “${changing.name}”` : 'New connection' }}
            </h2>

            <div class="grid gap-2">
                <Label>Kind</Label>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="(item, kind) in presets"
                        :key="kind"
                        type="button"
                        size="sm"
                        :variant="form.kind === kind ? 'default' : 'outline'"
                        :data-kind="kind"
                        @click="pickKind(kind)"
                    >
                        {{ item.label }}
                    </Button>
                </div>
                <p class="text-muted-foreground text-xs">{{ preset.hint }}</p>
                <InputError :message="form.errors.kind" />
            </div>

            <div class="grid gap-2">
                <Label for="ai-name">Name</Label>
                <Input
                    id="ai-name"
                    v-model="form.name"
                    required
                    maxlength="60"
                    :placeholder="`e.g. ${preset.label}`"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="ai-host">
                    Host
                    <span
                        v-if="preset.url"
                        class="text-muted-foreground font-normal"
                        >(leave empty for {{ preset.url }})</span
                    >
                </Label>
                <Input
                    id="ai-host"
                    v-model="form.base_url"
                    :placeholder="preset.url || 'https://llm.example.com/v1'"
                    :required="!preset.url"
                />
                <p
                    v-if="preset.url.startsWith('http://localhost')"
                    class="text-muted-foreground text-xs"
                >
                    <code>localhost</code> is the machine ZyrenN runs on, even
                    from Docker. A server in a container of its own is reached
                    by its name, such as <code>http://ollama:11434</code>, when
                    it shares a Docker network with the app.
                </p>
                <InputError :message="form.errors.base_url" />
            </div>

            <div class="grid gap-2">
                <Label for="ai-key">
                    Key
                    <span
                        v-if="!preset.key_required"
                        class="text-muted-foreground font-normal"
                        >(optional)</span
                    >
                </Label>
                <Input
                    id="ai-key"
                    v-model="form.api_key"
                    type="password"
                    autocomplete="off"
                    :placeholder="
                        changing?.has_key
                            ? 'Leave blank to keep the saved key'
                            : preset.key_required
                              ? 'Your key for this service'
                              : ''
                    "
                />
                <InputError :message="form.errors.api_key" />
            </div>

            <div class="grid gap-2">
                <Label>
                    Usual model
                    <span class="text-muted-foreground font-normal"
                        >(optional; each chat can choose its own)</span
                    >
                </Label>
                <ModelPicker
                    v-model="form.default_model"
                    :models="current?.models ?? []"
                    :free="current?.free ?? null"
                    :loading="checking"
                    :error="current && !current.ok ? current.text : null"
                    none="No usual model"
                />
                <p
                    v-if="!current && !checking"
                    class="text-muted-foreground text-xs"
                >
                    The models it offers are listed once the connection has been
                    tried; a name can be typed in any case.
                </p>
                <InputError :message="form.errors.default_model" />
            </div>

            <p
                v-if="current"
                class="flex items-start gap-1.5 text-sm"
                :class="
                    current.ok ? 'text-muted-foreground' : 'text-destructive'
                "
                role="status"
                data-connection-verdict
            >
                <Check v-if="current.ok" class="mt-0.5 size-4 shrink-0" />
                <X v-else class="mt-0.5 size-4 shrink-0" />
                <span>
                    {{ current.text }}
                    <template v-if="!current.ok">
                        It can still be saved as it is.
                    </template>
                </span>
            </p>

            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing || checking"
                    data-connection-save
                >
                    {{
                        checking
                            ? 'Trying it…'
                            : current && !current.ok
                              ? 'Save anyway'
                              : 'Save'
                    }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="checking"
                    data-connection-test
                    @click="tryIt"
                >
                    Test connection
                </Button>
                <Button type="button" variant="ghost" @click="editing = null"
                    >Cancel</Button
                >
            </div>
        </form>
    </div>
</template>
