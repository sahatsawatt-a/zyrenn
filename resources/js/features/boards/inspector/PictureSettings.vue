<script setup lang="ts">
import { SlidersHorizontal } from '@lucide/vue';
import { ref } from 'vue';
import PhotoEditor from '@/components/photo/PhotoEditor.vue';
import type { Fit, Item } from '../model/items';

// A picture chosen on its own: how it fills its box, and the photo editor
// for its pixels, which saves a new picture in its place.
defineProps<{ picture: Item }>();
const emit = defineEmits<{ update: [patch: Partial<Item>] }>();

const editing = ref(false);

const fits: { value: Fit; label: string; title: string }[] = [
    { value: 'fill', label: 'Stretch', title: 'Stretched to the box' },
    { value: 'contain', label: 'Fit', title: 'Whole, inside the box' },
    { value: 'cover', label: 'Fill', title: 'Covering the box, edges cut off' },
];
</script>

<template>
    <!-- How a picture fills its box -->
    <div class="inspector-align" data-test="fit-config">
        <p class="inspector-label">Picture</p>
        <div class="inspector-segments">
            <button
                v-for="option in fits"
                :key="option.value"
                type="button"
                :title="option.title"
                :class="{ 'is-on': picture.fit === option.value }"
                :data-test="`fit-${option.value}`"
                @click="emit('update', { fit: option.value })"
            >
                {{ option.label }}
            </button>
        </div>
        <div class="inspector-segments">
            <button
                type="button"
                class="gap-1.5"
                data-test="edit-photo"
                @click="editing = true"
            >
                <SlidersHorizontal :size="13" /> Edit photo
            </button>
        </div>
        <PhotoEditor
            v-if="editing"
            v-model:open="editing"
            :src="picture.src"
            @saved="
                emit('update', {
                    src: $event.src,
                    height: Math.round(
                        (picture.width * $event.height) / $event.width,
                    ),
                })
            "
        />
    </div>
</template>
