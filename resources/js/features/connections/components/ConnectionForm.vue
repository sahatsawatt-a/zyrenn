<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Loader2, Lock, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/common/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ModelPicker from '@/features/chat/components/ModelPicker.vue';
import { GROUPS, lookOf } from '@/features/connections/services';
import type {
    CheckResult,
    Field,
    SavedConnection,
    Service,
    ServiceGroup,
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
});

const service = computed<Service | undefined>(
    () => props.services[form.service],
);

const servicesIn = (group: ServiceGroup) =>
    Object.entries(props.services).filter(([, s]) => s.group === group);

// An AI connection may move to another AI service; anything else stays what it is
const siblings = computed(() =>
    props.connection && service.value?.group === 'ai'
        ? Object.entries(props.services).filter(([, s]) => s.group === 'ai')
        : [],
);

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
}

if (form.service) {
    choose(form.service);
}

// One spent on signing in is never kept: what it signed in to stands for it
const saved = (field: Field) =>
    field.spent
        ? (props.connection?.saved_secrets.length ?? 0) > 0
        : (props.connection?.saved_secrets.includes(field.name) ?? false);

const error = (where: 'settings' | 'secrets', field: Field) =>
    (form.errors as Record<string, string>)[`${where}.${field.name}`];

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
    <div v-if="!service" class="flex-1 space-y-6 overflow-y-auto px-4 pb-6">
        <section v-for="group in GROUPS" :key="group.key" class="space-y-2">
            <div>
                <h3 class="text-sm font-medium">{{ group.title }}</h3>
                <p class="text-muted-foreground text-xs">
                    {{ group.description }}
                </p>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <button
                    v-for="[name, item] in servicesIn(group.key)"
                    :key="name"
                    type="button"
                    class="hover:border-primary/40 hover:bg-muted/50 focus-visible:ring-ring/50 flex items-center gap-2.5 rounded-lg border p-2.5 text-left transition-colors outline-none focus-visible:ring-[3px]"
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
                    <span class="truncate text-sm font-medium">{{
                        item.label
                    }}</span>
                </button>
            </div>
        </section>
    </div>

    <!-- Then, what it needs -->
    <form
        v-else
        class="flex min-h-0 flex-1 flex-col"
        data-connection-form
        autocomplete="off"
        @submit.prevent="save"
    >
        <div class="flex-1 space-y-5 overflow-y-auto px-4 pb-6">
            <div
                class="bg-muted/40 flex items-start gap-3 rounded-lg border p-3"
            >
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
                    <p class="text-muted-foreground text-xs leading-relaxed">
                        {{ service.hint }}
                    </p>
                </div>
                <Button
                    v-if="!connection && !start"
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="-mt-1 -mr-1"
                    @click="form.service = ''"
                >
                    <ArrowLeft /> Change
                </Button>
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

            <template v-for="field in service.settings" :key="field.name">
                <div v-if="field.type === 'choice'" class="grid gap-2">
                    <Label>{{ field.label }}</Label>
                    <div class="grid gap-2" role="radiogroup">
                        <button
                            v-for="(text, value) in field.options"
                            :key="value"
                            type="button"
                            role="radio"
                            :aria-checked="form.settings[field.name] === value"
                            class="flex items-start gap-2.5 rounded-lg border p-3 text-left text-sm transition-colors"
                            :class="
                                form.settings[field.name] === value
                                    ? 'border-primary bg-primary/5'
                                    : 'hover:bg-muted/50'
                            "
                            :data-choice="value"
                            @click="form.settings[field.name] = String(value)"
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
                                    v-if="form.settings[field.name] === value"
                                    class="bg-primary size-2 rounded-full"
                                />
                            </span>
                            {{ text }}
                        </button>
                    </div>
                    <InputError :message="error('settings', field)" />
                </div>

                <div v-else-if="field.type === 'model'" class="grid gap-2">
                    <Label>
                        {{ field.label }}
                        <span class="text-muted-foreground font-normal"
                            >(optional; each chat can choose its own)</span
                        >
                    </Label>
                    <ModelPicker
                        v-model="form.settings[field.name]"
                        :models="current?.models ?? []"
                        :free="current?.free ?? null"
                        :loading="checking"
                        :error="current && !current.ok ? current.message : null"
                        none="No usual model"
                    />
                    <p
                        v-if="!current && !checking"
                        class="text-muted-foreground text-xs"
                    >
                        Its models are listed once it has been tried; a name can
                        be typed in any case.
                    </p>
                    <InputError :message="error('settings', field)" />
                </div>

                <div v-else class="grid gap-2">
                    <Label :for="`connection-${field.name}`">
                        {{ field.label }}
                        <span
                            v-if="field.type === 'url' && service.url"
                            class="text-muted-foreground font-normal"
                            >(leave empty for {{ service.url }})</span
                        >
                    </Label>
                    <Input
                        :id="`connection-${field.name}`"
                        v-model="form.settings[field.name]"
                        :name="`connection-${field.name}`"
                        autocomplete="off"
                        :placeholder="field.placeholder"
                        :required="
                            field.required && !service.url && !field.default
                        "
                    />
                    <p
                        v-if="
                            field.type === 'url' &&
                            service.url?.startsWith('http://localhost')
                        "
                        class="text-muted-foreground text-xs"
                    >
                        <code>localhost</code> is the machine ZyrenN runs on,
                        even from Docker. A server in a container of its own is
                        reached by its name, such as
                        <code>http://ollama:11434</code>.
                    </p>
                    <InputError :message="error('settings', field)" />
                </div>
            </template>

            <div
                v-for="field in service.secrets"
                :key="field.name"
                class="grid gap-2"
            >
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
                        <Lock /> {{ field.spent ? 'Signed in' : 'Saved' }}
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
                <InputError :message="error('secrets', field)" />
            </div>

            <p class="text-muted-foreground flex items-start gap-1.5 text-xs">
                <Lock class="mt-0.5 size-3.5 shrink-0" />
                Secrets are kept encrypted. They are used from the server and
                never shown again, not even to you.
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
                        ? 'Trying it…'
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
