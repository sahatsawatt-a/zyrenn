<script setup lang="ts">
import {
    Check,
    CircleAlert,
    ExternalLink,
    ImagePlus,
    LoaderCircle,
    Send,
    Sparkles,
    Workflow,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DateTimeField from '@/components/common/DateTimeField.vue';
import SegmentTabs from '@/components/common/SegmentTabs.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import {
    PLATFORMS,
    SAMPLE_PICTURES,
    STATUSES,
    problemsOf,
    written,
} from '@/features/planner/planner';
import type { Platform, Post } from '@/features/planner/planner';

// One post, opened beside the calendar: what it says, its pictures, a
// version for each platform it goes to -- each checked against what that
// platform takes -- when it goes, and the workflow that takes it there.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{ post: Post }>();

const emit = defineEmits<{ changed: [] }>();

const ORDER = Object.keys(PLATFORMS) as Platform[];

const on = ref<Platform>('facebook');

const TABS = computed(() =>
    ORDER.map((platform) => ({
        value: platform,
        label: PLATFORMS[platform].label,
        // How many things stand in its way, said beside its name
        count: problemsOf(props.post, platform).length || undefined,
        test: `planner-tab-${platform}`,
    })),
);

const going = computed(() =>
    ORDER.filter((platform) => props.post.versions[platform].on),
);

const blocked = computed(() =>
    going.value.some((platform) => problemsOf(props.post, platform).length),
);

const when = computed({
    get: () => props.post.at.slice(0, 16),
    set: (value: string) => {
        props.post.at = new Date(value).toISOString();
        emit('changed');
    },
});

