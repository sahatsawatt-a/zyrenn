<template>
    <node-view-wrapper
        class="image-block"
        :class="[`size-${node.attrs.size}`, { 'is-selected': selected }]"
        data-drag-handle
    >
        <div class="image-frame">
            <!-- Played right here; the toolbar's expand opens it full size -->
            <video
                v-if="isVideo && !failed"
                ref="player"
                :src="node.attrs.src"
                :title="node.attrs.title ?? undefined"
                controls
                preload="metadata"
                playsinline
                data-test="note-video"
                @error="failed = true"
                @loadedmetadata="failed = false"
            />
            <img
                v-else-if="!failed"
                :src="node.attrs.src"
                :alt="node.attrs.alt ?? ''"
                :title="node.attrs.title ?? undefined"
                draggable="false"
                @dblclick="expand"
                @error="failed = true"
                @load="failed = false"
            />
            <div v-else class="image-missing" contenteditable="false">
                {{ isVideo ? 'Video' : 'Image' }} couldn’t be loaded
                <span>{{ node.attrs.src }}</span>
            </div>

            <!-- A PDF can't play a video, so it says what was there -->
            <p v-if="isVideo" class="video-print" contenteditable="false">
                ▶ Video: {{ node.attrs.title || node.attrs.src }}
            </p>

            <!-- Hover toolbar -->
            <div v-if="!failed" class="image-toolbar" contenteditable="false">
                <button
                    v-for="option in sizes"
                    :key="option.value"
                    type="button"
                    class="image-btn"
                    :class="{ active: node.attrs.size === option.value }"
                    :title="`${option.title} width`"
                    @click="updateAttributes({ size: option.value })"
                >
                    {{ option.label }}
                </button>
                <span class="image-divider"></span>
                <button
                    type="button"
                    class="image-btn"
                    title="View full size"
                    @click="expand"
                >
                    <Maximize2 :size="14" />
                </button>
            </div>
        </div>
    </node-view-wrapper>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3';
import { Maximize2 } from '@lucide/vue';
import { useMediaViewer } from '../../composables/useMediaViewer';
import type { ViewerItem } from '../../composables/useMediaViewer';
import type { ImageSize } from '../../editor-nodes/ImageNode';

const props = defineProps(nodeViewProps);

// The one view for both picture and video blocks: same widths, same viewer
const isVideo = computed(() => props.node.type.name === 'video');
const player = ref<HTMLVideoElement | null>(null);

const failed = ref(false);
watch(
    () => props.node.attrs.src,
    () => (failed.value = false),
);

const sizes: { value: ImageSize; label: string; title: string }[] = [
    { value: 'small', label: 'S', title: 'Small' },
    { value: 'medium', label: 'M', title: 'Medium' },
    { value: 'full', label: 'L', title: 'Full' },
];

const viewer = useMediaViewer();

// Open the viewer on this one, with the note's other pictures and videos a
// swipe away. A video playing here carries on there from the same moment.
const expand = () => {
    const items: ViewerItem[] = [];
    let start = 0;
    const self = props.getPos();

    props.editor.state.doc.descendants((node, pos) => {
        const mine = pos === self;

        if (mine) start = items.length;

        if (node.type.name === 'image') {
            items.push({
                type: 'image',
                src: node.attrs.src,
                alt: node.attrs.alt ?? undefined,
            });
        } else if (node.type.name === 'video') {
            items.push({
                type: 'video',
                src: node.attrs.src,
                title: node.attrs.title ?? undefined,
                start: mine ? player.value?.currentTime : undefined,
            });
        }
    });

    player.value?.pause();
    viewer.open(items, start);
};
</script>

<style scoped>
.image-block {
    margin: 1rem 0;
    display: flex;
    justify-content: center;
}

.image-frame {
    position: relative;
    max-width: 100%;
    border-radius: 0.5rem;
    line-height: 0;
}
.size-small .image-frame {
    width: 33%;
}
.size-medium .image-frame {
    width: 66%;
}
.size-full .image-frame {
    width: 100%;
}

.image-frame img,
.image-frame video {
    display: block;
    width: 100%;
    height: auto;
    border-radius: 0.5rem;
}
.image-frame img {
    cursor: zoom-in;
}
.image-frame video {
    background: #000;
    /* 16:9 until it knows its own shape */
    aspect-ratio: auto 16 / 9;
}

.video-print {
    display: none;
}
@media print {
    .image-frame video {
        display: none;
    }
    .video-print {
        display: block;
        padding: 0.75rem 1rem;
        border: 1px solid var(--border);
        border-radius: 0.5rem;
        font-size: 13px;
        line-height: 1.4;
    }
}

.is-selected .image-frame {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

.image-missing {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 1.5rem;
    border: 1px dashed var(--border);
    border-radius: 0.5rem;
    font-size: 13px;
    line-height: 1.4;
    color: var(--muted-foreground);
    text-align: center;
}
.image-missing span {
    font-size: 11px;
    word-break: break-all;
    opacity: 0.7;
}

.image-toolbar {
    position: absolute;
    top: 8px;
    right: 8px;
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 3px;
    border-radius: 8px;
    background: color-mix(in oklab, var(--popover) 92%, transparent);
    border: 1px solid var(--border);
    box-shadow: 0 2px 8px rgb(0 0 0 / 0.12);
    line-height: 1;
    opacity: 0;
    transition: opacity 120ms ease;
    user-select: none;
}
.image-frame:hover .image-toolbar,
.is-selected .image-toolbar {
    opacity: 1;
}

.image-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 26px;
    height: 24px;
    padding: 0 6px;
    border-radius: 5px;
    font-size: 12px;
    font-weight: 600;
    color: var(--muted-foreground);
    cursor: pointer;
}
.image-btn:hover {
    background: var(--accent);
    color: var(--foreground);
}
.image-btn.active {
    background: var(--accent);
    color: var(--foreground);
}

.image-divider {
    width: 1px;
    height: 16px;
    margin: 0 2px;
    background: var(--border);
}
</style>
