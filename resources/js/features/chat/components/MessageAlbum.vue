<script setup lang="ts">
import { computed, ref } from 'vue';
import PictureDialog from '@/features/chat/components/PictureDialog.vue';

// Pictures sent together -- one after another by the same person -- drawn
// as one: a grid of them, each opened larger over the chat, where the
// others are a step away.

const props = defineProps<{
    files: { ref_id: string; name: string; url: string }[];
}>();

// How many are drawn; the last of them says how many more there are
const MOST = 9;

const drawn = computed(() => props.files.slice(0, MOST));
const more = computed(() => props.files.length - drawn.value.length);

// Three across, on a grid of six so a row can hold two: two or four are two
// across, and where three don't go evenly the first row takes what is over
// -- two wider ones, or one across the whole
const shape = (index: number) => {
    const count = drawn.value.length;
    const over = count % 3;

    if (count === 2 || count === 4 || (over === 2 && index < 2)) {
        return 'col-span-3 aspect-square';
    }

    return over === 1 && index === 0
        ? 'col-span-6 aspect-[2/1]'
        : 'col-span-2 aspect-square';
};

// The one opened larger, by where it is among them
const at = ref<number | null>(null);

const step = (by: -1 | 1) => {
    if (at.value !== null) {
        at.value = (at.value + by + props.files.length) % props.files.length;
    }
};
</script>

<template>
    <div
        class="mt-1 grid w-64 max-w-full grid-cols-6 gap-0.5 overflow-hidden rounded-2xl border sm:w-80"
        data-chat-album
    >
        <button
            v-for="(file, index) in drawn"
            :key="file.ref_id"
            type="button"
            class="bg-muted relative cursor-zoom-in"
            :class="shape(index)"
            :aria-label="`Open ${file.name}`"
            data-chat-file
            @click="at = index"
        >
            <img
                :src="file.url"
                :alt="file.name"
                loading="lazy"
                class="size-full object-cover"
            />
            <span
                v-if="more > 0 && index === drawn.length - 1"
                class="absolute inset-0 flex items-center justify-center bg-black/55 text-lg font-medium text-white"
                data-chat-album-more
                >+{{ more }}</span
            >
        </button>
    </div>
    <PictureDialog
        :open="at !== null"
        :file="at === null ? null : files[at]"
        :place="at === null ? undefined : `${at + 1} of ${files.length}`"
        @update:open="at = $event ? at : null"
        @step="step"
    />
</template>
