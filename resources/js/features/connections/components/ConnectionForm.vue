<script setup lang="ts">
import SearchField from '@/components/common/SearchField.vue';
import { useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    ChevronDown,
    ExternalLink,
    Info,
    Loader2,
    Lock,
    Search,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/common/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ModelPicker from '@/features/chat/components/ModelPicker.vue';
import {
    folded,
    GROUPS,
    guideOf,
    lookOf,
} from '@/features/connections/services';
import type {
    CheckResult,
    Field,
    SavedConnection,
    Service,
} from '@/features/connections/services';
import { xsrfToken } from '@/lib/utils';
import { update } from '@/routes/connections';

const props = defineProps<{
    services: Record<string, Service>;
    // The one being changed; null while adding
    connection: SavedConnection | null;
    // A service to start adding, skipping the choice
    start: string | null;
    urls: { store: string; check: string };
}>();

const emit = defineEmits<{ done: []; cancel: [] }>();

const form = useForm({
    name: props.connection?.name ?? '',
    service: props.connection?.service ?? props.start ?? '',
    settings: {} as Record<string, string>,
    secrets: {} as Record<string, string>,
    // The try made of exactly what is saved, kept as its first test
    tried: null as { ok: boolean; message: string } | null,
});

const service = computed<Service | undefined>(
    () => props.services[form.service],
);

// --- choosing what to connect: every service, in its group, found by a word ---
const query = ref('');

const choices = computed(() => {
    const words = query.value.trim().toLowerCase().split(/\s+/).filter(Boolean);

    return GROUPS.map((group) => ({
        ...group,
        services: Object.entries(props.services).filter(([name, item]) => {
            if (item.group !== group.key) {
                return false;
            }

            const said = [
                name,
                item.label,
                guideOf(name, item).summary,
                group.title,
            ]
                .join(' ')
                .toLowerCase();

            return words.every((word) => said.includes(word));
        }),
    })).filter((group) => group.services.length);
});

// An AI connection may move to another AI service; anything else stays what it is
const siblings = computed(() =>
    props.connection && service.value?.group === 'ai'
        ? Object.entries(props.services).filter(([, s]) => s.group === 'ai')
        : [],
);

// "More options" open; each service chosen starts it folded again
const more = ref(false);

function choose(name: string) {
    const chosen = props.services[name];
    const kept = props.connection?.settings ?? {};

    form.service = name;
    form.settings = Object.fromEntries(
        chosen.settings.map((field) => [
            field.name,
            // A usual host of the old AI service is not the new one's
            field.type === 'url' && name !== props.connection?.service
                ? ''
                : (kept[field.name] ?? field.default ?? ''),
        ]),
    );
    form.secrets = Object.fromEntries(chosen.secrets.map((f) => [f.name, '']));
    form.clearErrors();
    more.value = false;
}

if (form.service) {
    choose(form.service);
}

const guide = computed(() =>
    service.value ? guideOf(form.service, service.value) : null,
);

// A long hint is how to set it up, folded until asked for; a short one is shown
const hintFolded = computed(() => (service.value?.hint.length ?? 0) > 140);
const showHint = ref(false);

// --- the fields: the essentials in view, the rest under "More options" ---
type Placed = { field: Field; where: 'settings' | 'secrets' };

const fields = computed<Placed[]>(() =>
    service.value
        ? [
              ...service.value.settings.map((field) => ({
                  field,
                  where: 'settings' as const,
              })),
              ...service.value.secrets.map((field) => ({
                  field,
                  where: 'secrets' as const,
              })),
          ]
        : [],
);
const essentials = computed(() =>
    fields.value.filter(({ field }) => !folded(field, service.value!)),
);
const extras = computed(() =>
    fields.value.filter(({ field }) => folded(field, service.value!)),
);

// One spent on signing in is never kept: what it signed in to stands for it
const saved = (field: Field) =>
    field.spent
        ? (props.connection?.saved_secrets.length ?? 0) > 0
        : (props.connection?.saved_secrets.includes(field.name) ?? false);

const error = (where: 'settings' | 'secrets', field: Field) =>
    (form.errors as Record<string, string>)[`${where}.${field.name}`];

// A field folded away that the server refused opens the fold, so it is seen
const extraRefused = computed(() =>
    extras.value.some(({ field, where }) => error(where, field)),
);

// --- trying it before it is kept ---
// A try answers only for the exact values it was made with: change one and it no longer applies
const verdict = ref<(CheckResult & { signature: string }) | null>(null);
const checking = ref(false);

// Choosing a usual model changes nothing about whether the host answers
const signature = () =>
    JSON.stringify([
        form.service,
        Object.entries(form.settings).filter(
            ([name]) =>
                service.value?.settings.find((f) => f.name === name)?.type !==
                'model',
        ),
        form.secrets,
    ]);

const current = computed(() =>
    verdict.value?.signature === signature() ? verdict.value : null,
);

async function tryIt(): Promise<boolean> {
    const asked = signature();

    checking.value = true;

    try {
        const response = await fetch(props.urls.check, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                service: form.service,
                settings: form.settings,
                secrets: form.secrets,
                connection: props.connection?.ref_id ?? null,
            }),
        });
        const body = await response.json();

        verdict.value = {
            signature: asked,
            ...(response.ok
                ? (body as CheckResult)
                : {
                      ok: false,
                      message:
                          body.message ??
                          Object.values(body.errors ?? {})
                              .flat()
                              .join(' '),
                  }),
        };
    } catch {
        verdict.value = {
            signature: asked,
            ok: false,
            message: 'Could not try it just now.',
        };
    } finally {
        checking.value = false;
    }

    return verdict.value?.ok ?? false;
}

