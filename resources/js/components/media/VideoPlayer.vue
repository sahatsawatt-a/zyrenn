<script setup lang="ts">
import RangeField from '@/components/common/RangeField.vue';
import {
    Gauge,
    Loader2,
    Maximize,
    Minimize,
    Pause,
    PictureInPicture2,
    Play,
    RotateCcw,
    RotateCw,
    Volume1,
    Volume2,
    VolumeX,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useAttrs,
    watch,
} from 'vue';
import Tip from '@/components/common/Tip.vue';

// The app's one video player, wherever a video plays -- a note, a chat, the
// Drive's viewer, a browser check's film: the picture, and over it controls
// that keep out of the way while it plays. A timeline that grows under the
// pointer and shows the moment it would go to, with its chapter's name;
// speed and volume, remembered; fullscreen and picture-in-picture; the keys
// most players answer to; and on a phone, a double tap on a side to skip.
//
// What it is given beside its own props -- a data-test, a title -- goes on
// the <video> itself, as before; its class on the player round it.

export type Chapter = { at: number; title: string };

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        src: string;
        // Each a moment (s) the video's parts begin at, named
        chapters?: Chapter[];
        autoplay?: boolean;
        // Where to start (s)
        startAt?: number;
        // How the picture fills the player
        fit?: 'contain' | 'cover';
    }>(),
    { chapters: () => [], fit: 'contain' },
);

const emit = defineEmits<{
    error: [event: Event];
    loadedmetadata: [event: Event];
    timeupdate: [seconds: number];
}>();

const attrs = useAttrs();
const outer = computed(() => ({ class: attrs.class, style: attrs.style }));
const inner = computed(() => {
    const { class: _class, style: _style, ...rest } = attrs;

    return rest;
});

const root = ref<HTMLDivElement | null>(null);
const video = ref<HTMLVideoElement | null>(null);

const playing = ref(false);
const ended = ref(false);
const waiting = ref(false);
const now = ref(0);
const length = ref(0);
const loaded = ref(0);
const volume = ref(1);
const muted = ref(false);
const rate = ref(1);
const full = ref(false);
// Fullscreen shows only the player: a tip of its buttons is drawn inside it then
const fullTip = computed(() => (full.value && root.value) || undefined);
const started = ref(false);
const narrow = ref(false);

const RATES = [0.5, 0.75, 1, 1.25, 1.5, 2];
const SKIP = 10;

// ------------------------------------------------------------ Remembered
// Per viewer, in this browser: how loud, and how fast
const remembered = (key: string, fallback: number) => {
    try {
        const value = Number(localStorage.getItem(`player.${key}`));

        return Number.isFinite(value) &&
            localStorage.getItem(`player.${key}`) !== null
            ? value
            : fallback;
    } catch {
        return fallback;
    }
};
const remember = (key: string, value: number) => {
    try {
        localStorage.setItem(`player.${key}`, String(value));
    } catch {
        // Not kept, in a private window: still set for this video
    }
};

