<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Code, LoaderCircle, Plus, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import InputError from '@/components/common/InputError.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SegmentTabs from '@/components/common/SegmentTabs.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { settings as saveSettings } from '@/routes/admin/code';
import { destroy, store, update } from '@/routes/admin/code/libraries';
import Tip from '@/components/common/Tip.vue';

// What workflows' "Run code" may do, for the app's admins: the libraries each
// language may use, whether Python may be written at all, and how long and
// with how much memory code may run.

type Language = 'javascript' | 'python';

type Library = {
    id: number;
    language: Language;
    name: string;
    asked: string | null;
    version: string | null;
    status: 'installing' | 'ready' | 'failed';
    error: string | null;
    size: number | null;
    enabled: boolean;
    added_by: string | null;
};

type Settings = {
    python: boolean;
    seconds: Record<Language, number>;
    python_memory: number;
};

const props = defineProps<{
    settings: Settings;
    limits: { ceiling: Record<Language, number>; memory: [number, number] };
    libraries: Library[];
}>();

const language = ref<Language>('javascript');

const LANGUAGES = computed(() => [
    {
        value: 'javascript' as const,
        label: 'JavaScript',
        count: props.libraries.filter((one) => one.language === 'javascript')
            .length,
        test: 'code-javascript',
    },
    {
        value: 'python' as const,
        label: 'Python',
        count: props.libraries.filter((one) => one.language === 'python')
            .length,
        test: 'code-python',
    },
]);

const shown = computed(() =>
    props.libraries.filter((one) => one.language === language.value),
);

// ------------------------------------------------------------ Settings
const SECONDS = [5, 10, 15, 30, 45, 60, 90, 120];
const MEMORY = [128, 256, 384, 512, 768];

const secondsChoices = computed(() =>
    [...new Set([...SECONDS, props.settings.seconds[language.value]])]
        .filter((one) => one <= props.limits.ceiling[language.value])
        .sort((a, b) => a - b),
);

function saveSetting(change: Partial<Settings>) {
    router.patch(
        saveSettings().url,
        { ...props.settings, ...change },
        { preserveScroll: true },
    );
}

const setSeconds = (seconds: number) =>
    saveSetting({
        seconds: { ...props.settings.seconds, [language.value]: seconds },
    });

// ------------------------------------------------------------ Libraries
const name = ref('');
const version = ref('');
const errors = ref<Record<string, string>>({});
const adding = ref(false);

function add() {
    router.post(
        store().url,
        {
            language: language.value,
            name: name.value,
            version: version.value || null,
        },
        {
            preserveScroll: true,
            onStart: () => (adding.value = true),
            onFinish: () => (adding.value = false),
            onError: (problems) => (errors.value = problems),
            onSuccess: () => {
                errors.value = {};
                name.value = '';
                version.value = '';
            },
        },
    );
}

const setEnabled = (library: Library, enabled: boolean) =>
    router.patch(update(library.id).url, { enabled }, { preserveScroll: true });

// A name typed for one language is not one for the other
watch(language, () => {
    errors.value = {};
    name.value = '';
    version.value = '';
});

const removing = ref<Library | null>(null);
const removeOpen = computed({
    get: () => removing.value !== null,
    set: (open) => {
        if (!open) {
            removing.value = null;
        }
    },
});

function remove() {
    if (removing.value) {
        router.delete(destroy(removing.value.id).url, {
            preserveScroll: true,
            onFinish: () => (removing.value = null),
        });
    }
}

