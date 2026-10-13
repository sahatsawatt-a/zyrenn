<script setup lang="ts">
// A trip: a day's stops in order, and the route between them drawn on a map.
const STOPS = [
    { time: '09:00', place: 'Fushimi Inari', x: 430, y: 236 },
    { time: '12:30', place: 'Nishiki Market', x: 300, y: 150 },
    { time: '15:00', place: 'Kinkaku-ji', x: 150, y: 70 },
];
</script>

<template>
    <div
        class="grid h-full grid-rows-[auto_1fr] sm:grid-cols-[13rem_1fr] sm:grid-rows-1"
    >
        <div class="border-border px-4 py-5 sm:border-r sm:px-5">
            <div class="text-muted-foreground text-xs">Kyoto, 3 days</div>
            <h3 class="mt-0.5 text-lg font-semibold tracking-tight">Day 2</h3>
            <ol class="mt-3 space-y-2.5 text-sm">
                <li
                    v-for="(stop, at) in STOPS"
                    :key="stop.place"
                    class="welcome-stop flex items-center gap-2.5"
                    :style="{ animationDelay: `${at * 0.8}s` }"
                >
                    <span
                        class="bg-primary text-primary-foreground flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold"
                        >{{ at + 1 }}</span
                    >
                    <span class="min-w-0 flex-1 truncate">{{
                        stop.place
                    }}</span>
                    <span class="text-muted-foreground text-xs tabular-nums">{{
                        stop.time
                    }}</span>
                </li>
            </ol>
            <div class="text-muted-foreground mt-3 text-xs">
                7.4 km · 31 min between stops
            </div>
        </div>

        <div class="bg-chart-4/8 relative min-h-24 overflow-hidden">
            <svg
                viewBox="0 0 560 320"
                class="absolute inset-0 size-full"
                preserveAspectRatio="xMidYMid slice"
                aria-hidden="true"
            >
                <!-- a river, a park, some streets -->
                <path
                    d="M-20 250 C 120 200, 220 300, 360 250 S 520 180, 600 210"
                    fill="none"
                    stroke="var(--chart-2)"
                    stroke-width="16"
                    opacity="0.22"
                />
                <rect
                    x="40"
                    y="170"
                    width="120"
                    height="90"
                    rx="18"
                    fill="var(--chart-4)"
                    opacity="0.2"
                />
                <g
                    stroke="var(--card)"
                    stroke-width="6"
                    opacity="0.9"
                    fill="none"
                >
                    <path d="M0 110 H560" />
                    <path d="M0 200 H560" />
                    <path d="M210 0 V320" />
                    <path d="M380 0 V320" />
                    <path d="M60 0 L 520 320" />
                </g>
                <!-- the way between the stops -->
                <path
                    class="welcome-route"
                    d="M430 236 L 380 200 L 300 200 L 300 150 L 210 110 L 150 70"
                    fill="none"
                    stroke="var(--primary)"
                    stroke-width="4"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
                <!-- each stop, numbered as the list beside numbers it -->
                <g
                    v-for="(stop, at) in STOPS"
                    :key="stop.place"
                    class="welcome-pin"
                    :style="{ animationDelay: `${at * 0.8}s` }"
                >
                    <circle
                        :cx="stop.x"
                        :cy="stop.y"
                        r="13"
                        fill="var(--primary)"
                        stroke="var(--card)"
                        stroke-width="3"
                    />
                    <text
                        :x="stop.x"
                        :y="stop.y + 4.5"
                        text-anchor="middle"
                        font-size="13"
                        font-weight="700"
                        fill="var(--primary-foreground)"
                    >
                        {{ at + 1 }}
                    </text>
                </g>
            </svg>
        </div>
    </div>
</template>

<style scoped>
.welcome-route {
    stroke-dasharray: 520;
    stroke-dashoffset: 520;
    animation: trip-route 2.4s ease-in-out 0.3s forwards;
}

.welcome-pin {
    animation: trip-pin 0.45s cubic-bezier(0.3, 1.5, 0.6, 1) both;
}

.welcome-stop {
    animation: trip-stop 0.45s ease-out both;
}

@keyframes trip-route {
    to {
        stroke-dashoffset: 0;
    }
}

@keyframes trip-pin {
    from {
        opacity: 0;
        transform: translateY(-14px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes trip-stop {
    from {
        opacity: 0;
        transform: translateX(-6px);
    }

    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-route,
    .welcome-pin,
    .welcome-stop {
        animation-duration: 1ms;
        animation-delay: 0s !important;
    }
}
</style>
