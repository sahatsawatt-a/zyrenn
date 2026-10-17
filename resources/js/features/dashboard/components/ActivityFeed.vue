<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { formatRelativeTime } from '@/lib/utils';
import type { Happened } from './glance';

// What happened in a project lately, by whom, the latest first.

defineProps<{ lines: Happened[] }>();

const initials = (name: string | null) =>
    (name ?? '·')
        .split(/\s+/)
        .map((word) => word.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase();
</script>

<template>
    <section data-test="activity">
        <h2 class="mb-2.5 text-[15px] font-semibold">What happened</h2>
        <div class="bg-card rounded-xl border px-3.5">
            <div
                v-for="(line, index) in lines"
                :key="index"
                class="flex min-w-0 items-start gap-2.5 border-t py-2.5 text-[13px] first:border-t-0"
            >
                <span
                    class="bg-primary/15 text-primary grid size-6 shrink-0 place-items-center rounded-full text-[10px] font-bold"
                    :title="line.who ?? undefined"
                    >{{ initials(line.who) }}</span
                >
                <span class="min-w-0 flex-1">
                    <b class="font-medium">{{ line.who ?? 'Someone' }}</b>
                    {{ ' ' }}
                    <Link
                        v-if="line.href"
                        :href="line.href"
                        class="hover:underline"
                        >{{ line.text }}</Link
                    >
                    <template v-else>{{ line.text }}</template>
                </span>
                <span class="text-muted-foreground shrink-0 text-xs">{{
                    formatRelativeTime(line.at)
                }}</span>
            </div>
            <p
                v-if="!lines.length"
                class="text-muted-foreground py-4 text-[13px]"
            >
                Nothing has happened here this month.
            </p>
        </div>
    </section>
</template>