// While one installs, the list is asked again until it is done
let again: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.libraries,
    (libraries) => {
        clearTimeout(again);

        if (libraries.some((one) => one.status === 'installing')) {
            again = setTimeout(
                () => router.reload({ only: ['libraries'] }),
                2000,
            );
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(again));

const size = (bytes: number | null) =>
    bytes === null
        ? ''
        : bytes < 1024 * 1024
          ? `${Math.max(1, Math.round(bytes / 1024))} KB`
          : `${(bytes / 1024 / 1024).toFixed(1)} MB`;

const examples = computed(() =>
    language.value === 'python'
        ? 'numpy, pandas, python-dateutil'
        : 'lodash, dayjs, papaparse, mathjs',
);
</script>

<template>
    <Head title="Code" />

    <div class="mx-auto w-full max-w-3xl space-y-5 p-4 md:p-6">
        <PageHeader
            :icon="Code"
            title="Code"
            description="What workflows’ “Run code” may use, and how long it may run. Only admins see this."
        />

        <SegmentTabs v-model="language" :options="LANGUAGES" label="Language" />

        <!-- How it may run -->
        <section class="space-y-4 rounded-xl border p-4" data-code-settings>
            <div
                v-if="language === 'python'"
                class="flex items-start justify-between gap-4"
            >
                <div>
                    <p class="text-sm font-medium">Python may be written</p>
                    <p class="text-muted-foreground text-xs">
                        Off, steps can’t choose it, and those that have it stop.
                    </p>
                </div>
                <Switch
                    :model-value="settings.python"
                    aria-label="Python may be written"
                    data-test="code-python-on"
                    @update:model-value="saveSetting({ python: $event })"
                />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="code-seconds">Most a step may run</Label>
                    <Select
                        :model-value="String(settings.seconds[language])"
                        @update:model-value="setSeconds(Number($event))"
                    >
                        <SelectTrigger
                            id="code-seconds"
                            data-test="code-seconds"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="one in secondsChoices"
                                :key="one"
                                :value="String(one)"
                                >{{ one }} seconds</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="language === 'python'" class="grid gap-2">
                    <Label for="code-memory">Memory for a run</Label>
                    <Select
                        :model-value="String(settings.python_memory)"
                        @update:model-value="
                            saveSetting({ python_memory: Number($event) })
                        "
                    >
                        <SelectTrigger id="code-memory" data-test="code-memory">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="one in MEMORY"
                                :key="one"
                                :value="String(one)"
                                >{{ one }} MB</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <p v-else class="text-muted-foreground self-end text-xs">
                    JavaScript runs in 128 MB, always.
                </p>
            </div>
        </section>

        <!-- What it may use -->
        <section class="space-y-3">
            <h2 class="text-sm font-semibold">Libraries</h2>

            <form
                class="flex flex-wrap items-start gap-2"
                data-code-add
                @submit.prevent="add"
            >
                <div class="min-w-40 flex-1">
                    <Input
                        v-model="name"
                        :placeholder="examples"
                        aria-label="Package name"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        data-test="code-library-name"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="w-28">
                    <Input
                        v-model="version"
                        placeholder="latest"
                        aria-label="Version"
                        autocomplete="off"
                        data-test="code-library-version"
                    />
                    <InputError :message="errors.version" />
                </div>
                <Button
                    type="submit"
                    :disabled="adding || !name.trim()"
                    data-test="code-library-add"
                >
                    <Plus /> Add
                </Button>
            </form>
            <p class="text-muted-foreground text-xs">
                <template v-if="language === 'javascript'">
                    From npm, without its install scripts, bundled and tried
                    where code runs. Only libraries that just compute work: one
                    that needs files or the network is turned down. Code reaches
                    it by <code>require("name")</code>.
                </template>
                <template v-else>
                    The ones Python here comes with (numpy, pandas…), or pure
                    Python ones from PyPI. Code imports it by name.
                </template>
            </p>

            <ul
                v-if="shown.length"
                class="divide-y overflow-hidden rounded-xl border"
            >
                <li
                    v-for="library in shown"
                    :key="library.id"
                    class="flex items-center gap-3 px-3 py-2.5"
                    :data-code-library="library.name"
                    :data-status="library.status"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-mono text-sm">
                            {{ library.name }}
                            <span class="text-muted-foreground">{{
                                library.version ?? library.asked ?? ''
                            }}</span>
                        </p>
                        <p
                            v-if="library.status === 'installing'"
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <LoaderCircle class="size-3 animate-spin" />
                            Installing…
                        </p>
                        <p
                            v-else-if="library.status === 'failed'"
                            class="text-destructive text-xs break-words"
                        >
                            {{ library.error }}
                        </p>
                        <p v-else class="text-muted-foreground text-xs">
                            {{ size(library.size) }}
                            <template v-if="library.added_by">
                                · added by {{ library.added_by }}</template
                            >
                        </p>
                    </div>
                    <Switch
                        v-if="library.status === 'ready'"
                        :model-value="library.enabled"
                        :aria-label="`${library.name} may be used`"
                        @update:model-value="setEnabled(library, $event)"
                    />
                    <Tip :text="`Take ${library.name} away`">
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="`Take ${library.name} away`"
                            @click="removing = library"
                        >
                            <Trash2 />
                        </Button>
                    </Tip>
                </li>
            </ul>
            <p v-else class="text-muted-foreground py-6 text-center text-sm">
                None yet: code can use only what is added here.
            </p>
        </section>

        <DeleteDialog
            v-if="removing"
            v-model:open="removeOpen"
            :name="removing.name"
            description="Steps that use it stop, saying it is gone, until it is taken off them."
            @confirm="remove"
        />
    </div>
</template>