// An AI connection lists its models once tried, so editing one tries it at once
if (props.connection && service.value?.group === 'ai') {
    void tryIt();
}

function keep() {
    const done = { preserveScroll: true, onSuccess: () => emit('done') };

    form.tried = current.value
        ? { ok: current.value.ok, message: current.value.message.slice(0, 300) }
        : null;

    if (props.connection) {
        form.patch(update(props.connection.ref_id).url, done);
    } else {
        form.post(props.urls.store, done);
    }
}

// Saving tries it first. One that fails is kept only when asked again: it may
// be switched off for now, or a host that never lists its models.
async function save() {
    if (checking.value) {
        return;
    }

    if (service.value?.testable && !current.value && !(await tryIt())) {
        return;
    }

    keep();
}
</script>

<template>
    <!-- First, what to connect -->
    <div v-if="!service" class="flex min-h-0 flex-1 flex-col">
        <div class="px-4 pb-3">
            <SearchField
                id="connection-find"
                v-model="query"
                placeholder="Find a service: Telegram, OpenAI, email…"
                label="Find a service"
            />
        </div>
        <div class="flex-1 space-y-5 overflow-y-auto px-4 pb-6">
            <section
                v-for="group in choices"
                :key="group.key"
                class="space-y-1.5"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <h3 class="text-sm font-medium">{{ group.title }}</h3>
                    <span class="text-muted-foreground text-xs tabular-nums">{{
                        group.services.length
                    }}</span>
                </div>
                <p class="text-muted-foreground text-xs">
                    {{ group.description }}
                </p>
                <div class="divide-y rounded-lg border">
                    <button
                        v-for="[name, item] in group.services"
                        :key="name"
                        type="button"
                        class="hover:bg-muted/50 focus-visible:bg-muted/50 flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors outline-none first:rounded-t-lg last:rounded-b-lg"
                        :data-service="name"
                        @click="choose(name)"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-md"
                            :class="lookOf(name, item.group).tone"
                        >
                            <component
                                :is="lookOf(name, item.group).icon"
                                class="size-4"
                            />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{
                                item.label
                            }}</span>
                            <span
                                class="text-muted-foreground block truncate text-xs"
                                >{{ guideOf(name, item).summary }}</span
                            >
                        </span>
                    </button>
                </div>
            </section>
            <p
                v-if="!choices.length"
                class="text-muted-foreground py-8 text-center text-sm"
            >
                Nothing called “{{ query }}”. Any host that speaks the OpenAI
                chat API is under Other; any other API takes an API key.
            </p>
        </div>
    </div>

    <!-- Then, what it needs -->
    <form
        novalidate
        v-else
        class="flex min-h-0 flex-1 flex-col"
        data-connection-form
        autocomplete="off"
        @submit.prevent="save"
    >
        <div class="flex-1 space-y-5 overflow-y-auto px-4 pb-6">
            <div class="space-y-2 rounded-lg border p-3">
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-md"
                        :class="lookOf(form.service, service.group).tone"
                    >
                        <component
                            :is="lookOf(form.service, service.group).icon"
                            class="size-4.5"
                        />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ service.label }}</p>
                        <p class="text-muted-foreground truncate text-xs">
                            {{ guide?.summary }}
                        </p>
                    </div>
                    <Button
                        v-if="!connection && !start"
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="-mr-1 shrink-0"
                        @click="form.service = ''"
                    >
                        <ArrowLeft /> Change
                    </Button>
                </div>

                <p
                    v-if="!hintFolded"
                    class="text-muted-foreground text-xs leading-relaxed"
                >
                    {{ service.hint }}
                </p>

                <div
                    v-if="hintFolded || guide?.docs"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"
                >
                    <button
                        v-if="hintFolded"
                        type="button"
                        class="text-primary inline-flex items-center gap-1 font-medium hover:underline"
                        :aria-expanded="showHint"
                        data-connection-how
                        @click="showHint = !showHint"
                    >
                        <Info class="size-3.5" />
                        How to set it up
                        <ChevronDown
                            class="size-3.5 transition-transform"
                            :class="showHint ? 'rotate-180' : ''"
                        />
                    </button>
                    <a
                        v-if="guide?.docs"
                        :href="guide.docs.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-primary inline-flex items-center gap-1 font-medium hover:underline"
                        data-connection-docs
                    >
                        {{ guide.docs.label }}
                        <ExternalLink class="size-3.5" />
                    </a>
                </div>
                <p
                    v-if="hintFolded && showHint"
                    class="bg-muted/40 text-muted-foreground rounded-md p-2.5 text-xs leading-relaxed"
                >
                    {{ service.hint }}
                </p>
            </div>

            <div v-if="siblings.length" class="flex flex-wrap gap-1.5">
                <Button
                    v-for="[name, item] in siblings"
                    :key="name"
                    type="button"
                    size="sm"
                    :variant="form.service === name ? 'default' : 'outline'"
                    :data-service="name"
                    @click="choose(name)"
                >
                    {{ item.label }}
                </Button>
            </div>

            <div class="grid gap-2">
                <Label for="connection-name">Name</Label>
                <Input
                    id="connection-name"
                    v-model="form.name"
                    name="connection-name"
                    autocomplete="off"
                    required
                    maxlength="60"
                    :placeholder="`e.g. ${service.label}`"
                />
                <InputError :message="form.errors.name" />
            </div>

            <template
                v-for="(group, at) in [essentials, extras]"
                :key="at === 0 ? 'essentials' : 'extras'"
            >
                <button
                    v-if="at === 1 && group.length"
                    type="button"
                    class="text-muted-foreground hover:text-foreground flex w-full items-center gap-2 border-t pt-4 text-left text-sm font-medium"
                    :aria-expanded="more || extraRefused"
                    data-connection-more
                    @click="more = !more"
                >
                    More options
                    <span class="text-xs font-normal"
                        >({{ group.length }})</span
                    >
                    <ChevronDown
                        class="ml-auto size-4 transition-transform"
                        :class="more || extraRefused ? 'rotate-180' : ''"
                    />
                </button>
                <!-- Folded with v-show, so a folded field is still on the page -->
                <div
                    v-show="at === 0 || more || extraRefused"
                    class="space-y-5"
                >
                    <template
                        v-for="{ field, where } in group"
                        :key="`${where}.${field.name}`"
                    >
                        <div
                            v-if="
                                where === 'settings' && field.type === 'choice'
                            "
                            class="grid gap-2"
                        >
                            <Label>{{ field.label }}</Label>
                            <div class="grid gap-2" role="radiogroup">
                                <button
                                    v-for="(text, value) in field.options"
                                    :key="value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="
                                        form.settings[field.name] === value
                                    "
                                    class="flex items-start gap-2.5 rounded-lg border p-3 text-left text-sm transition-colors"
                                    :class="
                                        form.settings[field.name] === value
                                            ? 'border-primary bg-primary/5'
                                            : 'hover:bg-muted/50'
                                    "
                                    :data-choice="value"
                                    @click="
                                        form.settings[field.name] =
                                            String(value)
                                    "
                                >
                                    <span
                                        class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border"
                                        :class="
                                            form.settings[field.name] === value
                                                ? 'border-primary'
                                                : ''
                                        "
                                    >
                                        <span
                                            v-if="
                                                form.settings[field.name] ===
                                                value
                                            "
                                            class="bg-primary size-2 rounded-full"
                                        />
                                    </span>
                                    {{ text }}
                                </button>
                            </div>
                            <InputError :message="error('settings', field)" />
                        </div>

                        <div
                            v-else-if="
                                where === 'settings' && field.type === 'model'
                            "
                            class="grid gap-2"
                        >
                            <Label>
                                {{ field.label }}
                                <span class="text-muted-foreground font-normal"
                                    >(optional; each chat can choose its
                                    own)</span
                                >
                            </Label>
                            <ModelPicker
                                v-model="form.settings[field.name]"
                                :models="current?.models ?? []"
                                :free="current?.free ?? null"
                                :loading="checking"
                                :error="
                                    current && !current.ok
                                        ? current.message
                                        : null
                                "
                                none="No usual model"
                            />
                            <p
                                v-if="!current && !checking"
                                class="text-muted-foreground text-xs"
                            >
                                Its models are listed once it has been tested; a
                                name can be typed in any case.
                            </p>
                            <InputError :message="error('settings', field)" />
                        </div>

                        <div
                            v-else-if="where === 'settings'"
                            class="grid gap-2"
                        >
                            <Label :for="`connection-${field.name}`">
                                {{ field.label }}
                                <span
                                    v-if="field.type === 'url' && service.url"
                                    class="text-muted-foreground font-normal"
                                    >(leave empty for {{ service.url }})</span
                                >
                                <span
                                    v-else-if="!field.required"
                                    class="text-muted-foreground font-normal"
                                    >(optional)</span
                                >
                            </Label>
                            <Input
                                :id="`connection-${field.name}`"
                                v-model="form.settings[field.name]"
                                :name="`connection-${field.name}`"
                                autocomplete="off"
                                :placeholder="field.placeholder"
                                :required="
                                    field.required &&
                                    !service.url &&
                                    !field.default
                                "
                            />
                            <p
                                v-if="field.help"
                                class="text-muted-foreground text-xs"
                            >
                                {{ field.help }}
                            </p>
                            <p
                                v-if="
                                    field.type === 'url' &&
                                    service.url?.startsWith('http://localhost')
                                "
                                class="text-muted-foreground text-xs"
                            >
                                <code>localhost</code> is the machine ZyrenN
                                runs on, even from Docker. A server in a
                                container of its own is reached by its name,
                                such as <code>http://ollama:11434</code>.
                            </p>
                            <InputError :message="error('settings', field)" />
                        </div>

                        <div v-else class="grid gap-2">
                            <Label
                                :for="`connection-${field.name}`"
                                class="flex items-center gap-2"
                            >
                                {{ field.label }}
                                <span
                                    v-if="!field.required"
                                    class="text-muted-foreground font-normal"
                                    >(optional)</span
                                >
                                <Badge
                                    v-if="saved(field)"
                                    variant="secondary"
                                    class="font-normal"
                                >
                                    <Lock />
                                    {{ field.spent ? 'Signed in' : 'Saved' }}
                                </Badge>
                            </Label>
                            <Input
                                :id="`connection-${field.name}`"
                                v-model="form.secrets[field.name]"
                                type="password"
                                :name="`connection-secret-${field.name}`"
                                autocomplete="new-password"
                                data-1p-ignore
                                data-lpignore="true"
                                data-bwignore
                                data-form-type="other"
                                :placeholder="
                                    saved(field)
                                        ? field.spent
                                            ? 'Leave blank to stay signed in'
                                            : 'Leave blank to keep the saved one'
                                        : field.placeholder
                                "
                            />
                            <p
                                v-if="field.help"
                                class="text-muted-foreground text-xs"
                            >
                                {{ field.help }}
                            </p>
                            <InputError :message="error('secrets', field)" />
                        </div>
                    </template>
                </div>
            </template>

            <p
                v-if="service.secrets.length"
                class="text-muted-foreground flex items-start gap-1.5 text-xs"
            >
                <Lock class="mt-0.5 size-3.5 shrink-0" />
                Secrets are kept encrypted and never shown again, not even to
                you.
            </p>

            <div
                v-if="current"
                class="flex items-start gap-2 rounded-lg border p-3 text-sm"
                :class="
                    current.ok
                        ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-700 dark:text-emerald-400'
                        : 'border-destructive/30 bg-destructive/5 text-destructive'
                "
                role="status"
                data-connection-verdict
            >
                <Check v-if="current.ok" class="mt-0.5 size-4 shrink-0" />
                <X v-else class="mt-0.5 size-4 shrink-0" />
                <span>
                    {{ current.message }}
                    <template v-if="!current.ok">
                        It can still be saved as it is.
                    </template>
                </span>
            </div>
        </div>

        <div class="bg-background flex flex-wrap gap-2 border-t p-4">
            <Button
                type="submit"
                :disabled="form.processing || checking"
                data-connection-save
            >
                <Loader2 v-if="checking" class="animate-spin" />
                {{
                    checking
                        ? 'Testing…'
                        : current && !current.ok
                          ? 'Save anyway'
                          : 'Save'
                }}
            </Button>
            <Button
                v-if="service.testable"
                type="button"
                variant="outline"
                :disabled="checking"
                data-connection-test
                @click="tryIt"
            >
                Test
            </Button>
            <Button
                type="button"
                variant="ghost"
                class="ml-auto"
                @click="emit('cancel')"
                >Cancel</Button
            >
        </div>
    </form>
</template>