// The date field wants the time as it is here, not in UTC
const local = computed(() => {
    const at = new Date(props.post.at);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}T${pad(at.getHours())}:${pad(at.getMinutes())}`;
});

const addPicture = (at: number) => {
    props.post.pictures.push(SAMPLE_PICTURES[at % SAMPLE_PICTURES.length]);
    emit('changed');
};

// ------------------------------------------------- An agent writes versions
const writing = ref(false);

async function writeVersions() {
    writing.value = true;
    // As an agent would take a moment
    await new Promise((resolve) => setTimeout(resolve, 900));

    for (const platform of going.value) {
        props.post.versions[platform].text = written(props.post.text, platform);
    }

    writing.value = false;
    emit('changed');
}

// ------------------------------------------------------- Going out, step by step
const publishing = ref(false);

async function publish() {
    publishing.value = true;
    props.post.results = {};

    for (const platform of going.value) {
        props.post.results[platform] = { state: 'sending' };
        await new Promise((resolve) => setTimeout(resolve, 700));
        // One fails, as one sometimes does, to show how that reads
        props.post.results[platform] =
            platform === 'linkedin'
                ? {
                      state: 'failed',
                      why: 'LinkedIn refused the sign-in: it ran out. Sign in again on the connection, then try this one again.',
                  }
                : { state: 'posted', url: '#', likes: 0, comments: 0 };
    }

    const results = Object.values(props.post.results);
    props.post.status = results.some((result) => result?.state === 'failed')
        ? 'failed'
        : 'posted';
    publishing.value = false;
    emit('changed');
}

function retry(platform: Platform) {
    props.post.results[platform] = {
        state: 'posted',
        url: '#',
        likes: 0,
        comments: 0,
    };
    props.post.status = Object.values(props.post.results).every(
        (result) => result?.state === 'posted',
    )
        ? 'posted'
        : 'failed';
    emit('changed');
}

const setStatus = (status: Post['status']) => {
    props.post.status = status;
    emit('changed');
};
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-xl"
            data-test="planner-post"
        >
            <SheetHeader class="border-b px-5 py-4 text-left">
                <SheetTitle class="flex items-center gap-2">
                    <Input
                        v-model="post.title"
                        class="h-8 border-transparent px-1 text-base font-semibold shadow-none"
                        aria-label="What it is"
                        data-test="planner-title"
                    />
                    <span
                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="STATUSES[post.status].tone"
                        data-test="planner-status"
                        >{{ STATUSES[post.status].label }}</span
                    >
                </SheetTitle>
                <SheetDescription>
                    Goes out through the workflow "{{ post.workflow }}": your
                    own steps first, then each platform's.
                </SheetDescription>
            </SheetHeader>

            <div class="space-y-6 px-5 py-5">
                <!-- What it says, and its pictures -->
                <section class="grid gap-2">
                    <Label for="planner-text">What it says</Label>
                    <textarea
                        id="planner-text"
                        v-model="post.text"
                        rows="4"
                        class="border-input focus-visible:ring-ring/50 rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                        placeholder="Written once; each platform can have its own below"
                        data-test="planner-text"
                        @input="emit('changed')"
                    />
                    <div class="flex flex-wrap gap-2">
                        <figure
                            v-for="(picture, at) in post.pictures"
                            :key="at"
                            class="relative size-20 overflow-hidden rounded-lg border"
                        >
                            <img
                                :src="picture.src"
                                :alt="picture.name"
                                class="size-full object-cover"
                            />
                            <button
                                type="button"
                                class="bg-background/80 absolute top-1 right-1 rounded-full p-0.5"
                                :aria-label="`Take ${picture.name} off`"
                                @click="post.pictures.splice(at, 1)"
                            >
                                <X class="size-3" />
                            </button>
                        </figure>
                        <button
                            type="button"
                            class="text-muted-foreground hover:bg-accent flex size-20 flex-col items-center justify-center gap-1 rounded-lg border border-dashed text-[11px]"
                            data-test="planner-add-picture"
                            @click="addPicture(post.pictures.length)"
                        >
                            <ImagePlus class="size-4" />
                            From the Drive
                        </button>
                    </div>
                </section>

                <!-- A version for each platform -->
                <section class="grid gap-3">
                    <div class="flex items-center justify-between gap-2">
                        <Label>On each platform</Label>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="writing || !going.length"
                            data-test="planner-write"
                            @click="writeVersions"
                        >
                            <LoaderCircle v-if="writing" class="animate-spin" />
                            <Sparkles v-else />
                            Write versions with an agent
                        </Button>
                    </div>
                    <div class="max-w-full overflow-x-auto">
                        <SegmentTabs
                            v-model="on"
                            :options="TABS"
                            label="Platform"
                        />
                    </div>
                    <div
                        class="grid gap-2 rounded-xl border p-3"
                        :data-test="`planner-version-${on}`"
                    >
                        <label class="flex items-center gap-2 text-sm">
                            <Switch
                                v-model="post.versions[on].on"
                                :data-test="`planner-on-${on}`"
                                @update:model-value="emit('changed')"
                            />
                            Goes to {{ PLATFORMS[on].label }}
                        </label>
                        <template v-if="post.versions[on].on">
                            <textarea
                                v-model="post.versions[on].text"
                                rows="4"
                                class="border-input focus-visible:ring-ring/50 rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                                :placeholder="
                                    post.text ||
                                    'Its own words; left empty, what it says above'
                                "
                                :data-test="`planner-words-${on}`"
                                @input="emit('changed')"
                            />
                            <p
                                class="text-right text-[11px] tabular-nums"
                                :class="
                                    (post.versions[on].text || post.text)
                                        .length > PLATFORMS[on].most
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{
                                    (
                                        post.versions[on].text || post.text
                                    ).length.toLocaleString()
                                }}
                                / {{ PLATFORMS[on].most.toLocaleString() }}
                            </p>
                            <p
                                v-for="problem in problemsOf(post, on)"
                                :key="problem"
                                class="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400"
                                data-test="planner-problem"
                            >
                                <CircleAlert class="mt-px size-3.5 shrink-0" />
                                {{ problem }}
                            </p>
                        </template>
                    </div>
                </section>

                <!-- When, and who has said yes -->
                <section class="grid gap-2">
                    <Label for="planner-when">When it goes</Label>
                    <DateTimeField
                        id="planner-when"
                        kind="datetime"
                        label="When it goes"
                        :minute-step="15"
                        :model-value="local"
                        data-test="planner-when"
                        @update:model-value="when = $event"
                    />
                    <div class="flex flex-wrap gap-2 pt-1">
                        <Button
                            v-if="post.status === 'draft'"
                            variant="outline"
                            :disabled="blocked"
                            data-test="planner-approve"
                            @click="setStatus('approved')"
                        >
                            <Check /> Approve
                        </Button>
                        <Button
                            v-if="post.status === 'approved'"
                            variant="outline"
                            data-test="planner-schedule"
                            @click="setStatus('scheduled')"
                        >
                            <Workflow /> Schedule
                        </Button>
                        <Button
                            v-if="
                                ['approved', 'scheduled', 'failed'].includes(
                                    post.status,
                                )
                            "
                            :disabled="blocked || publishing"
                            data-test="planner-publish"
                            @click="publish"
                        >
                            <LoaderCircle
                                v-if="publishing"
                                class="animate-spin"
                            />
                            <Send v-else />
                            Publish now
                        </Button>
                        <p
                            v-if="blocked"
                            class="text-muted-foreground self-center text-xs"
                        >
                            Mend what each platform's tab says first.
                        </p>
                    </div>
                </section>

                <!-- How it went, platform by platform -->
                <section
                    v-if="Object.keys(post.results).length"
                    class="grid gap-2"
                    data-test="planner-results"
                >
                    <Label>How it went</Label>
                    <ul class="divide-y rounded-xl border">
                        <li
                            v-for="(result, platform) in post.results"
                            :key="platform"
                            class="flex items-start gap-3 px-3 py-2.5 text-sm"
                            :data-test="`planner-result-${platform}`"
                        >
                            <span
                                class="mt-1.5 size-2 shrink-0 rounded-full"
                                :class="PLATFORMS[platform].tone"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">
                                    {{ PLATFORMS[platform].label }}
                                </p>
                                <p
                                    v-if="result?.state === 'sending'"
                                    class="text-muted-foreground flex items-center gap-1 text-xs"
                                >
                                    <LoaderCircle class="size-3 animate-spin" />
                                    Going out…
                                </p>
                                <p
                                    v-else-if="result?.state === 'failed'"
                                    class="text-destructive text-xs"
                                >
                                    {{ result.why }}
                                </p>
                                <p
                                    v-else-if="result?.state === 'posted'"
                                    class="text-muted-foreground text-xs"
                                >
                                    Posted · {{ result.likes ?? 0 }} likes ·
                                    {{ result.comments ?? 0 }} comments
                                </p>
                            </div>
                            <a
                                v-if="result?.state === 'posted'"
                                :href="result.url"
                                class="text-primary flex items-center gap-1 text-xs underline-offset-2 hover:underline"
                                @click.prevent
                            >
                                Open <ExternalLink class="size-3" />
                            </a>
                            <Button
                                v-if="result?.state === 'failed'"
                                size="sm"
                                variant="outline"
                                :data-test="`planner-retry-${platform}`"
                                @click="retry(platform)"
                            >
                                Try again
                            </Button>
                        </li>
                    </ul>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>
