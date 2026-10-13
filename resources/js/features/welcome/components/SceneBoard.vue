<script setup lang="ts">
// A board: sticky notes joined by connectors that draw themselves, and
// someone moving one of them.
import LiveCursor from './LiveCursor.vue';
</script>

<template>
    <div
        class="welcome-dots flex h-full items-center justify-center overflow-hidden px-2"
    >
        <!-- the drawing's own box: the cursor is placed within it, and so stays on what it drags -->
        <div class="relative aspect-15/7 max-h-full w-full max-w-170">
            <svg viewBox="0 0 600 280" class="size-full" aria-hidden="true">
                <!-- a frame to present -->
                <rect
                    x="12"
                    y="26"
                    width="576"
                    height="242"
                    rx="10"
                    fill="none"
                    stroke="var(--border)"
                    stroke-dasharray="6 6"
                />
                <text
                    x="22"
                    y="18"
                    fill="var(--muted-foreground)"
                    font-size="12"
                >
                    Frame 1 · Customer journey
                </text>

                <!-- connectors -->
                <g
                    fill="none"
                    stroke="var(--muted-foreground)"
                    stroke-width="2"
                    stroke-linecap="round"
                >
                    <path class="welcome-draw" d="M166 106 H226" />
                    <path
                        class="welcome-draw welcome-draw-2"
                        d="M378 106 C 410 106, 400 190, 430 190"
                    />
                </g>

                <!-- stickies, and a shape -->
                <g font-size="15" font-weight="600" fill="var(--foreground)">
                    <rect
                        x="34"
                        y="62"
                        width="132"
                        height="88"
                        rx="8"
                        fill="var(--chart-3)"
                        opacity="0.28"
                    />
                    <text x="48" y="92">Hears of us</text>
                    <text
                        x="48"
                        y="113"
                        font-weight="400"
                        font-size="12"
                        fill="var(--muted-foreground)"
                    >
                        a friend, a post
                    </text>

                    <rect
                        x="226"
                        y="62"
                        width="152"
                        height="88"
                        rx="8"
                        fill="var(--chart-4)"
                        opacity="0.28"
                    />
                    <text x="240" y="92">Tries it</text>
                    <text
                        x="240"
                        y="113"
                        font-weight="400"
                        font-size="12"
                        fill="var(--muted-foreground)"
                    >
                        first note, first board
                    </text>

                    <g class="welcome-drag">
                        <rect
                            x="430"
                            y="146"
                            width="146"
                            height="88"
                            rx="8"
                            fill="var(--chart-5)"
                            opacity="0.28"
                        />
                        <text x="444" y="176">Invites the team</text>
                        <text
                            x="444"
                            y="197"
                            font-weight="400"
                            font-size="12"
                            fill="var(--muted-foreground)"
                        >
                            a project, shared
                        </text>
                    </g>

                    <ellipse
                        cx="100"
                        cy="214"
                        rx="60"
                        ry="28"
                        fill="var(--chart-2)"
                        opacity="0.22"
                    />
                    <text x="66" y="219" font-size="13">Feedback</text>
                </g>
            </svg>

            <LiveCursor name="Ken" :tone="2" class="welcome-cursor-board" />
        </div>
    </div>
</template>

<style scoped>
.welcome-dots {
    background-image: radial-gradient(
        color-mix(in oklab, var(--muted-foreground) 28%, transparent) 1px,
        transparent 1px
    );
    background-size: 18px 18px;
}

.welcome-draw {
    stroke-dasharray: 160;
    stroke-dashoffset: 160;
    animation: board-draw 1.1s ease-out 0.4s forwards;
}

.welcome-draw-2 {
    animation-delay: 2.6s;
}

/* Ken drags the last sticky into place, and its connector follows */
.welcome-drag {
    animation: board-drag 2.6s ease-in-out both;
}

.welcome-cursor-board {
    animation: board-cursor 2.6s ease-in-out both;
    top: 78%;
    left: 82%;
}

@keyframes board-draw {
    to {
        stroke-dashoffset: 0;
    }
}

@keyframes board-drag {
    0%,
    20% {
        transform: translate(-36px, 30px);
    }

    85%,
    100% {
        transform: translate(0, 0);
    }
}

@keyframes board-cursor {
    0%,
    20% {
        top: 88.7%;
        left: 76%;
    }

    85%,
    100% {
        top: 78%;
        left: 82%;
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-draw,
    .welcome-drag,
    .welcome-cursor-board {
        animation-duration: 1ms;
        animation-delay: 0s;
    }
}
</style>