// ------------------------------------------------------------ Time
const clock = (seconds: number) => {
    const s = Math.max(0, Math.floor(seconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const rest = String(s % 60).padStart(2, '0');

    return h ? `${h}:${String(m).padStart(2, '0')}:${rest}` : `${m}:${rest}`;
};

const chapterAt = (seconds: number) =>
    props.chapters.findLast((chapter) => chapter.at <= seconds + 0.05) ?? null;
const chapter = computed(() => chapterAt(now.value));

// ------------------------------------------------------------ Playing
const play = () => video.value?.play().catch(() => {});
const pause = () => video.value?.pause();
const toggle = () => (video.value?.paused ? play() : pause());

/** To `seconds` in, within the video. */
function seek(seconds: number) {
    const v = video.value;

    if (!v) return;

    const end = Number.isFinite(v.duration) ? v.duration : seconds;
    v.currentTime = Math.min(Math.max(0, seconds), end);
    now.value = v.currentTime;
    ended.value = false;
}

const skip = (by: number) => seek((video.value?.currentTime ?? 0) + by);

function setVolume(value: number) {
    const v = video.value;

    if (!v) return;

    v.volume = Math.min(1, Math.max(0, value));
    v.muted = v.volume === 0;
}

const toggleMute = () => {
    const v = video.value;

    if (!v) return;

    v.muted = !v.muted;

    if (!v.muted && v.volume === 0) v.volume = 0.5;
};

function setRate(value: number) {
    if (video.value) video.value.playbackRate = value;
    speedOpen.value = false;
}

// The video's own events, mirrored for the controls
// (each looks for the video first: one going away still sends a last event)
function onMeta(event: Event) {
    const v = video.value;

    if (!v) return;

    length.value = Number.isFinite(v.duration) ? v.duration : 0;
    v.volume = remembered('volume', 1);
    v.muted = remembered('muted', 0) === 1;
    v.playbackRate = remembered('rate', 1);

    if (props.startAt) seek(props.startAt);

    emit('loadedmetadata', event);
}

function onTime() {
    const v = video.value;

    if (!v) return;

    now.value = v.currentTime;

    if (v.buffered.length) {
        loaded.value = v.buffered.end(v.buffered.length - 1);
    }

    emit('timeupdate', v.currentTime);
}

function onVolume() {
    const v = video.value;

    if (!v) return;

    volume.value = v.volume;
    muted.value = v.muted;
    remember('volume', v.volume);
    remember('muted', v.muted ? 1 : 0);
}

function onRate() {
    if (!video.value) return;

    rate.value = video.value.playbackRate;
    remember('rate', rate.value);
}

watch(
    () => props.src,
    () => {
        ended.value = false;
        started.value = false;
        now.value = 0;
        length.value = 0;
    },
);

// ------------------------------------------------------------ Controls in view
// Shown when paused, for a moment after the pointer moves, and while one of
// them has the keyboard; while it plays, they step aside
const lively = ref(true);
let resting: ReturnType<typeof setTimeout> | undefined;

function wake() {
    lively.value = true;
    clearTimeout(resting);
    resting = setTimeout(() => (lively.value = false), 2500);
}

const shown = computed(
    () =>
        !playing.value ||
        lively.value ||
        scrubbing.value ||
        speedOpen.value ||
        waiting.value,
);

// ------------------------------------------------------------ Timeline
const bar = ref<HTMLDivElement | null>(null);
const scrubbing = ref(false);
const hoverAt = ref<number | null>(null);
const hoverX = ref(0);
let wasPlaying = false;

const fraction = (x: number) => {
    const box = bar.value!.getBoundingClientRect();

    return Math.min(1, Math.max(0, (x - box.left) / box.width));
};

function barMove(event: PointerEvent) {
    if (!length.value || !bar.value) return;

    const at = fraction(event.clientX) * length.value;
    hoverAt.value = at;
    hoverX.value = event.clientX - bar.value.getBoundingClientRect().left;
    preview(at);

    if (scrubbing.value) seek(at);
}

function barDown(event: PointerEvent) {
    if (!length.value) return;

    scrubbing.value = true;
    wasPlaying = !(video.value?.paused ?? true);
    pause();
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    seek(fraction(event.clientX) * length.value);
}

function barUp() {
    if (!scrubbing.value) return;

    scrubbing.value = false;

    if (wasPlaying) play();
}

// A picture of the moment under the pointer: a second, silent copy of the
// video, sought there -- made only once the timeline is first pointed at
const peek = ref<HTMLVideoElement | null>(null);
const peeking = ref(false);
let peekTimer: ReturnType<typeof setTimeout> | undefined;

function preview(at: number) {
    peeking.value = true;
    clearTimeout(peekTimer);
    peekTimer = setTimeout(() => {
        if (peek.value && Number.isFinite(at)) peek.value.currentTime = at;
    }, 60);
}

const tipLeft = computed(() => {
    const width = bar.value?.clientWidth ?? 0;

    return Math.min(Math.max(hoverX.value, 80), Math.max(80, width - 80));
});

// ------------------------------------------------------------ Speed menu
// Drawn inside the player, not in a layer of the page: fullscreen shows only
// what is inside it
const speedOpen = ref(false);

// ------------------------------------------------------------ Fullscreen, PiP
const canPip = computed(
    () =>
        typeof document !== 'undefined' &&
        'pictureInPictureEnabled' in document &&
        document.pictureInPictureEnabled,
);

async function toggleFull() {
    const el = root.value;

    if (!el) return;

    if (document.fullscreenElement) {
        await document.exitFullscreen().catch(() => {});

        return;
    }

    if (el.requestFullscreen) {
        await el.requestFullscreen().catch(() => {});
    } else {
        // An iPhone: only the video itself goes fullscreen, with its own controls
        (
            video.value as HTMLVideoElement & {
                webkitEnterFullscreen?: () => void;
            }
        )?.webkitEnterFullscreen?.();
    }
}

async function togglePip() {
    if (!video.value) return;

    if (document.pictureInPictureElement) {
        await document.exitPictureInPicture().catch(() => {});
    } else {
        await video.value.requestPictureInPicture().catch(() => {});
    }
}

const onFullChange = () =>
    (full.value = document.fullscreenElement === root.value);

// ------------------------------------------------------------ Keys
function onKey(event: KeyboardEvent) {
    if (event.metaKey || event.ctrlKey || event.altKey) return;

    const target = event.target as HTMLElement;

    // A button of its own answers Space and Enter itself
    if (target !== root.value && ['Enter', ' '].includes(event.key)) return;

    const v = video.value;
    let done = true;

    switch (event.key) {
        case ' ':
        case 'k':
            toggle();
            break;
        case 'j':
            skip(-SKIP);
            break;
        case 'l':
            skip(SKIP);
            break;
        case 'ArrowLeft':
            skip(-5);
            break;
        case 'ArrowRight':
            skip(5);
            break;
        case 'ArrowUp':
            setVolume((v?.volume ?? 1) + 0.1);
            break;
        case 'ArrowDown':
            setVolume((v?.volume ?? 1) - 0.1);
            break;
        case 'm':
            toggleMute();
            break;
        case 'f':
            void toggleFull();
            break;
        case 'Home':
            seek(0);
            break;
        case 'End':
            seek(length.value);
            break;
        // A frame at a time, paused
        case ',':
        case '.':
            pause();
            skip(event.key === '.' ? 1 / 25 : -1 / 25);
            break;
        default:
            if (/^[0-9]$/.test(event.key) && length.value) {
                seek((Number(event.key) / 10) * length.value);
            } else {
                done = false;
            }
    }

    if (done) {
        event.preventDefault();
        event.stopPropagation();
        wake();
    }
}

// ------------------------------------------------------------ Pointer and touch
// A click plays or pauses; a double click goes fullscreen. On a touch
// screen a tap brings the controls up (then plays or pauses), and a double
// tap on either side skips back or on
const burst = ref<{ side: 'back' | 'on'; key: number } | null>(null);
let lastTap = 0;
let tapTimer: ReturnType<typeof setTimeout> | undefined;

function onPictureUp(event: PointerEvent) {
    root.value?.focus({ preventScroll: true });

    if (event.pointerType !== 'touch') {
        clearTimeout(tapTimer);
        tapTimer = setTimeout(toggle, 220);

        return;
    }

    const at = Date.now();
    const box = root.value!.getBoundingClientRect();
    const x = (event.clientX - box.left) / box.width;

    if (at - lastTap < 300 && (x < 0.35 || x > 0.65)) {
        clearTimeout(tapTimer);
        const side = x < 0.35 ? 'back' : 'on';
        skip(side === 'back' ? -SKIP : SKIP);
        burst.value = { side, key: at };
        setTimeout(() => {
            if (burst.value?.key === at) burst.value = null;
        }, 600);
        lastTap = 0;

        return;
    }

    lastTap = at;
    clearTimeout(tapTimer);
    tapTimer = setTimeout(() => {
        if (!shown.value) {
            wake();
        } else {
            toggle();
            wake();
        }
    }, 300);
}

function onPictureDouble(event: MouseEvent) {
    clearTimeout(tapTimer);

    if ((event as PointerEvent).pointerType !== 'touch') void toggleFull();
}

// ------------------------------------------------------------ Size
let watcher: ResizeObserver | undefined;

onMounted(() => {
    document.addEventListener('fullscreenchange', onFullChange);
    watcher = new ResizeObserver(([entry]) => {
        narrow.value = entry.contentRect.width < 420;
    });

    if (root.value) watcher.observe(root.value);

    if (props.autoplay) void nextTick(play);
});

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', onFullChange);
    watcher?.disconnect();
    clearTimeout(resting);
    clearTimeout(peekTimer);
    clearTimeout(tapTimer);
});

