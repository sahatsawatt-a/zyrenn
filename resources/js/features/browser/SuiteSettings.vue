<script setup lang="ts">
import {
    CalendarClock,
    KeyRound,
    LogIn,
    Plus,
    Ticket,
    Trash2,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { ask } from './http';
import type { Every, SignIn, Suite } from './steps';

// How a suite goes on by itself: when it runs without being asked, whether a
// failure files a ticket in its project, and the hidden values its steps
// type (a password, say) -- each kept as soon as it is changed.

const props = defineProps<{ suite: Suite; canChange: boolean }>();

const EVERY: Record<Every, string> = {
    off: 'Only when asked',
    hour: 'Every hour',
    day: 'Every day',
    weekdays: 'On weekdays',
};
// Every half hour of the day, to choose from
const TIMES = Array.from(
    { length: 48 },
    (_, at) =>
        `${String(Math.floor(at / 2)).padStart(2, '0')}:${at % 2 ? '30' : '00'}`,
);
const MINUTES = ['00', '15', '30', '45'];

async function saveSettings(change: Partial<Suite['settings']>) {
    const now = props.suite;
    Object.assign(now.settings, change);

    try {
        const answer = await ask<{ next_run_at: string | null }>(
            { url: now.urls.update, method: 'patch' },
            {
                settings: {
                    ...now.settings,
                    zone:
                        now.settings.zone ??
                        Intl.DateTimeFormat().resolvedOptions().timeZone,
                },
            },
        );
        now.next_run_at = answer.next_run_at;
    } catch (problem) {
        toast.error((problem as Error).message);
    }
}

const nextRun = computed(() =>
    props.suite.next_run_at
        ? new Intl.DateTimeFormat(undefined, {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              hour: 'numeric',
              minute: '2-digit',
          }).format(new Date(props.suite.next_run_at))
        : null,
);

// Hidden values, as written here: a value typed is sent once, then kept
// on the server and never shown again
const values = ref<{ name: string; value: string; set: boolean }[]>([]);
watch(
    () => props.suite,
    (now) => {
        values.value = (now?.values ?? []).map((one) => ({
            ...one,
            value: '',
        }));
    },
    { immediate: true },
);

const saveValues = useDebounceFn(async () => {
    const now = props.suite;
    const named = values.value.filter((one) => one.name.trim());

    try {
        await ask(
            { url: now.urls.update, method: 'patch' },
            {
                values: named.map((one) => ({
                    name: one.name.trim(),
                    ...(one.value !== '' ? { value: one.value } : {}),
                })),
            },
        );

        for (const one of named) {
            one.set ||= one.value !== '';
            one.value = '';
        }
    } catch (problem) {
        toast.error((problem as Error).message);
    }
}, 700);

// Signing in once a run: each a name and the check that signs in. Sent
// whole as it changes; one without a check yet is kept here until it has one
const signIns = ref<SignIn[]>([]);
watch(
    () => props.suite,
    (now) => {
        signIns.value = (now?.sign_ins ?? []).map((one) => ({ ...one }));
    },
    { immediate: true },
);

const saveSignIns = useDebounceFn(async () => {
    const whole = signIns.value
        .map((one) => ({ name: one.name.trim(), check: one.check }))
        .filter((one) => /^[A-Za-z][\w-]{0,39}$/.test(one.name) && one.check);

    try {
        await ask(
            { url: props.suite.urls.update, method: 'patch' },
            { sign_ins: whole },
        );
        props.suite.sign_ins = whole;
    } catch (problem) {
        toast.error((problem as Error).message);
    }
}, 700);

function addSignIn() {
    const taken = new Set(signIns.value.map((one) => one.name));

    signIns.value.push({
        name: taken.has('member')
            ? `person${signIns.value.length + 1}`
            : 'member',
        check: '',
    });
}

// How a step names a hidden value, shown as it is written
const PASSWORD = '{{password}}';

function addValue() {
    values.value.push({ name: 'password', value: '', set: false });
}
</script>

<template>
    <div class="space-y-4" data-test="suite-auto">
        <!-- When it runs -->
        <section class="rounded-xl border">
            <header class="flex items-start gap-3 border-b px-4 py-3">
                <CalendarClock
                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                />
                <div>
                    <h3 class="text-sm font-medium">Runs by itself</h3>
                    <p class="text-muted-foreground text-xs">
                        A quick test on a timetable, so a broken page is found
                        before a customer finds it.
                    </p>
                </div>
            </header>
            <div class="flex flex-wrap items-center gap-2 px-4 py-3 text-sm">
                <Select
                    :model-value="suite.settings.every"
                    :disabled="!canChange"
                    @update:model-value="
                        saveSettings({ every: $event as Every })
                    "
                >
                    <SelectTrigger
                        class="w-44"
                        size="sm"
                        aria-label="Run it by itself"
                        data-test="auto-every"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="(label, value) in EVERY"
                            :key="value"
                            :value="value"
                            >{{ label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <template
                    v-if="['day', 'weekdays'].includes(suite.settings.every)"
                >
                    <span class="text-muted-foreground">at</span>
                    <Select
                        :model-value="suite.settings.at"
                        :disabled="!canChange"
                        @update:model-value="
                            saveSettings({ at: $event as string })
                        "
                    >
                        <SelectTrigger
                            class="w-28"
                            size="sm"
                            aria-label="At"
                            data-test="auto-at"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="time in TIMES"
                                :key="time"
                                :value="time"
                                >{{ time }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </template>
                <template v-else-if="suite.settings.every === 'hour'">
                    <span class="text-muted-foreground">at minute</span>
                    <Select
                        :model-value="suite.settings.at.slice(3)"
                        :disabled="!canChange"
                        @update:model-value="
                            saveSettings({ at: `00:${$event}` })
                        "
                    >
                        <SelectTrigger
                            class="w-20"
                            size="sm"
                            aria-label="At minute"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="minute in MINUTES"
                                :key="minute"
                                :value="minute"
                                >:{{ minute }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </template>
                <p
                    v-if="suite.settings.every !== 'off'"
                    class="text-muted-foreground basis-full text-xs"
                    data-test="auto-next"
                >
                    A quick test each time.
                    <template v-if="nextRun">Next: {{ nextRun }}.</template>
                </p>
            </div>
        </section>

        <!-- A ticket when one fails -->
        <section v-if="suite.project" class="rounded-xl border">
            <label class="flex items-start gap-3 px-4 py-3">
                <Ticket class="text-muted-foreground mt-0.5 size-4 shrink-0" />
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium"
                        >File a bug ticket when a check fails</span
                    >
                    <span class="text-muted-foreground block text-xs"
                        >In this project, with its pictures -- once, not again
                        while that ticket is open.</span
                    >
                </span>
                <Switch
                    :model-value="suite.settings.tickets"
                    :disabled="!canChange"
                    data-test="auto-tickets"
                    @update:model-value="
                        saveSettings({ tickets: Boolean($event) })
                    "
                />
            </label>
        </section>

        <!-- Signing in once a run, so the checks start signed in -->
        <section class="rounded-xl border" data-test="sign-ins">
            <header class="flex items-start gap-3 border-b px-4 py-3">
                <LogIn class="text-muted-foreground mt-0.5 size-4 shrink-0" />
                <div>
                    <h3 class="text-sm font-medium">Sign in once</h3>
                    <p class="text-muted-foreground text-xs">
                        A run signs in with this check first, once, and every
                        other check starts already signed in -- so they need no
                        sign-in steps. A check can start as someone else, or
                        signed out, under its own settings.
                    </p>
                </div>
            </header>
            <div class="space-y-2 px-4 py-3">
                <div
                    v-for="(one, at) in signIns"
                    :key="at"
                    class="flex items-center gap-2"
                    data-test="sign-in-row"
                >
                    <Input
                        v-model="one.name"
                        :readonly="!canChange"
                        class="h-8 w-28 text-xs sm:w-36"
                        aria-label="Who it signs in as"
                        data-test="sign-in-name"
                        @update:model-value="saveSignIns"
                    />
                    <Select
                        :model-value="one.check"
                        :disabled="!canChange"
                        @update:model-value="
                            one.check = String($event);
                            saveSignIns();
                        "
                    >
                        <SelectTrigger
                            class="h-8 min-w-0 flex-1"
                            size="sm"
                            aria-label="The check that signs in"
                            data-test="sign-in-check"
                        >
                            <SelectValue
                                placeholder="The check that signs in"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="check in suite.checks"
                                :key="check.id"
                                :value="check.id"
                                >{{ check.title || 'Untitled' }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <Button
                        v-if="canChange"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Stop signing in as this"
                        @click="
                            signIns.splice(at, 1);
                            saveSignIns();
                        "
                    >
                        <Trash2 />
                    </Button>
                </div>
                <Button
                    v-if="canChange && signIns.length < 5"
                    variant="outline"
                    size="sm"
                    data-test="sign-in-add"
                    @click="addSignIn"
                >
                    <Plus />
                    {{
                        signIns.length
                            ? 'Sign in as someone else too'
                            : 'Sign in once'
                    }}
                </Button>
            </div>
        </section>

        <!-- Hidden values -->
        <section class="rounded-xl border">
            <header class="flex items-start gap-3 border-b px-4 py-3">
                <KeyRound
                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                />
                <div>
                    <h3 class="text-sm font-medium">Hidden values</h3>
                    <p class="text-muted-foreground text-xs">
                        For a password, say. Write
                        <code class="bg-muted rounded px-1">{{
                            PASSWORD
                        }}</code>
                        where a step types it: the value is kept apart, never
                        shown again here, in a run or in a caption.
                    </p>
                </div>
            </header>
            <div class="space-y-2 px-4 py-3">
                <div
                    v-for="(one, at) in values"
                    :key="at"
                    class="flex items-center gap-2"
                    data-test="value-row"
                >
                    <Input
                        v-model="one.name"
                        :readonly="!canChange"
                        class="h-8 w-32 font-mono text-xs sm:w-40"
                        aria-label="Its name"
                        data-test="value-name"
                        @update:model-value="saveValues"
                    />
                    <Input
                        v-model="one.value"
                        :readonly="!canChange"
                        type="password"
                        class="h-8 min-w-0 flex-1"
                        :placeholder="
                            one.set ? 'Kept. Type to change it' : 'Its value'
                        "
                        aria-label="Its value"
                        autocomplete="off"
                        data-test="value-value"
                        @update:model-value="saveValues"
                    />
                    <Button
                        v-if="canChange"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Remove the value"
                        @click="
                            values.splice(at, 1);
                            saveValues();
                        "
                    >
                        <Trash2 />
                    </Button>
                </div>
                <Button
                    v-if="canChange"
                    variant="outline"
                    size="sm"
                    data-test="value-add"
                    @click="addValue"
                >
                    <Plus /> Add a hidden value
                </Button>
            </div>
        </section>
    </div>
</template>
