<script setup lang="ts">
import { History, Loader2, RotateCcw } from '@lucide/vue';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ask } from './http';
import type { Suite } from './steps';

// A suite's checks as each version of them was -- what each run tested --
// and putting an earlier one back, kept as a new version so nothing is lost.

type Version = {
    version: number;
    at: string;
    by: string | null;
    checks: number;
    steps: number;
    titles: string[];
    runs: number;
    restore: string;
};

const props = defineProps<{ suite: Suite; canChange: boolean }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ restored: [suite: Suite] }>();

const versions = ref<Version[]>([]);
const current = ref(0);
const loading = ref(false);
const restoring = ref<number | null>(null);

const time = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

watch(open, async (now) => {
    if (!now) return;

    loading.value = true;

    try {
        const answer = await ask<{ current: number; versions: Version[] }>({
            url: props.suite.urls.versions,
            method: 'get',
        });
        versions.value = answer.versions;
        current.value = answer.current;
    } catch (problem) {
        toast.error((problem as Error).message);
    } finally {
        loading.value = false;
    }
});

async function restore(version: Version) {
    restoring.value = version.version;

    try {
        const suite = await ask<Suite>({
            url: version.restore,
            method: 'post',
        });
        emit('restored', suite);
        toast.success(
            `Version ${version.version} is back, as version ${suite.version}.`,
        );
        open.value = false;
    } catch (problem) {
        toast.error((problem as Error).message);
    } finally {
        restoring.value = null;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Versions of “{{ suite.title }}”</DialogTitle>
                <DialogDescription>
                    Each change to its checks is kept. Putting one back keeps it
                    as a new version, so nothing is lost.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="loading"
                class="text-muted-foreground flex items-center gap-2 py-6 text-sm"
            >
                <Loader2 class="size-4 animate-spin" /> Finding its versions…
            </div>
            <p
                v-else-if="!versions.length"
                class="text-muted-foreground py-6 text-sm"
            >
                No versions kept yet: one is kept the next time its checks
                change.
            </p>
            <ol v-else class="divide-y rounded-lg border" data-test="versions">
                <li
                    v-for="one in versions"
                    :key="one.version"
                    class="flex items-start gap-3 px-3 py-2.5"
                    data-test="version"
                    :data-version="one.version"
                >
                    <History
                        class="text-muted-foreground mt-0.5 size-4 shrink-0"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">
                            Version {{ one.version }}
                            <span
                                v-if="one.version === current"
                                class="text-muted-foreground font-normal"
                                >· now</span
                            >
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ time.format(new Date(one.at)) }}
                            <template v-if="one.by">· {{ one.by }}</template> ·
                            {{ one.checks }}
                            {{ one.checks === 1 ? 'check' : 'checks' }},
                            {{ one.steps }}
                            {{ one.steps === 1 ? 'step' : 'steps' }} ·
                            {{
                                one.runs
                                    ? `${one.runs} ${one.runs === 1 ? 'run' : 'runs'}`
                                    : 'not run'
                            }}
                        </p>
                        <p
                            v-if="one.titles.length"
                            class="text-muted-foreground truncate text-xs"
                        >
                            {{ one.titles.join(' · ') }}
                        </p>
                    </div>
                    <Button
                        v-if="canChange && one.version !== current"
                        variant="outline"
                        size="sm"
                        :disabled="restoring !== null"
                        data-test="version-restore"
                        @click="restore(one)"
                    >
                        <Loader2
                            v-if="restoring === one.version"
                            class="animate-spin"
                        />
                        <RotateCcw v-else />
                        Put back
                    </Button>
                </li>
            </ol>
        </DialogContent>
    </Dialog>
</template>
