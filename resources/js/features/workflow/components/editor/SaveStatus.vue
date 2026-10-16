<script setup lang="ts">
import { CloudUpload, Loader2 } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { SaveStatus } from '@/features/workflow/types';

// Whether the draft is saved. Where room is short, only what went wrong is
// said in words.

const props = defineProps<{
    status: SaveStatus;
    // Why it was not saved, when the server said
    error: string | null;
    compact: boolean;
}>();

const emit = defineEmits<{ retry: [] }>();

const wrong = computed(() => ['error', 'conflict'].includes(props.status));

const text = computed(
    () =>
        ({
            saved: 'Saved',
            saving: 'Saving…',
            unsaved: 'Unsaved',
            error: props.error || 'Not saved',
            conflict: 'Someone else saved',
        })[props.status],
);
</script>

<template>
    <span
        class="text-muted-foreground flex items-center gap-1.5 text-xs"
        :class="{ 'text-destructive': wrong }"
        data-save-status
    >
        <Loader2 v-if="status === 'saving'" class="size-3.5 animate-spin" />
        <CloudUpload v-else class="size-3.5" />
        <span :class="{ 'sr-only': compact && !wrong }">{{ text }}</span>
        <Button
            v-if="status === 'error'"
            size="sm"
            variant="link"
            class="h-auto p-0 text-xs"
            @click="emit('retry')"
            >Try again</Button
        >
    </span>
</template>
