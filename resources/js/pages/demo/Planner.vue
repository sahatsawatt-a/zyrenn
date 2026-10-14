<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    FlaskConical,
    List,
    Plus,
} from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SegmentTabs from '@/components/common/SegmentTabs.vue';
import { Button } from '@/components/ui/button';
import PostSheet from '@/features/planner/PostSheet.vue';
import { PLATFORMS, STATUSES, samplePosts } from '@/features/planner/planner';
import type { Platform, Post } from '@/features/planner/planner';

// The content planner, as a demo to try before it is built: posts on a
// calendar, opened to write one version for each platform, approve, schedule
// and publish -- through a workflow, platform by platform. Nothing is kept
// or posted: it all lives in this page.

const posts = reactive<Post[]>(samplePosts());

// ------------------------------------------------------------ How to look
// A phone gets the list: a month of small squares is no use there
const wide = useMediaQuery('(min-width: 768px)');
const chosenView = ref<'month' | 'list' | null>(null);
const view = computed(
    () => chosenView.value ?? (wide.value ? 'month' : 'list'),
);

const VIEWS = [
    {
        value: 'month' as const,
        title: 'A month',
        icon: CalendarDays,
        test: 'planner-month',
    },
    {
        value: 'list' as const,
        title: 'A list',
        icon: List,
        test: 'planner-list',
    },
];

const shown = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const monthName = computed(() =>
    new Intl.DateTimeFormat(undefined, {
        month: 'long',
        year: 'numeric',
    }).format(shown.value),
);
const move = (by: number) =>
    (shown.value = new Date(
        shown.value.getFullYear(),
        shown.value.getMonth() + by,
        1,
    ));

const sameDay = (a: Date, b: Date) =>
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate();

// Six weeks from the Monday on or before the first
const days = computed(() => {
    const first = new Date(shown.value);
    const start = new Date(first);
    start.setDate(1 - ((first.getDay() + 6) % 7));

    return Array.from({ length: 42 }, (_, at) => {
        const date = new Date(start);
        date.setDate(start.getDate() + at);

        return {
            date,
            inMonth: date.getMonth() === first.getMonth(),
            today: sameDay(date, new Date()),
            posts: posts
                .filter((post) => sameDay(new Date(post.at), date))
                .sort((a, b) => a.at.localeCompare(b.at)),
        };
    });
});

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const ordered = computed(() =>
    [...posts].sort((a, b) => a.at.localeCompare(b.at)),
);

const time = new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: '2-digit',
});
const when = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

const goingTo = (post: Post) =>
    (Object.keys(PLATFORMS) as Platform[]).filter(
        (platform) => post.versions[platform].on,
    );

// ---------------------------------------------------------- One post open
const openId = ref<number | null>(null);
const opened = computed(
    () => posts.find((post) => post.id === openId.value) ?? null,
);

function start(on: Date = new Date()) {
    const at = new Date(on);
    at.setHours(10, 0, 0, 0);

    posts.push({
        id: Math.max(0, ...posts.map((post) => post.id)) + 1,
        title: 'New post',
        text: '',
        pictures: [],
        versions: {
            facebook: { on: true, text: '' },
            instagram: { on: true, text: '' },
            threads: { on: true, text: '' },
            linkedin: { on: false, text: '' },
        },
        at: at.toISOString(),
        status: 'draft',
        workflow: 'Publish everywhere',
        results: {},
    });
    openId.value = posts[posts.length - 1].id;
}
</script>

