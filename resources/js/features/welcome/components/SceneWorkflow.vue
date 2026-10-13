<script setup lang="ts">
// A workflow running: a message comes in, each step lights up in turn,
// and the answer goes back.
import { Check, MessageCircle, Send, Sparkles, Table2 } from '@lucide/vue';
import type { Component } from 'vue';

const STEPS: {
    icon: Component;
    label: string;
    detail: string;
    tone: string;
    strip: string;
}[] = [
    {
        icon: MessageCircle,
        label: 'Instagram message',
        detail: '“Are you open on Sunday?”',
        tone: 'bg-chart-5/12 text-chart-5',
        strip: 'bg-chart-4',
    },
    {
        icon: Sparkles,
        label: 'Ask AI',
        detail: 'Answers from your opening hours',
        tone: 'bg-chart-1/12 text-chart-1',
        strip: 'bg-chart-1',
    },
    {
        icon: Send,
        label: 'Send on Instagram',
        detail: '“Yes, 10 to 6 on Sundays.”',
        tone: 'bg-chart-5/12 text-chart-5',
        strip: 'bg-chart-5',
    },
    {
        icon: Table2,
        label: 'Add a row',
        detail: 'Questions asked · 1 new',
        tone: 'bg-chart-2/12 text-chart-2',
        strip: 'bg-chart-2',
    },
];
</script>

<template>
    <div class="welcome-dots flex h-full flex-col px-4 py-5 sm:px-7 sm:py-6">
        <div class="flex items-center justify-between gap-3">
            <h3
                class="min-w-0 truncate text-base font-semibold tracking-tight sm:text-lg"
            >
                Answer Instagram DMs
            </h3>
            <span
                class="welcome-ran bg-chart-4/15 text-chart-4 flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
            >
                <Check class="size-3" stroke-width="3" /> Ran in 1.2s
            </span>
        </div>

        <ol
            class="mt-3 grid flex-1 content-center gap-1.5 sm:mt-4 sm:grid-cols-4 sm:gap-0"
        >
            <li
                v-for="(step, at) in STEPS"
                :key="step.label"
                class="relative flex items-center sm:block"
            >
                <!-- the join to the step before, with the run travelling along it -->
                <span
                    v-if="at > 0"
                    class="bg-muted-foreground/50 absolute top-1/2 left-3 hidden h-px w-6 -translate-x-full sm:block"
                    aria-hidden="true"
                >
                    <span
                        class="welcome-pulse bg-primary absolute -top-[2.5px] size-1.5 rounded-full"
                        :style="{ animationDelay: `${at * 0.9 - 0.5}s` }"
                    />
                </span>
                <div
                    class="welcome-step bg-card border-border relative w-full overflow-hidden rounded-lg border py-2 pr-3 pl-4 shadow-xs sm:mx-3 sm:w-auto sm:py-2.5"
                    :style="{ animationDelay: `${at * 0.9}s` }"
                >
                    <span
                        class="absolute inset-y-0 left-0 w-1"
                        :class="step.strip"
                        aria-hidden="true"
                    />
                    <div class="flex items-center gap-2">
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-md"
                            :class="step.tone"
                        >
                            <component :is="step.icon" class="size-4" />
                        </span>
                        <span
                            class="min-w-0 text-sm leading-tight font-medium"
                            >{{ step.label }}</span
                        >
                        <Check
                            class="welcome-ok text-chart-4 absolute top-2 right-2 size-3.5"
                            stroke-width="3"
                            :style="{ animationDelay: `${at * 0.9 + 0.5}s` }"
                        />
                    </div>
                    <div
                        class="text-muted-foreground mt-1 truncate text-xs sm:mt-1.5 sm:whitespace-normal"
                    >
                        {{ step.detail }}
                    </div>
                </div>
            </li>
        </ol>
    </div>
</template>

<style scoped>
.welcome-dots {
    background-image: radial-gradient(
        color-mix(in oklab, var(--muted-foreground) 22%, transparent) 1px,
        transparent 1px
    );
    background-size: 18px 18px;
}

.welcome-step {
    animation: flow-step 0.5s ease-out both;
}

.welcome-ok {
    animation: flow-ok 0.3s ease-out both;
}

.welcome-ran {
    animation: flow-ok 0.4s ease-out 3.6s both;
}

.welcome-pulse {
    left: 0;
    opacity: 0;
    animation: flow-pulse 0.5s ease-in both;
}

@keyframes flow-step {
    from {
        opacity: 0.35;
        transform: translateY(6px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes flow-ok {
    from {
        opacity: 0;
        transform: scale(0.5);
    }

    to {
        opacity: 1;
        transform: scale(1);
    }
}

@keyframes flow-pulse {
    0% {
        left: 0;
        opacity: 1;
    }

    100% {
        left: 100%;
        opacity: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-step,
    .welcome-ok,
    .welcome-ran,
    .welcome-pulse {
        animation-duration: 1ms;
        animation-delay: 0s !important;
    }
}
</style>
