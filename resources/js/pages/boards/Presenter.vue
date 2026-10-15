<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, X } from '@lucide/vue';
import { useIntervalFn } from '@vueuse/core';
import { computed, ref } from 'vue';
import BoardView from '@/features/boards/components/BoardView.vue';
import {
    presenterChannel,
    usePresenterView,
} from '@/features/boards/composables/usePresenterLink';

// The presenter's own window while a board is shown as slides, on their
// screen rather than the audience's: what they say over this slide, the
// slide after it, and how long they have been going. It follows the show
// in the same browser, and moves it on.
const props = defineProps<{ board: { ref_id: string; title: string } }>();

const { state, go, stop } = usePresenterView(
    presenterChannel(props.board.ref_id),
);

const now = ref(Date.now());
useIntervalFn(() => (now.value = Date.now()), 1000);

const elapsed = computed(() => {
    const started = state.value?.startedAt;

    if (!started) {
        return '0:00';
    }

    const seconds = Math.max(0, Math.floor((now.value - started) / 1000));
    const minutes = Math.floor(seconds / 60);

    return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
});

const showing = computed(() => state.value?.presenting && state.value.current);
</script>

<template>
    <Head :title="`Presenting ${board.title || 'a board'}`" />

    <main class="presenter" data-test="presenter">
        <p v-if="!state" class="presenter-wait">
            Start presenting the board, and this window follows it.
        </p>
        <p v-else-if="!showing" class="presenter-wait">
            The show is over. Present the board again and this window follows
            it.
        </p>

        <template v-else-if="state && state.current">
            <header class="presenter-head">
                <span data-test="presenter-position"
                    >{{ state.index + 1 }} / {{ state.count }}</span
                >
                <span class="truncate font-semibold">{{
                    state.current.title
                }}</span>
                <span class="tabular-nums" data-test="presenter-clock">{{
                    elapsed
                }}</span>
            </header>

            <section class="presenter-slides">
                <div class="presenter-now">
                    <BoardView
                        :items="state.current.items"
                        :frame="state.current.id"
                        :deck="state.deck"
                        :title="state.title"
                        :decor="
                            state.current.decor
                                ? { [state.current.id]: state.current.decor }
                                : {}
                        "
                        bare
                        :padding="0"
                        plain
                    />
                </div>
                <div class="presenter-next">
                    <p class="presenter-label">Next</p>
                    <div v-if="state.next" class="presenter-next-slide">
                        <BoardView
                            :items="state.next.items"
                            :frame="state.next.id"
                            :deck="state.deck"
                            :title="state.title"
                            :decor="
                                state.next.decor
                                    ? { [state.next.id]: state.next.decor }
                                    : {}
                            "
                            bare
                            :padding="0"
                            plain
                        />
                    </div>
                    <p v-else class="presenter-label">The end</p>
                </div>
            </section>

            <section class="presenter-notes" data-test="presenter-notes">
                <p
                    v-if="state.current.notes.trim()"
                    class="whitespace-pre-wrap"
                >
                    {{ state.current.notes }}
                </p>
                <p v-else class="text-muted-foreground">
                    No notes on this slide.
                </p>
            </section>

            <footer class="presenter-foot">
                <button
                    type="button"
                    :disabled="state.index === 0"
                    data-test="presenter-prev"
                    @click="go(state.index - 1)"
                >
                    <ChevronLeft class="size-5" />
                    Back
                </button>
                <button
                    type="button"
                    :disabled="state.index >= state.count - 1"
                    data-test="presenter-next"
                    @click="go(state.index + 1)"
                >
                    Next
                    <ChevronRight class="size-5" />
                </button>
                <button
                    type="button"
                    class="ml-auto"
                    data-test="presenter-stop"
                    @click="stop"
                >
                    <X class="size-5" />
                    End the show
                </button>
            </footer>
        </template>
    </main>
</template>

<style scoped>
.presenter {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    height: 100vh;
    padding: 1rem;
    background-color: var(--background);
    color: var(--foreground);
}

.presenter-wait {
    margin: auto;
    max-width: 28rem;
    text-align: center;
    color: var(--muted-foreground);
}

.presenter-head {
    display: flex;
    align-items: center;
    gap: 1rem;
    font-size: 1.125rem;
}
.presenter-head > :nth-child(2) {
    flex: 1;
    min-width: 0;
}

.presenter-slides {
    display: grid;
    grid-template-columns: 3fr 2fr;
    gap: 1rem;
    min-height: 0;
    flex: 0 0 45%;
}

.presenter-now,
.presenter-next-slide {
    aspect-ratio: 16 / 9;
    max-height: 100%;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
}
.presenter-next {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    min-height: 0;
}

.presenter-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted-foreground);
}

.presenter-notes {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    font-size: 1.375rem;
    line-height: 1.5;
}

.presenter-foot {
    display: flex;
    gap: 0.5rem;
}
.presenter-foot button {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.5rem 1rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.presenter-foot button:hover:not(:disabled) {
    background-color: var(--muted);
}
.presenter-foot button:disabled {
    opacity: 0.4;
    cursor: default;
}

/* On a phone the two slides stack, and the notes take what is left */
@media (max-width: 640px) {
    .presenter-slides {
        grid-template-columns: 1fr;
        flex: 0 0 auto;
    }
    .presenter-next {
        display: none;
    }
    .presenter-notes {
        font-size: 1.125rem;
    }
}
</style>
