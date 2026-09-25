<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Activity, ArrowRight, GitBranch, ShieldCheck, Zap } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const page = usePage();

const isAuthenticated = computed(() => Boolean(page.props.auth.user));

const primaryCta = computed(() =>
    isAuthenticated.value
        ? { href: dashboard(), label: 'Open Homepage' }
        : { href: register(), label: 'Create an account' },
);
</script>

<template>
    <Head title="Welcome" />

    <div class="bg-background text-foreground flex min-h-svh flex-col">
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
                        The all-in-one productivity hub
                    </h1>

                    <p
                        class="text-muted-foreground mx-auto mt-6 max-w-xl text-lg text-pretty"
                    >
                        <!-- {{ $page.props.name }} keeps every project, deploy and -->
                        <!-- incident on one timeline your whole team can read. -->
                        Where all your essential functions fuse into one
                        powerful hub.
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
