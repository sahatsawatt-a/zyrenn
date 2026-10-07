<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    ArrowRight,
    FolderKanban,
    HardDrive,
    NotebookPen,
    Presentation,
    Table2,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import AppLogoIcon from '@/components/app/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const page = usePage();

const isAuthenticated = computed(() => Boolean(page.props.auth.user));

const primaryCta = computed(() =>
    isAuthenticated.value
        ? { href: dashboard(), label: 'Open Homepage' }
        : { href: register(), label: 'Create an account' },
);

// What there is to do here, as the sidebar names it
const features: { icon: Component; title: string; text: string }[] = [
    {
        icon: NotebookPen,
        title: 'Notes',
        text: 'Write in blocks: headings, tasks, tables, maths, code and pictures. Type / for any of them.',
    },
    {
        icon: Presentation,
        title: 'Boards',
        text: 'An endless canvas of sticky notes, shapes, connectors and frames you can present.',
    },
    {
        icon: Table2,
        title: 'Tables',
        text: 'Rows and columns where each column is a kind: dates, choices, people, money and more.',
    },
    {
        icon: HardDrive,
        title: 'Drive',
        text: 'Your files and pictures, kept privately, and put into notes and boards in a click.',
    },
    {
        icon: FolderKanban,
        title: 'Projects',
        text: 'A shared space with notes, boards, tables and a Drive of its own. What is in it belongs to the project.',
    },
    {
        icon: Users,
        title: 'Live, together',
        text: 'Write and draw at the same time as everyone else, and see their cursors and changes as they happen.',
    },
];
</script>

<template>
    <Head title="Welcome" />

    <!-- clip, not hidden: the hero's glow is wider than a phone, and a clip
         keeps the sticky header working -->
    <div
        class="bg-background text-foreground flex min-h-svh flex-col overflow-x-clip"
    >
        <header
            class="border-border/60 sticky top-0 z-10 border-b backdrop-blur-sm"
        >
            <nav
                class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6"
            >
                <Link
                    :href="'/'"
                    class="-my-2 flex min-h-11 items-center gap-2.5 py-2 font-semibold tracking-tight"
                >
                    <AppLogoIcon class="size-6 fill-current" />
                    {{ $page.props.name }}
                </Link>

                <div class="flex items-center gap-2">
                    <Button
                        v-if="isAuthenticated"
                        as-child
                        size="sm"
                        variant="outline"
                    >
                        <Link :href="dashboard()">Homepage</Link>
                    </Button>
                    <template v-else>
                        <Button as-child size="sm" variant="ghost">
                            <Link :href="login()">Log in</Link>
                        </Button>
                        <Button as-child size="sm">
                            <Link :href="register()">Get started</Link>
                        </Button>
                    </template>
                </div>
            </nav>
        </header>
        <main class="flex flex-1 flex-col justify-center">
            <!-- hero -->
            <section class="relative">
                <div
                    class="pointer-events-none absolute top-1/2 left-1/2 size-168 -translate-x-1/2 -translate-y-1/2 rounded-full opacity-20 blur-3xl"
                    style="background: var(--chart-1)"
                    aria-hidden="true"
                />

                <div
                    class="relative mx-auto max-w-3xl px-6 pt-24 pb-20 text-center"
                >
                    <h1
                        class="text-foreground mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-6xl"
                    >
                        Notes, boards and tables, together
                    </h1>

                    <p
                        class="text-muted-foreground mx-auto mt-6 max-w-xl text-lg text-pretty"
                    >
                        {{ $page.props.name }} keeps your writing, whiteboards,
                        tables and files in one place: on your own, or in a
                        project you share and edit live with everyone in it.
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
                </div>
            </section>

            <!-- what is here -->
            <section
                class="mx-auto w-full max-w-6xl px-6 pb-24"
                aria-labelledby="features-heading"
            >
                <h2 id="features-heading" class="sr-only">What you can do</h2>

                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <li
                        v-for="feature in features"
                        :key="feature.title"
                        class="bg-card border-border/70 rounded-xl border p-5"
                    >
                        <div
                            class="bg-primary/10 text-primary flex size-10 items-center justify-center rounded-lg"
                        >
                            <component
                                :is="feature.icon"
                                class="size-5"
                                aria-hidden="true"
                            />
                        </div>
                        <h3 class="mt-4 font-semibold">{{ feature.title }}</h3>
                        <p class="text-muted-foreground mt-1.5 text-sm">
                            {{ feature.text }}
                        </p>
                    </li>
                </ul>

                <p class="text-muted-foreground mt-8 text-center text-sm">
                    Connect an AI assistant over MCP, and it can read and write
                    your notes, boards, tables and Drive too.
                </p>
            </section>
        </main>
        <footer class="border-border/60 border-t">
            <div
                class="text-muted-foreground mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-6 py-8 text-xs"
            >
                <span
                    >&copy; {{ new Date().getFullYear() }}
                    {{ $page.props.name }}</span
                >
                <span>Built on Laravel, Inertia and Vue.</span>
            </div>
        </footer>
    </div>
</template>
