<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { Publishing } from '@/features/workflow/types';

// Which version is published, and whether the draft has changed since

defineProps<{ publishing: Publishing; compact: boolean }>();
</script>

<template>
    <Badge
        v-if="publishing.published"
        variant="secondary"
        class="font-normal"
        :title="
            publishing.unpublished_changes
                ? 'Changed since it was published'
                : 'Published'
        "
        data-published
    >
        v{{ publishing.published.number }}
        <template v-if="!compact">
            {{
                publishing.unpublished_changes
                    ? '· changed since'
                    : '· published'
            }}
        </template>
        <template v-else-if="publishing.unpublished_changes"
            >· changed</template
        >
    </Badge>
    <Badge v-else-if="!compact" variant="outline" class="font-normal"
        >Not published</Badge
    >
</template>
