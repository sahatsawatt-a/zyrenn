<script setup lang="ts">
import { ClipboardCopy, Download, HardDrive } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { deliver } from '@/lib/exporting';
import type { Item } from '@/features/boards/composables/items';

// What a right-click on a frame offers, where it was clicked: the frame as a
// picture -- downloaded, copied, or kept in the Drive. Which frame was
// clicked is the canvas's to say; this only draws and hands it on.

const props = defineProps<{
    /** The frame drawn as a picture, by the server. */
    pictureOfFrame: (frame: Item) => Promise<File>;
}>();
/** The frame clicked and where, in the canvas's own pixels; null when shut. */
const menu = defineModel<{ frame: Item; x: number; y: number } | null>({
    required: true,
});

const open = computed({
    get: () => menu.value !== null,
    set: (now) => {
        if (!now) menu.value = null;
    },
});

// Copying a picture is a newer thing than downloading one
const canCopyPicture =
    typeof window !== 'undefined' &&
    'ClipboardItem' in window &&
    !!navigator.clipboard?.write;

const frameName = (frame: Item) => frame.text?.trim() || 'Frame';

async function saveFrame(to: 'download' | 'drive' | 'copy'): Promise<void> {
    const frame = menu.value?.frame;

    if (!frame) {
        return;
    }

    const loading = toast.loading(`Drawing “${frameName(frame)}”…`);

    try {
        if (to === 'copy') {
            // Handed the drawing still to come, so the copy keeps the click
            // that asked for it while the server draws
            await navigator.clipboard.write([
                new ClipboardItem({ 'image/png': props.pictureOfFrame(frame) }),
            ]);
            toast.success(`Copied “${frameName(frame)}” as a picture`);
        } else {
            await deliver(await props.pictureOfFrame(frame), to);
        }
    } catch (error) {
        toast.error((error as Error).message);
    } finally {
        toast.dismiss(loading);
    }
}
</script>

<template>
    <DropdownMenu v-model:open="open" :modal="false">
        <DropdownMenuTrigger as-child>
            <span
                class="pointer-events-none absolute size-0"
                :style="{
                    left: `${menu?.x ?? 0}px`,
                    top: `${menu?.y ?? 0}px`,
                }"
            />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-56" data-test="frame-menu">
            <DropdownMenuLabel class="truncate">
                {{ menu ? frameName(menu.frame) : '' }}
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                data-test="frame-download"
                @select="saveFrame('download')"
            >
                <Download />
                Download picture
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="canCopyPicture"
                data-test="frame-copy"
                @select="saveFrame('copy')"
            >
                <ClipboardCopy />
                Copy picture
            </DropdownMenuItem>
            <DropdownMenuItem
                data-test="frame-drive"
                @select="saveFrame('drive')"
            >
                <HardDrive />
                Save picture to Drive
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
