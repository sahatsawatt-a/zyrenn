import Konva from 'konva';
import type { Ref } from 'vue';
import { computed } from 'vue';
import { useMediaViewer } from '@/composables/useMediaViewer';
import type { Item, Tool } from './items';
import type { useBoard } from './useBoard';
import type { useCamera } from './useCamera';
import { VIDEO_PLAY } from './useVideos';
import type { useVideos } from './useVideos';

// What the canvas does with a video on it: a click on its play button plays
// it (any click, while presenting), a double-click watches it full size, and
// the one selected has its controls just under it. useVideos keeps the
// players themselves; this is how the pointer reaches them.

export function useVideoControls({
    board,
    videos,
    camera,
    tool,
    presenting,
    onItemClick,
    onItemDoubleClick,
}: {
    board: ReturnType<typeof useBoard>;
    videos: ReturnType<typeof useVideos>;
    camera: ReturnType<typeof useCamera>;
    tool: Ref<Tool>;
    presenting: Ref<boolean>;
    /** What any other click does: select. */
    onItemClick: (event: Konva.KonvaEventObject<MouseEvent>) => void;
    /** What any other double-click does: write on it. */
    onItemDoubleClick: (event: Konva.KonvaEventObject<MouseEvent>) => void;
}) {
    const viewer = useMediaViewer();

    /** The video item a click landed on, if it landed on one. */
    const videoAt = (event: Konva.KonvaEventObject<MouseEvent>) => {
        const id = event.target.id() || event.target.getParent()?.id();
        const item = id ? board.byId.value.get(id) : undefined;

        return item?.kind === 'video' ? item : undefined;
    };

    // A click on a video's play button plays it, and so does any click on one
    // while presenting. Clicking the selected video pauses it; otherwise a click
    // is the usual select.
    const onClick = (event: Konva.KonvaEventObject<MouseEvent>) => {
        const video = videoAt(event);

        if (video && tool.value === 'select') {
            const chosen =
                board.selection.value.length === 1 &&
                board.selection.value[0] === video.id;

            if (
                presenting.value ||
                event.target.name() === VIDEO_PLAY ||
                (chosen && videos.isPlaying(video.id))
            ) {
                videos.toggle(video.id);
            }
        }

        onItemClick(event);
    };

    /** Watch a video full size, from wherever it had got to. */
    const expandVideo = (item: Item) => {
        const element = videos.elementOf(item.id);

        videos.pause(item.id);
        viewer.open([
            {
                type: 'video',
                src: item.src,
                start: element?.currentTime,
            },
        ]);
    };

    // Double-clicking a video watches it full size; anything else is labelled
    const onDoubleClick = (event: Konva.KonvaEventObject<MouseEvent>) => {
        const video = videoAt(event);

        if (video) {
            expandVideo(video);

            return;
        }

        onItemDoubleClick(event);
    };

    /** The one selected video, and where its controls go: just under it. */
    const videoBar = computed(() => {
        const item = board.selected.value[0];

        if (
            presenting.value ||
            board.selected.value.length !== 1 ||
            item?.kind !== 'video'
        ) {
            return null;
        }

        const element = videos.elementOf(item.id);

        if (!element) {
            return null;
        }

        const scale = camera.scale.value;
        const { x, y } = camera.position.value;

        return {
            item,
            element,
            style: {
                left: `${item.x * scale + x}px`,
                top: `${(item.y + item.height) * scale + y + 10}px`,
                width: `${Math.max(300, item.width * scale)}px`,
            },
        };
    });

    return { onClick, onDoubleClick, expandVideo, videoBar };
}
