<script setup lang="ts">
import { Timer } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ask } from './http';
import type { Limits, Suite, Waits } from './steps';

// How long a suite's checks wait for a slow site: each step, and each check
// as a whole -- chosen up to the most anyone may choose, which an admin sets
// here too, for everyone.

const props = defineProps<{
    suite: Suite;
    canChange: boolean;
    limits: Limits;
}>();

const STEPS = [5, 10, 15, 20, 30, 45, 60, 90, 120, 180, 240, 300];
const CHECKS = [30, 60, 90, 120, 180, 300, 600, 900, 1200, 1800];
const DEFAULT = 'default';

const said = (seconds: number) =>
    seconds < 120 || seconds % 60 ? `${seconds} s` : `${seconds / 60} min`;

/**
 * What may be chosen, up to the most -- the default offered once, as itself
 * -- with what is chosen now kept in.
 */
const upTo = (kind: keyof Waits, all: number[]) => {
    const now = props.suite.limits[kind];
    const most = props.limits.most[kind];
    const plain = props.limits.default[kind];

    return [
        ...new Set([
            ...all.filter((one) => one <= most && one !== plain),
            ...(now ? [now] : []),
        ]),
    ].sort((a, b) => a - b);
};

const stepChoices = computed(() => upTo('step', STEPS));
const checkChoices = computed(() => upTo('check', CHECKS));

// Held to the most allowed now: what it waits, said as it is
const waits = computed<Waits>(() => ({
    step: Math.min(
        props.suite.limits.step ?? props.limits.default.step,
        props.limits.most.step,
    ),
    check: Math.min(
        props.suite.limits.check ?? props.limits.default.check,
        props.limits.most.check,
    ),
}));

async function choose(kind: keyof Waits, value: string) {
    const now = props.suite;
    now.limits[kind] = value === DEFAULT ? null : Number(value);

    try {
        await ask(
            { url: now.urls.update, method: 'patch' },
            { limits: now.limits },
        );
    } catch (problem) {
        toast.error((problem as Error).message);
    }
}

// ------------------------------------------------------------ For everyone
const MOST_STEPS = computed(() =>
    STEPS.filter(
        (one) =>
            one >= (props.limits.floor?.step ?? 0) &&
            one <= (props.limits.ceiling?.step ?? 0),
    ),
);
const MOST_CHECKS = computed(() =>
    CHECKS.filter(
        (one) =>
            one >= (props.limits.floor?.check ?? 0) &&
            one <= (props.limits.ceiling?.check ?? 0),
    ),
);

async function setMost(kind: keyof Waits, value: string) {
    const most = { ...props.limits.most, [kind]: Number(value) };

    try {
        const answer = await ask<Limits>(
            { url: props.limits.set!, method: 'patch' },
            most,
        );
        Object.assign(props.limits.most, answer.most);
        toast.success('The most for everyone is set.');
    } catch (problem) {
        toast.error((problem as Error).message);
    }
}
</script>

<template>
    <section class="rounded-xl border" data-test="time-limits">
        <header class="flex items-start gap-3 border-b px-4 py-3">
            <Timer class="text-muted-foreground mt-0.5 size-4 shrink-0" />
            <div>
                <h3 class="text-sm font-medium">Time limits</h3>
                <p class="text-muted-foreground text-xs">
                    For a slow site: how long to wait before a step or a check
                    fails.
                </p>
            </div>
        </header>
        <div class="grid gap-2 px-4 py-3 text-sm sm:grid-cols-2">
            <label class="flex items-center justify-between gap-2">
                <span class="text-muted-foreground">Each step waits up to</span>
                <Select
                    :model-value="
                        suite.limits.step === null
                            ? DEFAULT
                            : String(suite.limits.step)
                    "
                    :disabled="!canChange"
                    @update:model-value="choose('step', $event as string)"
                >
                    <SelectTrigger
                        class="w-32"
                        size="sm"
                        aria-label="Each step waits up to"
                        data-test="limit-step"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="DEFAULT"
                            >{{
                                said(
                                    Math.min(
                                        limits.default.step,
                                        limits.most.step,
                                    ),
                                )
                            }}
                            (default)</SelectItem
                        >
                        <SelectItem
                            v-for="one in stepChoices"
                            :key="one"
                            :value="String(one)"
                            >{{ said(one) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </label>
            <label class="flex items-center justify-between gap-2">
                <span class="text-muted-foreground"
                    >Each check takes up to</span
                >
                <Select
                    :model-value="
                        suite.limits.check === null
                            ? DEFAULT
                            : String(suite.limits.check)
                    "
                    :disabled="!canChange"
                    @update:model-value="choose('check', $event as string)"
                >
                    <SelectTrigger
                        class="w-32"
                        size="sm"
                        aria-label="Each check takes up to"
                        data-test="limit-check"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="DEFAULT"
                            >{{
                                said(
                                    Math.min(
                                        limits.default.check,
                                        limits.most.check,
                                    ),
                                )
                            }}
                            (default)</SelectItem
                        >
                        <SelectItem
                            v-for="one in checkChoices"
                            :key="one"
                            :value="String(one)"
                            >{{ said(one) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </label>
            <p
                class="text-muted-foreground text-xs sm:col-span-2"
                data-test="limit-note"
            >
                A slowed or filmed run is given its slowing on top. The most
                anyone can choose: {{ said(limits.most.step) }} a step,
                {{ said(limits.most.check) }} a check<template
                    v-if="
                        (suite.limits.step ?? 0) > limits.most.step ||
                        (suite.limits.check ?? 0) > limits.most.check
                    "
                    >, so this suite waits {{ said(waits.step) }} and
                    {{ said(waits.check) }}</template
                >.
            </p>
        </div>

        <!-- The most anyone may choose: an admin's to set -->
        <div
            v-if="limits.set"
            class="bg-muted/40 grid gap-2 border-t px-4 py-3 text-sm sm:grid-cols-2"
            data-test="limits-most"
        >
            <p class="text-xs font-medium sm:col-span-2">
                The most for everyone
                <span class="text-muted-foreground font-normal"
                    >-- as an admin</span
                >
            </p>
            <label class="flex items-center justify-between gap-2">
                <span class="text-muted-foreground">A step</span>
                <Select
                    :model-value="String(limits.most.step)"
                    @update:model-value="setMost('step', $event as string)"
                >
                    <SelectTrigger
                        class="w-32"
                        size="sm"
                        aria-label="The most a step may wait, for everyone"
                        data-test="most-step"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="one in MOST_STEPS"
                            :key="one"
                            :value="String(one)"
                            >{{ said(one) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </label>
            <label class="flex items-center justify-between gap-2">
                <span class="text-muted-foreground">A check</span>
                <Select
                    :model-value="String(limits.most.check)"
                    @update:model-value="setMost('check', $event as string)"
                >
                    <SelectTrigger
                        class="w-32"
                        size="sm"
                        aria-label="The most a check may take, for everyone"
                        data-test="most-check"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="one in MOST_CHECKS"
                            :key="one"
                            :value="String(one)"
                            >{{ said(one) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </label>
        </div>
    </section>
</template>
