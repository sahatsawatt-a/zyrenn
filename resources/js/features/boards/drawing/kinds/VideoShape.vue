<script setup lang="ts">
import type Konva from 'konva';
import { Circle, Image as KonvaImage, Line, Rect, Shape } from 'vue-konva';
import type { Item } from '../../model/items';
import { pictured } from '../itemLook';
import { VIDEO_PLAY } from '../useVideos';

// A video: a frame of it, redrawn as it plays, with a play button over it
// while it is paused. Every part stays mounted and is only shown or hidden:
// vue-konva adds a node that mounts later on top of the rest, which would
// bury the button under the frame.
defineProps<{
    item: Item;
    selected: boolean;
    image?: HTMLImageElement;
    video?: HTMLVideoElement;
    playing?: boolean;
}>();

/** The video's own shape, as large as fits the item, with bars round it. */
const fitted = (item: Item, video: HTMLVideoElement) => {
    const width = video.videoWidth || item.width;
    const height = video.videoHeight || item.height;
    const scale = Math.min(item.width / width, item.height / height);

    return {
        x: (item.width - width * scale) / 2,
        y: (item.height - height * scale) / 2,
        width: width * scale,
        height: height * scale,
    };
};

/**
 * How far through it is, as a line along the bottom. Read from the player on
 * every frame the canvas draws, rather than through Vue, which would redraw
 * the whole board to move it.
 */
const progressLine =
    (item: Item, video: HTMLVideoElement) => (context: Konva.Context) => {
        if (!video.duration || !video.currentTime) {
            return;
        }

        const thickness = Math.max(3, item.height * 0.012);

        context.setAttr('fillStyle', 'rgba(255,255,255,0.3)');
        context.fillRect(0, item.height - thickness, item.width, thickness);
        context.setAttr('fillStyle', '#6366f1');
        context.fillRect(
            0,
            item.height - thickness,
            (item.width * video.currentTime) / video.duration,
            thickness,
        );
    };

const playRadius = (item: Item) =>
    Math.max(12, Math.min(36, Math.min(item.width, item.height) / 5));

/** The triangle on the play button, pointing right, centred on its middle. */
const playTriangle = (item: Item) => {
    const radius = playRadius(item) * 0.45;

    return [-radius * 0.7, -radius, -radius * 0.7, radius, radius * 1.05, 0];
};
</script>

<template>
    <Rect
        :config="{
            width: item.width,
            height: item.height,
            fill: item.fill,
            cornerRadius: 4,
        }"
    />
    <KonvaImage
        :config="{
            image: video,
            visible: !!video && !(image && !playing),
            ...(video ? fitted(item, video) : {}),
        }"
    />
    <!-- Its poster, in place of a still while it isn't playing -->
    <KonvaImage
        :config="{
            image: image,
            visible: !!image && !playing,
            ...(image ? pictured({ ...item, fit: 'contain' }, image) : {}),
            listening: false,
        }"
    />
    <Shape
        :config="{
            sceneFunc: video ? progressLine(item, video) : () => {},
            visible: !!video,
            listening: false,
        }"
    />
    <Circle
        :config="{
            name: VIDEO_PLAY,
            x: item.width / 2,
            y: item.height / 2,
            radius: playRadius(item),
            fill: 'rgba(15,23,42,0.6)',
            stroke: '#ffffff',
            strokeWidth: 2,
            visible: !playing,
        }"
    />
    <Line
        :config="{
            x: item.width / 2,
            y: item.height / 2,
            points: playTriangle(item),
            closed: true,
            fill: '#ffffff',
            listening: false,
            visible: !playing,
        }"
    />
    <Rect
        :config="{
            width: item.width,
            height: item.height,
            stroke: '#6366f1',
            strokeWidth: 2,
            cornerRadius: 4,
            listening: false,
            visible: selected,
        }"
    />
</template>