const volumeIcon = computed(() =>
    muted.value || volume.value === 0
        ? VolumeX
        : volume.value < 0.5
          ? Volume1
          : Volume2,
);

defineExpose({
    video,
    play,
    pause,
    seek,
    currentTime: () => video.value?.currentTime ?? 0,
});
</script>

<template>
    <div
        ref="root"
        v-bind="outer"
        class="vp group/vp relative overflow-hidden bg-black text-white outline-none select-none focus-visible:ring-2 focus-visible:ring-sky-400"
        :class="{ 'vp-full': full, 'cursor-none': full && !shown }"
        tabindex="0"
        role="region"
        aria-label="Video"
        data-video-player
        @keydown="onKey"
        @pointermove="wake"
        @pointerleave="lively = false"
        @dragstart.prevent
    >
        <video
            ref="video"
            v-bind="inner"
            :src="src"
            :autoplay="autoplay"
            preload="metadata"
            playsinline
            class="vp-picture block size-full"
            :class="fit === 'cover' ? 'object-cover' : 'object-contain'"
            @pointerup="onPictureUp"
            @dblclick="onPictureDouble"
            @loadedmetadata="onMeta"
            @durationchange="
                length =
                    video && Number.isFinite(video.duration)
                        ? video.duration
                        : 0
            "
            @timeupdate="onTime"
            @progress="onTime"
            @play="
                playing = true;
                started = true;
                ended = false;
                wake();
            "
            @pause="playing = false"
            @ended="
                playing = false;
                ended = true;
            "
            @waiting="waiting = true"
            @playing="waiting = false"
            @canplay="waiting = false"
            @volumechange="onVolume"
            @ratechange="onRate"
            @error="emit('error', $event)"
        />

        <!-- Waiting for the picture -->
        <div
            v-if="waiting && playing"
            class="pointer-events-none absolute inset-0 flex items-center justify-center"
        >
            <Loader2 class="size-10 animate-spin text-white/80" />
        </div>

        <!-- Before it starts, and once it ends: one big button -->
        <button
            v-if="!playing && !scrubbing && (!started || ended)"
            type="button"
            class="absolute top-1/2 left-1/2 flex size-14 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-black/55 backdrop-blur-sm transition-transform hover:scale-110 focus-visible:ring-2 focus-visible:ring-sky-400 focus-visible:outline-none"
            :aria-label="ended ? 'Play again' : 'Play'"
            data-test="vp-big-play"
            @click="
                ended && seek(0);
                play();
            "
        >
            <RotateCcw v-if="ended" class="size-6" />
            <Play v-else class="ml-0.5 size-6 fill-current" />
        </button>

        <!-- A double tap's skip, said where it was tapped -->
        <Transition name="vp-fade">
            <div
                v-if="burst"
                :key="burst.key"
                class="pointer-events-none absolute top-1/2 flex -translate-y-1/2 items-center gap-1 rounded-full bg-black/50 px-3 py-2 text-sm font-medium"
                :class="burst.side === 'back' ? 'left-6' : 'right-6'"
            >
                <RotateCcw v-if="burst.side === 'back'" class="size-4" />
                {{ burst.side === 'back' ? '−' : '+' }}{{ SKIP }} s
                <RotateCw v-if="burst.side === 'on'" class="size-4" />
            </div>
        </Transition>

        <!-- The controls, over the bottom of the picture -->
        <div
            class="vp-controls absolute inset-x-0 bottom-0 px-3 pt-8 pb-2 transition-opacity duration-200 print:hidden"
            :class="shown ? 'opacity-100' : 'pointer-events-none opacity-0'"
            @pointerdown.stop
            @mousedown.stop
        >
            <!-- Timeline -->
            <div
                ref="bar"
                class="vp-bar group/bar relative flex h-4 cursor-pointer touch-none items-center"
                role="slider"
                tabindex="-1"
                aria-label="Seek"
                :aria-valuemin="0"
                :aria-valuemax="Math.round(length)"
                :aria-valuenow="Math.round(now)"
                :aria-valuetext="`${clock(now)} of ${clock(length)}`"
                data-test="vp-bar"
                @pointermove="barMove"
                @pointerdown="barDown"
                @pointerup="barUp"
                @pointercancel="barUp"
                @pointerleave="!scrubbing && (hoverAt = null)"
            >
                <div
                    class="relative h-1 w-full overflow-hidden rounded-full bg-white/25 transition-[height] group-hover/bar:h-1.5"
                    :class="{ 'h-1.5': scrubbing }"
                >
                    <div
                        class="absolute inset-y-0 left-0 bg-white/35"
                        :style="{
                            width: `${length ? (loaded / length) * 100 : 0}%`,
                        }"
                    />
                    <div
                        v-if="hoverAt !== null"
                        class="absolute inset-y-0 left-0 bg-white/30"
                        :style="{ width: `${(hoverAt / length) * 100}%` }"
                    />
                    <div
                        class="absolute inset-y-0 left-0 bg-sky-400"
                        :style="{
                            width: `${length ? (now / length) * 100 : 0}%`,
                        }"
                    />
                    <!-- Where each chapter begins: a gap in the line -->
                    <span
                        v-for="one in chapters.filter((c) => c.at > 0)"
                        :key="one.at"
                        class="absolute inset-y-0 w-0.5 bg-black/70"
                        :style="{ left: `${(one.at / (length || 1)) * 100}%` }"
                        data-test="vp-chapter"
                    />
                </div>
                <span
                    class="absolute size-3 -translate-x-1/2 scale-0 rounded-full bg-sky-400 shadow transition-transform group-hover/bar:scale-100"
                    :class="{ 'scale-100': scrubbing }"
                    :style="{
                        left: `${length ? (now / length) * 100 : 0}%`,
                    }"
                />

                <!-- The moment under the pointer -->
                <div
                    v-if="hoverAt !== null"
                    class="pointer-events-none absolute bottom-5 flex -translate-x-1/2 flex-col items-center gap-1"
                    :style="{ left: `${tipLeft}px` }"
                    data-test="vp-tip"
                >
                    <video
                        v-if="peeking && !narrow"
                        ref="peek"
                        :src="src"
                        muted
                        preload="auto"
                        class="aspect-video w-40 rounded-md border border-white/20 bg-black object-cover shadow-lg"
                    />
                    <span
                        class="max-w-48 truncate rounded bg-black/80 px-1.5 py-0.5 text-xs tabular-nums"
                    >
                        <template v-if="chapterAt(hoverAt)"
                            >{{ chapterAt(hoverAt)!.title }} ·
                        </template>
                        {{ clock(hoverAt) }}
                    </span>
                </div>
            </div>

            <!-- Buttons -->
            <div class="mt-1 flex items-center gap-1 text-sm">
                <Tip :text="playing ? 'Pause (k)' : 'Play (k)'" :to="fullTip">
                    <button
                        type="button"
                        class="vp-btn"
                        :aria-label="playing ? 'Pause (k)' : 'Play (k)'"
                        data-test="vp-play"
                        @click="toggle"
                    >
                        <Pause v-if="playing" class="size-5 fill-current" />
                        <Play v-else class="size-5 fill-current" />
                    </button>
                </Tip>
                <template v-if="!narrow">
                    <Tip :text="`Back ${SKIP} s (j)`" :to="fullTip">
                        <button
                            type="button"
                            class="vp-btn"
                            :aria-label="`Back ${SKIP} seconds (j)`"
                            @click="skip(-SKIP)"
                        >
                            <RotateCcw class="size-4" />
                        </button>
                    </Tip>
                    <Tip :text="`On ${SKIP} s (l)`" :to="fullTip">
                        <button
                            type="button"
                            class="vp-btn"
                            :aria-label="`On ${SKIP} seconds (l)`"
                            @click="skip(SKIP)"
                        >
                            <RotateCw class="size-4" />
                        </button>
                    </Tip>
                </template>
                <div class="group/vol flex items-center">
                    <Tip
                        :text="muted ? 'Unmute (m)' : 'Mute (m)'"
                        :to="fullTip"
                    >
                        <button
                            type="button"
                            class="vp-btn"
                            :aria-label="muted ? 'Unmute (m)' : 'Mute (m)'"
                            data-test="vp-mute"
                            @click="toggleMute"
                        >
                            <component :is="volumeIcon" class="size-5" />
                        </button>
                    </Tip>
                    <RangeField
                        v-if="!narrow"
                        :model-value="muted ? 0 : volume"
                        label="Volume"
                        :min="0"
                        :max="1"
                        :step="0.05"
                        :format="false"
                        class="vp-volume w-0 opacity-0 transition-[width,opacity] group-focus-within/vol:w-16 group-focus-within/vol:opacity-100 group-hover/vol:w-16 group-hover/vol:opacity-100"
                        @update:model-value="setVolume($event)"
                    />
                </div>
                <span
                    class="ml-1 shrink-0 text-xs text-white/90 tabular-nums"
                    data-test="vp-time"
                    >{{ clock(now) }}
                    <span class="text-white/60"
                        >/ {{ clock(length) }}</span
                    ></span
                >
                <span
                    v-if="chapter && !narrow"
                    class="ml-1 min-w-0 truncate text-xs text-white/80"
                    data-test="vp-chapter-now"
                    >· {{ chapter.title }}</span
                >

                <div class="ml-auto flex items-center gap-1">
                    <div class="relative">
                        <Tip text="Speed" :to="fullTip">
                            <button
                                type="button"
                                class="vp-btn gap-1 px-1.5 text-xs tabular-nums"
                                :aria-expanded="speedOpen"
                                aria-label="Speed"
                                data-test="vp-speed"
                                @click="speedOpen = !speedOpen"
                            >
                                <Gauge v-if="rate === 1" class="size-4" />
                                <template v-else>{{ rate }}×</template>
                            </button>
                        </Tip>
                        <div
                            v-if="speedOpen"
                            class="absolute right-0 bottom-9 z-10 min-w-24 rounded-lg bg-black/85 p-1 shadow-lg backdrop-blur-sm"
                            role="menu"
                        >
                            <button
                                v-for="one in RATES"
                                :key="one"
                                type="button"
                                role="menuitemradio"
                                :aria-checked="rate === one"
                                class="flex w-full items-center justify-between rounded px-2 py-1 text-left text-xs tabular-nums hover:bg-white/15"
                                :class="{ 'text-sky-300': rate === one }"
                                @click="setRate(one)"
                            >
                                {{ one === 1 ? 'Normal' : `${one}×` }}
                            </button>
                        </div>
                    </div>
                    <Tip
                        v-if="canPip && !narrow"
                        text="Picture in picture"
                        :to="fullTip"
                    >
                        <button
                            type="button"
                            class="vp-btn"
                            aria-label="Picture in picture"
                            @click="togglePip"
                        >
                            <PictureInPicture2 class="size-4" />
                        </button>
                    </Tip>
                    <Tip
                        :text="
                            full ? 'Exit full screen (f)' : 'Full screen (f)'
                        "
                        :to="fullTip"
                    >
                        <button
                            type="button"
                            class="vp-btn"
                            :aria-label="
                                full
                                    ? 'Exit full screen (f)'
                                    : 'Full screen (f)'
                            "
                            data-test="vp-full"
                            @click="toggleFull"
                        >
                            <Minimize v-if="full" class="size-4" />
                            <Maximize v-else class="size-4" />
                        </button>
                    </Tip>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.vp-controls {
    background: linear-gradient(
        to top,
        rgb(0 0 0 / 0.7),
        rgb(0 0 0 / 0.35) 55%,
        transparent
    );
}

.vp-picture {
    /* 16:9 until it knows its own shape, never past the room it is given */
    aspect-ratio: auto 16 / 9;
    max-height: inherit;
}

.vp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2rem;
    height: 2rem;
    border-radius: 0.375rem;
    color: rgb(255 255 255 / 0.92);
    cursor: pointer;
}
.vp-btn:hover {
    color: white;
    background-color: rgb(255 255 255 / 0.12);
}
.vp-btn:focus-visible {
    outline: 2px solid rgb(56 189 248);
    outline-offset: 1px;
}

/* Fullscreen: the picture as large as the screen lets it be */
.vp-full {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100vw;
    height: 100vh;
    max-width: none !important;
    max-height: none !important;
    border-radius: 0 !important;
}
.vp-full .vp-picture {
    max-height: 100vh !important;
}

.vp-fade-enter-active,
.vp-fade-leave-active {
    transition: opacity 0.2s;
}
.vp-fade-enter-from,
.vp-fade-leave-to {
    opacity: 0;
}
</style>
