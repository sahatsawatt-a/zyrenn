<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { Publishing } from '@/features/workflow/types';
import Tip from '@/components/common/Tip.vue';

// Which version is published, and whether the draft has changed since

defineProps<{ publishing: Publishing; compact: boolean }>();
</script>

<template>
    <Tip
        v-if="publishing.published"
        :text="
            publishing.unpublished_changes
                ? 'Changed since it was published'
                : 'Published'
        "
    >
        <Badge variant="secondary" class="font-normal" data-published>
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
    </Tip>
    <Badge v-else-if="!compact" variant="outline" class="font-normal"
        >Not published</Badge
    >
</template>
