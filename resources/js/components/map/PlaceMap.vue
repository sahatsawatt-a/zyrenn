<script setup lang="ts">
import { ref, watch } from 'vue';
import { pin } from './markers';
import { useMap } from './useMap';

// A still map of one place with its pin, to show inside something else -- a
// note's map block. It keeps what it draws, so it prints, and says when it
// has drawn, so a PDF can wait for it.

const props = defineProps<{ lat: number; lng: number; zoom: number }>();
const emit = defineEmits<{ ready: [] }>();

const host = ref<HTMLElement>();
const marker = pin('#ef4444');
const { map } = useMap(host, {
    bare: true,
    still: true,
    printable: true,
    center: [props.lng, props.lat],
    zoom: props.zoom,
});

watch(
    [map, () => props.lat, () => props.lng, () => props.zoom],
    () => {
        if (!map.value) {
            return;
        }

        const at: [number, number] = [props.lng, props.lat];
        map.value.jumpTo({ center: at, zoom: props.zoom });
        marker.setLngLat(at).addTo(map.value);
        map.value.once('idle', () => emit('ready'));
    },
    { immediate: true },
);
</script>

<template>
    <div ref="host" class="size-full" data-test="place-map" />
</template>
