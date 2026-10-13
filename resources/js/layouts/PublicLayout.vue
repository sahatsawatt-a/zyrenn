<script setup lang="ts">
// What anyone sees without an account -- the welcome page, and the pages
// on privacy, terms and deleting data: the same bar above and foot below.
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/app/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const page = usePage();

const isAuthenticated = computed(() => Boolean(page.props.auth.user));

const LEGAL = [
    { href: '/privacy', label: 'Privacy' },
    { href: '/terms', label: 'Terms' },
    { href: '/data-deletion', label: 'Delete your data' },
];
</script>

<template>
    <!-- clip, not hidden: the welcome page's glow is wider than a phone, and
         a clip keeps the sticky header working -->
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

        <slot />

        <footer class="border-border/60 border-t">
            <div
                class="text-muted-foreground mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-6 py-8 text-xs"
            >
                <span>{{ $page.props.copyright }}</span>
                <nav class="flex flex-wrap gap-x-4 gap-y-1" aria-label="Legal">
                    <Link
                        v-for="link in LEGAL"
                        :key="link.href"
                        :href="link.href"
                        class="hover:text-foreground -my-3 py-3 underline-offset-4 hover:underline"
                        >{{ link.label }}</Link
                    >
                </nav>
            </div>
        </footer>
    </div>
</template>
