<script setup lang="ts">
import NumberField from '@/components/common/NumberField.vue';
import { Play } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { SPEEDS } from './steps';
import type { Check, Pace, RunChoice, Speed } from './steps';
import Tip from '@/components/common/Tip.vue';

// A run set up by hand, for what the two usual ones (a quick test, a demo
// video) do not do: a speed or pace of one's own, captions, a film, pictures
// kept in the Drive, only some of the checks.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    checks: Check[];
    speeds: Record<Exclude<Speed, 'custom'>, Pace>;
    folder: string;
}>();

const emit = defineEmits<{ start: [choice: RunChoice] }>();

const speed = ref<Speed>('watch');
const pace = ref<Pace>({ glide: 350, typing: 45, pause: 800 });
const captions = ref(true);
const record = ref(false);
const keep = ref(false);
const oneFilm = ref(false);
const cutWaits = ref(true);
const only = ref<Set<string>>(new Set());

watch(open, (now) => {
    if (now) {
        only.value = new Set(props.checks.map((check) => check.id));
    }
});

// Slowed is for watching: said on the page as it goes
watch(speed, (now) => {
    captions.value = now !== 'quick';
});
// A film is only ever kept in the Drive
watch(record, (now) => {
    if (now) {
        keep.value = true;
    }
});

const paceShown = computed(() =>
    speed.value === 'custom' ? pace.value : props.speeds[speed.value],
);
const paceSaid = computed(() => {
    const { glide, typing, pause } = paceShown.value;

    return glide === 0 && typing === 0 && pause === 0
        ? 'No pointer, no pauses.'
        : `The pointer glides ${(glide / 1000).toFixed(2)}s to each thing, each key takes ${typing}ms, and it waits ${(pause / 1000).toFixed(1)}s after each step.`;
});

function toggle(id: string, on: boolean) {
    const next = new Set(only.value);

    if (on) {
        next.add(id);
    } else {
        next.delete(id);
    }

    only.value = next;
}

function start() {
    emit('start', {
        speed: speed.value,
        pace: pace.value,
        captions: captions.value,
        record: record.value,
        keep: keep.value,
        checks: only.value.size === props.checks.length ? [] : [...only.value],
        one_film: record.value && oneFilm.value,
        cut_waits: cutWaits.value,
    });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-test="run-options">
            <DialogHeader>
                <DialogTitle>Run with options</DialogTitle>
                <DialogDescription
                    >How fast it goes, what is shown and kept, and which
                    checks.</DialogDescription
                >
            </DialogHeader>

            <div class="space-y-4">
                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium">Speed</span>
                        <Select v-model="speed">
                            <SelectTrigger
                                class="w-40"
                                size="sm"
                                aria-label="Speed"
                                data-test="run-speed"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="(about, value) in SPEEDS"
                                    :key="value"
                                    :value="value"
                                    >{{ about.label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <p
                        class="text-muted-foreground text-xs"
                        data-test="run-pace"
                    >
                        {{ SPEEDS[speed].about }} {{ paceSaid }}
                    </p>
                    <div
                        v-if="speed === 'custom'"
                        class="grid grid-cols-1 gap-2 sm:grid-cols-3"
                    >
                        <label class="space-y-1 text-xs">
                            <span class="text-muted-foreground">Glide</span>
                            <NumberField
                                v-model="pace.glide"
                                label="Glide"
                                :min="0"
                                :max="2000"
                                :step="50"
                                unit="ms"
                                compact
                                class="w-full"
                            />
                        </label>
                        <label class="space-y-1 text-xs">
                            <span class="text-muted-foreground">Each key</span>
                            <NumberField
                                v-model="pace.typing"
                                label="Each key"
                                :min="0"
                                :max="400"
                                :step="10"
                                unit="ms"
                                compact
                                class="w-full"
                            />
                        </label>
                        <label class="space-y-1 text-xs">
                            <span class="text-muted-foreground">Pause</span>
                            <NumberField
                                v-model="pace.pause"
                                label="Pause"
                                :min="0"
                                :max="5000"
                                :step="100"
                                unit="ms"
                                compact
                                class="w-full"
                            />
                        </label>
                    </div>
                </div>

                <div class="divide-y rounded-lg border text-sm">
                    <label
                        class="flex items-center justify-between gap-3 px-3 py-2"
                    >
                        Show the steps' notes
                        <Switch v-model="captions" data-test="run-captions" />
                    </label>
                    <label
                        class="flex items-center justify-between gap-3 px-3 py-2"
                    >
                        Film it
                        <Switch v-model="record" data-test="run-record" />
                    </label>
                    <Tip
                        v-if="record && checks.length > 1"
                        text="Every check one after another in one browser -- what one leaves, the next finds -- as one film"
                    >
                        <label
                            class="flex items-center justify-between gap-3 px-3 py-2"
                        >
                            All checks in one film
                            <Switch
                                v-model="oneFilm"
                                data-test="run-one-film"
                            />
                        </label>
                    </Tip>
                    <Tip
                        v-if="record"
                        text="Waits of 2.5 s or more for the site -- a page loading, words coming -- left out of the film, with a note saying how long was skipped. Off: filmed as it happened."
                    >
                        <label
                            class="flex items-center justify-between gap-3 px-3 py-2"
                        >
                            Cut out waiting for the page
                            <Switch
                                v-model="cutWaits"
                                data-test="run-cut-waits"
                            />
                        </label>
                    </Tip>
                    <label
                        class="flex items-center justify-between gap-3 px-3 py-2"
                    >
                        Keep pictures in the Drive
                        <Switch
                            v-model="keep"
                            :disabled="record"
                            data-test="run-keep"
                        />
                    </label>
                </div>
                <p v-if="keep" class="text-muted-foreground -mt-2 text-xs">
                    In the Drive, in Browser checks / {{ folder }}.
                </p>

                <div v-if="checks.length > 1" class="space-y-1.5">
                    <span class="text-sm font-medium">Checks</span>
                    <label
                        v-for="check in checks"
                        :key="check.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="only.has(check.id)"
                            @update:model-value="toggle(check.id, !!$event)"
                        />
                        <span class="truncate">{{ check.title }}</span>
                    </label>
                </div>
            </div>

            <DialogFooter>
                <Button variant="ghost" @click="open = false">Cancel</Button>
                <Button
                    :disabled="!only.size"
                    data-test="run-all"
                    @click="start"
                >
                    <Play />
                    {{
                        only.size === 1
                            ? 'Run the check'
                            : `Run ${only.size} checks`
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
