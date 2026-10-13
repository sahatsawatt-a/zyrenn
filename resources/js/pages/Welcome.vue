<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    FolderKanban,
    HardDrive,
    MapPinned,
    MessagesSquare,
    NotebookPen,
    Presentation,
    Radio,
    ShieldCheck,
    Table2,
    Users,
    Workflow,
    Zap,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';
import AssistantTerminal from '@/features/welcome/components/AssistantTerminal.vue';
import LiveCursor from '@/features/welcome/components/LiveCursor.vue';
import ProductStage from '@/features/welcome/components/ProductStage.vue';
import Reveal from '@/features/welcome/components/Reveal.vue';
import ServiceMarquee from '@/features/welcome/components/ServiceMarquee.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { dashboard, login, register } from '@/routes';

const page = usePage();

const isAuthenticated = computed(() => Boolean(page.props.auth.user));

const primaryCta = computed(() =>
    isAuthenticated.value
        ? { href: dashboard(), label: 'Open Homepage' }
        : { href: register(), label: 'Create an account' },
);

// What there is to do here, as the sidebar names it; the wide ones carry a picture of their own
const features: {
    icon: Component;
    title: string;
    text: string;
    tone: 1 | 2 | 3 | 4 | 5;
    wide?: 'together' | 'projects' | 'workflows';
}[] = [
    {
        icon: NotebookPen,
        title: 'Notes',
        text: 'Write in blocks: headings, tasks, tables, maths, code and pictures. Type / for any of them.',
        tone: 1,
    },
    {
        icon: Presentation,
        title: 'Boards',
        text: 'An endless canvas of sticky notes, shapes, connectors and frames you can present.',
        tone: 3,
    },
    {
        icon: Users,
        title: 'Live, together',
        text: 'Write and draw at the same time as everyone else, and see their cursors and changes as they happen.',
        tone: 5,
        wide: 'together',
    },
    {
        icon: Table2,
        title: 'Tables',
        text: 'Rows and columns where each column is a kind: dates, choices, people, money, places.',
        tone: 4,
    },
    {
        icon: MapPinned,
        title: 'Maps and trips',
        text: 'Save places, plan a trip day by day, and see the route between its stops.',
        tone: 2,
    },
    {
        icon: FolderKanban,
        title: 'Projects',
        text: 'A shared space with notes, boards, tables and a Drive of its own. What is in it belongs to the project, not to whoever made it.',
        tone: 1,
        wide: 'projects',
    },
    {
        icon: HardDrive,
        title: 'Drive',
        text: 'Your files and pictures, kept privately, and put into notes and boards in a click.',
        tone: 2,
    },
    {
        icon: MessagesSquare,
        title: 'Chat',
        text: 'Message one person or a whole project, with files, places and pictures.',
        tone: 4,
    },
    {
        icon: Workflow,
        title: 'Workflows',
        text: 'Steps joined on a canvas that run by themselves: when this happens, do that, then that.',
        tone: 3,
        wide: 'workflows',
    },
];

const promises: { icon: Component; title: string; text: string }[] = [
    {
        icon: Radio,
        title: 'Starts by itself',
        text: 'On a message, a comment, a webhook, or the clock.',
    },
    {
        icon: Zap,
        title: 'One step, one call',
        text: 'Every step shows what went in and what came out.',
    },
    {
        icon: ShieldCheck,
        title: 'Never sends twice',
        text: 'A send that may have gone out asks before it goes again.',
    },
];
</script>

