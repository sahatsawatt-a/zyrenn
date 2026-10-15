<script setup lang="ts">
import { Pause, Play, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { player, stop } from './tour';

// A tour as it plays: what is happening now, and a way to hold it or stop.
// Kept in the layout, so it goes on as the pages change under it. While it
// plays, a clear layer keeps the person's own clicks from landing on the page.

const step = computed(() => player.steps[player.at]);
</script>

<template>
    <div v-if="player.tour" data-tour-player>
        <div
            v-if="!player.done && !player.problem"
            class="fixed inset-0 z-[2147483000]"
            data-test="tour-shield"
        />
        <div
            class="bg-popover text-popover-foreground fixed inset-x-4 z-[2147483001] mx-auto max-w-md rounded-xl border p-4 shadow-lg"
            :class="player.high ? 'top-4' : 'bottom-4'"
            role="status"
            data-test="tour-card"
        >
            <div class="mb-1 flex items-center gap-2 text-xs">
                <span class="text-muted-foreground min-w-0 flex-1 truncate">
                    Show me · {{ player.tour.title }}
                </span>
                <span
                    v-if="!player.done"
                    class="text-muted-foreground tabular-nums"
                    data-test="tour-count"
                    >{{ player.at + 1 }} of {{ player.steps.length }}</span
                >
            </div>
            <p
                v-if="player.problem"
                class="text-destructive text-sm"
                data-test="tour-problem"
            >
                {{ player.problem }} The tour stopped here.
            </p>
            <p v-else-if="player.done" class="text-sm" data-test="tour-done">
                That's it.
                <template v-if="player.tour.makes"
                    >It made {{ player.tour.makes }}: it's yours to keep or
                    delete.</template
                >
            </p>
            <p v-else class="text-sm" data-test="tour-say">{{ step?.say }}</p>
            <div class="mt-3 flex justify-end gap-2">
                <Button
                    v-if="!player.done && !player.problem"
                    variant="outline"
                    size="sm"
                    data-test="tour-pause"
                    @click="player.paused = !player.paused"
                >
                    <component :is="player.paused ? Play : Pause" />
                    {{ player.paused ? 'Go on' : 'Hold' }}
                </Button>
                <Button size="sm" data-test="tour-stop" @click="stop()">
                    <X />
                    {{ player.done || player.problem ? 'Close' : 'Stop' }}
                </Button>
            </div>
        </div>
    </div>
</template>
