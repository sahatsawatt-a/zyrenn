<script setup lang="ts">
import { Download, FileDown, HardDrive, Image } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// A board as a PDF, a frame to a page, or as a picture of all of it
defineProps<{ busy: boolean }>();

defineEmits<{ export: ['pdf' | 'png', 'download' | 'drive'] }>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="sm"
                :disabled="busy"
                data-test="board-export"
            >
                <FileDown />
                Export
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuItem
                data-test="board-export-pdf"
                @select="$emit('export', 'pdf', 'download')"
            >
                <Download />
                Download PDF
            </DropdownMenuItem>
            <DropdownMenuItem
                data-test="board-export-pdf-drive"
                @select="$emit('export', 'pdf', 'drive')"
            >
                <HardDrive />
                Save PDF to Drive
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                data-test="board-export-png"
                @select="$emit('export', 'png', 'download')"
            >
                <Image />
                Download PNG
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