<template>
    <Head title="Welcome" />

    <PublicLayout>
        <main class="flex-1">
            <!-- hero -->
            <section class="relative">
                <!-- a faint grid that fades out downwards, and two glows behind the window -->
                <div
                    class="welcome-grid pointer-events-none absolute inset-0"
                    aria-hidden="true"
                />
                <div
                    class="pointer-events-none absolute top-40 left-1/2 size-152 -translate-x-[70%] rounded-full opacity-25 blur-3xl"
                    style="background: var(--chart-1)"
                    aria-hidden="true"
                />
                <div
                    class="pointer-events-none absolute top-72 left-1/2 size-128 translate-x-[5%] rounded-full opacity-20 blur-3xl"
                    style="background: var(--chart-2)"
                    aria-hidden="true"
                />

                <div
                    class="relative mx-auto max-w-5xl px-6 pt-16 pb-20 text-center sm:pt-24"
                >
                    <div
                        class="bg-card/70 border-border/70 text-muted-foreground mx-auto inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs backdrop-blur-sm"
                    >
                        <span class="relative flex size-2" aria-hidden="true">
                            <span
                                class="bg-chart-4 absolute inline-flex size-full animate-ping rounded-full opacity-60 motion-reduce:hidden"
                            />
                            <span
                                class="bg-chart-4 relative size-2 rounded-full"
                            />
                        </span>
                        Notes, boards, tables, trips and workflows
                    </div>

                    <h1
                        class="mx-auto mt-6 max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-6xl"
                    >
                        Everything you’re working on, in
                        <span
                            class="from-chart-1 to-chart-2 bg-linear-to-r bg-clip-text text-transparent"
                            >one live workspace</span
                        >
                    </h1>

                    <p
                        class="text-muted-foreground mx-auto mt-6 max-w-xl text-lg text-pretty"
                    >
                        Write, sketch, keep track and plan in
                        {{ $page.props.name }}, and let workflows handle the
                        busywork: on your own, or live with everyone in a
                        project.
                    </p>

                    <div
                        class="mt-9 flex flex-wrap items-center justify-center gap-3"
                    >
                        <Button as-child size="lg" class="gap-1.5">
                            <Link :href="primaryCta.href">
                                {{ primaryCta.label }}
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                        </Button>
                        <Button
                            v-if="!isAuthenticated"
                            as-child
                            size="lg"
                            variant="outline"
                        >
                            <Link :href="login()">Sign in</Link>
                        </Button>
                    </div>

                    <ProductStage class="mt-14" />
                </div>
            </section>

            <!-- what is here -->
            <section
                class="mx-auto w-full max-w-6xl px-6 py-20"
                aria-labelledby="features-heading"
            >
                <Reveal class="mx-auto max-w-2xl text-center">
                    <h2
                        id="features-heading"
                        class="text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        One place, instead of eight tabs
                    </h2>
                    <p class="text-muted-foreground mt-4 text-pretty">
                        Each part is good by itself. Together, a table sits in a
                        note, a trip shows on a board, and a workflow fills them
                        in.
                    </p>
                </Reveal>

                <ul class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <li
                        v-for="feature in features"
                        :key="feature.title"
                        :class="{ 'sm:col-span-2': feature.wide }"
                    >
                        <Reveal
                            class="bg-card border-border/70 hover:border-border group relative flex h-full gap-4 overflow-hidden rounded-2xl border p-4 hover:shadow-lg hover:shadow-black/5 sm:block sm:p-5"
                        >
                            <!-- on a phone the icon stands beside the words, and the list is half as long -->
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg transition-transform duration-300 group-hover:scale-110 motion-reduce:transition-none"
                                :style="{
                                    background: `color-mix(in oklab, var(--chart-${feature.tone}) 14%, transparent)`,
                                    color: `var(--chart-${feature.tone})`,
                                }"
                            >
                                <component
                                    :is="feature.icon"
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-semibold sm:mt-4">
                                    {{ feature.title }}
                                </h3>
                                <p
                                    class="text-muted-foreground mt-1 text-sm sm:mt-1.5"
                                    :class="{ 'max-w-sm': feature.wide }"
                                >
                                    {{ feature.text }}
                                </p>
                            </div>

                            <!-- three people in one document -->
                            <div
                                v-if="feature.wide === 'together'"
                                class="absolute top-5 right-5 hidden h-24 w-44 sm:block"
                                aria-hidden="true"
                            >
                                <div class="flex justify-end -space-x-2">
                                    <span
                                        v-for="[who, tone] in [
                                            ['MA', 5],
                                            ['KN', 2],
                                            ['SA', 4],
                                        ]"
                                        :key="who"
                                        class="ring-card flex size-8 items-center justify-center rounded-full text-[11px] font-semibold text-white ring-2"
                                        :style="{
                                            background: `var(--chart-${tone})`,
                                        }"
                                        >{{ who }}</span
                                    >
                                </div>
                                <LiveCursor
                                    name="Mai"
                                    :tone="5"
                                    class="welcome-drift top-12 left-2"
                                />
                                <LiveCursor
                                    name="Ken"
                                    :tone="2"
                                    class="welcome-drift welcome-drift-2 top-16 left-24"
                                />
                            </div>

                            <!-- three steps, joined -->
                            <div
                                v-if="feature.wide === 'workflows'"
                                class="absolute top-6 right-5 hidden items-center gap-1.5 text-xs sm:flex"
                                aria-hidden="true"
                            >
                                <template
                                    v-for="(step, at) in [
                                        'Message',
                                        'Ask AI',
                                        'Answer',
                                    ]"
                                    :key="step"
                                >
                                    <span
                                        v-if="at"
                                        class="bg-muted-foreground/40 h-px w-3"
                                    />
                                    <span
                                        class="bg-muted/60 border-border/60 rounded-md border px-2 py-1 font-medium"
                                        >{{ step }}</span
                                    >
                                </template>
                            </div>

                            <!-- who may do what in a project -->
                            <div
                                v-if="feature.wide === 'projects'"
                                class="absolute top-5 right-5 hidden flex-col items-end gap-1.5 text-xs sm:flex"
                                aria-hidden="true"
                            >
                                <span
                                    v-for="[role, can] in [
                                        ['Owner', 'runs it'],
                                        ['Editor', 'changes it'],
                                        ['Viewer', 'reads it'],
                                    ]"
                                    :key="role"
                                    class="bg-muted/60 border-border/60 rounded-full border px-2.5 py-1"
                                >
                                    <span class="font-medium">{{ role }}</span>
                                    <span class="text-muted-foreground">
                                        · {{ can }}</span
                                    >
                                </span>
                            </div>
                        </Reveal>
                    </li>
                </ul>
            </section>

            <!-- workflows -->
            <section
                class="border-border/60 bg-muted/30 border-y py-20"
                aria-labelledby="workflows-heading"
            >
                <Reveal class="mx-auto max-w-2xl px-6 text-center">
                    <div
                        class="bg-primary/10 text-primary mx-auto flex size-11 items-center justify-center rounded-xl"
                    >
                        <Workflow class="size-5" aria-hidden="true" />
                    </div>
                    <h2
                        id="workflows-heading"
                        class="mt-5 text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        Workflows that reach the services you already use
                    </h2>
                    <p class="text-muted-foreground mt-4 text-pretty">
                        Join steps on a canvas: when a message comes in on
                        Instagram, LINE or Telegram, ask AI, answer, and keep a
                        row of it. No code, unless you want a step of your own.
                    </p>
                </Reveal>

                <ServiceMarquee class="mt-10" />

                <ul
                    class="mx-auto mt-12 grid max-w-4xl gap-6 px-6 sm:grid-cols-3"
                >
                    <li
                        v-for="promise in promises"
                        :key="promise.title"
                        class="flex gap-3 sm:block sm:text-center"
                    >
                        <component
                            :is="promise.icon"
                            class="text-primary mt-0.5 size-5 shrink-0 sm:mx-auto sm:mt-0"
                            aria-hidden="true"
                        />
                        <div>
                            <h3 class="font-semibold sm:mt-2">
                                {{ promise.title }}
                            </h3>
                            <p class="text-muted-foreground mt-1 text-sm">
                                {{ promise.text }}
                            </p>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- an assistant, over MCP -->
            <section
                class="mx-auto grid w-full max-w-6xl items-center gap-10 px-6 py-20 lg:grid-cols-2"
                aria-labelledby="assistant-heading"
            >
                <Reveal>
                    <h2
                        id="assistant-heading"
                        class="text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        Your AI assistant can work here too
                    </h2>
                    <p class="text-muted-foreground mt-4 max-w-md text-pretty">
                        Connect an assistant over MCP and it reads and writes
                        your notes, boards, tables, trips and Drive, with the
                        rights you have and no more. Ask for a plan, and find it
                        in your workspace.
                    </p>
                </Reveal>
                <Reveal>
                    <AssistantTerminal />
                </Reveal>
            </section>

            <!-- the way in -->
            <section class="mx-auto w-full max-w-6xl px-6 pb-24">
                <Reveal
                    class="border-border/70 relative overflow-hidden rounded-3xl border px-6 py-14 text-center"
                >
                    <div
                        class="welcome-sheen pointer-events-none absolute inset-0"
                        aria-hidden="true"
                    />
                    <h2
                        class="relative text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        Start with one note
                    </h2>
                    <p
                        class="text-muted-foreground relative mx-auto mt-3 max-w-md text-pretty"
                    >
                        The rest is there when you need it.
                    </p>
                    <div
                        class="relative mt-8 flex flex-wrap items-center justify-center gap-3"
                    >
                        <Button as-child size="lg" class="gap-1.5">
                            <Link :href="primaryCta.href">
                                {{ primaryCta.label }}
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                        </Button>
                        <Button
                            v-if="!isAuthenticated"
                            as-child
                            size="lg"
                            variant="outline"
                        >
                            <Link :href="login()">Sign in</Link>
                        </Button>
                    </div>
                </Reveal>
            </section>
        </main>
    </PublicLayout>
