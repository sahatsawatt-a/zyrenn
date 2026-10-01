<script setup lang="ts">
import { Maximize2, Pause, Play, Volume2, VolumeX } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { ref, watch } from 'vue';

// The controls under a selected video on a board. The video itself is drawn
// on the canvas, which has no controls of its own to offer.
const props = defineProps<{
    video: HTMLVideoElement;
    playing: boolean;
}>();

defineEmits<{ toggle: []; expand: [] }>();

const time = ref(0);
const duration = ref(0);
const muted = ref(false);

const read = () => {
    time.value = props.video.currentTime;
    duration.value = Number.isFinite(props.video.duration)
        ? props.video.duration
        : 0;
    muted.value = props.video.muted;
};

watch(() => props.video, read, { immediate: true });

for (const event of [
    'timeupdate',
    'durationchange',
    'volumechange',
    'seeked',
]) {
    useEventListener(() => props.video, event, read);
}

const seek = (event: Event) => {
    props.video.currentTime = Number((event.target as HTMLInputElement).value);
};

const toggleMute = () => {
    props.video.muted = !props.video.muted;
};

/** 75 → "1:15", and 3700 → "1:01:40". */
const clock = (seconds: number) => {
    const whole = Math.floor(seconds);
    const hours = Math.floor(whole / 3600);
    const minutes = Math.floor((whole % 3600) / 60);
    const rest = String(whole % 60).padStart(2, '0');

    return hours
        ? `${hours}:${String(minutes).padStart(2, '0')}:${rest}`
        : `${minutes}:${rest}`;
};
</script>

<template>
    <div class="video-bar" data-test="video-controls" @pointerdown.stop>
        <button
            type="button"
            class="video-btn"
            :title="playing ? 'Pause' : 'Play'"
            data-test="video-toggle"
            @click="$emit('toggle')"
        >
            <Pause v-if="playing" class="size-4" />
            <Play v-else class="size-4" />
        </button>

        <span class="text-muted-foreground shrink-0 text-xs tabular-nums">
            {{ clock(time) }} / {{ clock(duration) }}
        </span>

        <input
            type="range"
            class="video-seek"
            min="0"
            :max="duration || 0"
            step="0.1"
            :value="time"
            aria-label="Seek"
            data-test="video-seek"
            @input="seek"
        />

        <button
            type="button"
            class="video-btn"
            :title="muted ? 'Unmute' : 'Mute'"
            @click="toggleMute"
        >
            <VolumeX v-if="muted" class="size-4" />
            <Volume2 v-else class="size-4" />
        </button>
        <button
            type="button"
            class="video-btn"
            title="Watch full size"
            data-test="video-expand"
            @click="$emit('expand')"
        >
            <Maximize2 class="size-4" />
        </button>
    </div>
</template>

<style scoped>
.video-bar {
    position: absolute;
    z-index: 15;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.25rem 0.5rem;
    border: 1px solid var(--border);
    border-radius: 9999px;
    background: color-mix(in oklab, var(--background) 95%, transparent);
    box-shadow: 0 4px 12px rgb(0 0 0 / 0.12);
}

.video-btn {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 9999px;
    cursor: pointer;
}
.video-btn:hover {
    background-color: var(--muted);
}

.video-seek {
    flex: 1;
    min-width: 4rem;
    accent-color: #6366f1;
}
</style>