<template>
    <Head title="Content planner" />

    <div class="mx-auto w-full max-w-6xl space-y-4 p-4 md:p-6">
        <PageHeader
            :icon="CalendarDays"
            title="Content planner"
            description="Posts planned on a calendar, one version for each platform, published through a workflow."
        >
            <Button data-test="planner-new" @click="start()">
                <Plus /> New post
            </Button>
        </PageHeader>

        <p
            class="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2 text-xs text-amber-700 dark:text-amber-400"
            data-test="planner-demo"
        >
            <FlaskConical class="size-4 shrink-0" />
            A demo to try: nothing here is kept, and nothing is posted.
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                variant="ghost"
                size="icon-sm"
                aria-label="The month before"
                @click="move(-1)"
            >
                <ChevronLeft />
            </Button>
            <h2
                class="min-w-36 text-center font-medium"
                data-test="planner-month-name"
            >
                {{ monthName }}
            </h2>
            <Button
                variant="ghost"
                size="icon-sm"
                aria-label="The month after"
                @click="move(1)"
            >
                <ChevronRight />
            </Button>
            <div class="ml-auto">
                <SegmentTabs
                    :model-value="view"
                    :options="VIEWS"
                    label="View"
                    @update:model-value="chosenView = $event"
                />
            </div>
        </div>

        <!-- A month -->
        <div
            v-if="view === 'month'"
            class="overflow-hidden rounded-xl border"
            data-test="planner-calendar"
        >
            <div
                class="bg-muted/40 grid grid-cols-7 border-b text-center text-xs font-medium"
            >
                <span
                    v-for="weekday in WEEKDAYS"
                    :key="weekday"
                    class="py-1.5"
                    >{{ weekday }}</span
                >
            </div>
            <div class="grid grid-cols-7">
                <div
                    v-for="cell in days"
                    :key="cell.date.toISOString()"
                    class="group relative min-h-28 border-r border-b p-1.5 [&:nth-child(7n)]:border-r-0"
                    :class="{
                        'bg-muted/30 text-muted-foreground': !cell.inMonth,
                    }"
                >
                    <div class="mb-1 flex items-center justify-between">
                        <span
                            class="flex size-6 items-center justify-center rounded-full text-xs"
                            :class="{
                                'bg-primary text-primary-foreground font-semibold':
                                    cell.today,
                            }"
                            >{{ cell.date.getDate() }}</span
                        >
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground rounded p-0.5 opacity-0 group-hover:opacity-100 focus:opacity-100"
                            :aria-label="`A post on ${cell.date.toDateString()}`"
                            @click="start(cell.date)"
                        >
                            <Plus class="size-3.5" />
                        </button>
                    </div>
                    <button
                        v-for="post in cell.posts"
                        :key="post.id"
                        type="button"
                        class="mb-1 flex w-full flex-col gap-0.5 rounded-md px-1.5 py-1 text-left text-[11px] leading-tight"
                        :class="STATUSES[post.status].tone"
                        data-test="planner-chip"
                        @click="openId = post.id"
                    >
                        <span class="truncate font-medium"
                            >{{ time.format(new Date(post.at)) }} ·
                            {{ post.title }}</span
                        >
                        <span class="flex gap-0.5">
                            <span
                                v-for="platform in goingTo(post)"
                                :key="platform"
                                class="size-1.5 rounded-full"
                                :class="PLATFORMS[platform].tone"
                                :title="PLATFORMS[platform].label"
                            />
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <!-- A list -->
        <ul
            v-else
            class="divide-y rounded-xl border"
            data-test="planner-agenda"
        >
            <li v-for="post in ordered" :key="post.id">
                <button
                    type="button"
                    class="hover:bg-accent/50 flex w-full items-center gap-3 px-4 py-3 text-left"
                    data-test="planner-row"
                    @click="openId = post.id"
                >
                    <img
                        v-if="post.pictures[0]"
                        :src="post.pictures[0].src"
                        alt=""
                        class="size-10 shrink-0 rounded-md object-cover"
                    />
                    <span v-else class="bg-muted size-10 shrink-0 rounded-md" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{
                            post.title
                        }}</span>
                        <span
                            class="text-muted-foreground block truncate text-xs"
                        >
                            {{ when.format(new Date(post.at)) }} ·
                            {{
                                goingTo(post)
                                    .map(
                                        (platform) => PLATFORMS[platform].label,
                                    )
                                    .join(', ') || 'nowhere yet'
                            }}
                        </span>
                    </span>
                    <span
                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="STATUSES[post.status].tone"
                        >{{ STATUSES[post.status].label }}</span
                    >
                </button>
            </li>
        </ul>

        <PostSheet
            v-if="opened"
            :open="openId !== null"
            :post="opened"
            @update:open="$event || (openId = null)"
        />
    </div>
</template>