</template>

<style scoped>
.welcome-grid {
    background-image:
        linear-gradient(
            to right,
            color-mix(in oklab, var(--border) 70%, transparent) 1px,
            transparent 1px
        ),
        linear-gradient(
            to bottom,
            color-mix(in oklab, var(--border) 70%, transparent) 1px,
            transparent 1px
        );
    background-size: 56px 56px;
    mask-image: radial-gradient(
        ellipse 70% 55% at 50% 0%,
        black 30%,
        transparent 75%
    );
}

.welcome-sheen {
    background:
        radial-gradient(
            ellipse 60% 90% at 20% 0%,
            color-mix(in oklab, var(--chart-1) 16%, transparent),
            transparent 70%
        ),
        radial-gradient(
            ellipse 60% 90% at 85% 100%,
            color-mix(in oklab, var(--chart-2) 14%, transparent),
            transparent 70%
        );
}

/* Two cursors wandering, as people at work in the same place do */
.welcome-drift {
    animation: drift 7s ease-in-out infinite alternate;
}

.welcome-drift-2 {
    animation-duration: 9s;
    animation-direction: alternate-reverse;
}

@keyframes drift {
    0% {
        transform: translate(0, 0);
    }

    50% {
        transform: translate(22px, -10px);
    }

    100% {
        transform: translate(-8px, 12px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-drift {
        animation: none;
    }
}
</style>
