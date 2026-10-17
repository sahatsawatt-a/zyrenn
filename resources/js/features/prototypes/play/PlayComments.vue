<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import CommentPin from '../CommentPin.vue';
import { usePrototypeComments } from '../feedback';

// Comments in Play, over the screen they were left on: the open ones as pins
// (hidden when asked), and -- in Comment mode -- a tap leaves a pin there and
// a box to say what about it. Lies over the whole screen frame, scrolling
// with it, so a spot is 0 to 1 of the frame however big it is drawn. Out of
// Comment mode only the pins take a tap; the screen under them works.
const props = defineProps<{
    board: string;
    // The screen shown, by its frame's id
    screen: string;
    // Whoever may change the prototype: they resolve any comment, and make
    // tickets of them
    canChange?: boolean;
}>();

const commenting = defineModel<boolean>('commenting', { default: false });
const shown = defineModel<boolean>('shown', { default: true });

const { open, add } = usePrototypeComments(props.board);

const here = computed(() =>
    open.value.filter((comment) => comment.screen === props.screen),
);

// ------------------------------------------------------------- A new one
const layer = useTemplateRef<HTMLDivElement>('layer');
const draft = ref<{ x: number; y: number } | null>(null);
const body = ref('');
const saving = ref(false);
const box = useTemplateRef<HTMLTextAreaElement>('box');

const place = async (event: PointerEvent) => {
    const area = layer.value?.getBoundingClientRect();

    if (!area || !area.width || !area.height) {
        return;
    }

    draft.value = {
        x: Math.min(1, Math.max(0, (event.clientX - area.left) / area.width)),
        y: Math.min(1, Math.max(0, (event.clientY - area.top) / area.height)),
    };
    body.value = '';
    await nextTick();
    box.value?.focus();
};

const cancel = () => {
    draft.value = null;
    body.value = '';
};

const save = async () => {
    const said = body.value.trim();

    if (!draft.value || !said || saving.value) {
        return;
    }

    saving.value = true;

    try {
        await add(props.screen, draft.value.x, draft.value.y, said);
        cancel();
        commenting.value = false;
        shown.value = true;
    } catch (problem) {
        toast.error((problem as Error).message);
    } finally {
        saving.value = false;
    }
};

// Leaving Comment mode, or the screen, drops a comment not yet said
watch([commenting, () => props.screen], () => cancel());

const at = (spot: { x: number; y: number }) => ({
    left: `${spot.x * 100}%`,
    top: `${spot.y * 100}%`,
});
</script>

<template>
    <div
        ref="layer"
        class="play-comments"
        :class="{ 'is-commenting': commenting }"
        data-test="play-comments"
        @pointerdown.self="commenting && !draft && place($event)"
    >
        <template v-if="shown || commenting">
            <div
                v-for="(comment, index) in here"
                :key="comment.ref_id"
                class="play-comment-spot"
                :style="at(comment)"
            >
                <CommentPin
                    :board="board"
                    :comment="comment"
                    :number="index + 1"
                    :can-resolve="!!canChange"
                    :can-ticket="!!canChange"
                />
            </div>
        </template>

        <!-- The one being written: its pin, and the box beside it -->
        <Popover
            v-if="draft"
            :open="true"
            @update:open="(open) => !open && !saving && cancel()"
        >
            <PopoverAnchor as-child>
                <div class="play-comment-spot" :style="at(draft)">
                    <span class="play-comment-draft" aria-hidden="true" />
                </div>
            </PopoverAnchor>
            <PopoverContent
                class="w-72 space-y-2 p-3"
                :collision-padding="8"
                data-test="comment-new"
                @open-auto-focus.prevent
            >
                <textarea
                    ref="box"
                    v-model="body"
                    rows="3"
                    maxlength="2000"
                    placeholder="What about this spot?"
                    aria-label="Your comment"
                    class="border-input dark:bg-input/30 placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full resize-none rounded-md border bg-transparent px-2.5 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                    data-test="comment-body"
                    @keydown.enter.exact.prevent="save"
                    @keydown.esc.stop="cancel"
                />
                <div class="flex justify-end gap-2">
                    <Button size="sm" variant="ghost" @click="cancel">
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        :disabled="!body.trim() || saving"
                        data-test="comment-save"
                        @click="save"
                    >
                        Comment
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    </div>
</template>

<style scoped>
.play-comments {
    position: absolute;
    inset: 0;
    z-index: 5;
    pointer-events: none;
}
.play-comments.is-commenting {
    pointer-events: auto;
    cursor: crosshair;
    /* A faint wash, so it is clear a tap leaves a comment */
    background: rgb(245 158 11 / 0.06);
    outline: 2px dashed rgb(245 158 11 / 0.6);
    outline-offset: -2px;
}
.play-comment-spot {
    position: absolute;
    width: 0;
    height: 0;
}
.play-comment-draft {
    position: absolute;
    left: -7px;
    top: -7px;
    width: 14px;
    height: 14px;
    background: #f59e0b;
    border: 2px solid white;
    border-radius: 50%;
    box-shadow: 0 0 0 4px rgb(245 158 11 / 0.3);
}
</style>
