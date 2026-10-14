<script setup lang="ts">
import { computed } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Item } from '@/features/boards/composables/items';
import {
    BOARD_TEMPLATES,
    itemsOfTemplate,
} from '@/features/boards/composables/templates';
import type { BoardTemplate } from '@/features/boards/composables/templates';
import BoardView from './BoardView.vue';

// Diagrams to start from: each shown as the board would draw it, and put on
// the board as ordinary shapes and connectors, there to be reworded, moved
// and added to.
const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ insert: [Item[]] }>();

// Drawn once for the pictures; what goes on the board is made afresh, so a
// template put on twice is two diagrams and not one diagram twice
const previews = computed(() =>
    open.value
        ? BOARD_TEMPLATES.map((template) => ({
              template,
              items: itemsOfTemplate(template),
          }))
        : [],
);

const choose = (template: BoardTemplate) => {
    emit('insert', itemsOfTemplate(template));
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-3xl" data-test="template-dialog">
            <DialogHeader>
                <DialogTitle>Start from a template</DialogTitle>
                <DialogDescription>
                    It goes on the board as shapes and connectors of your own:
                    change the words, move things, add to it.
                </DialogDescription>
            </DialogHeader>

            <div class="template-grid">
                <button
                    v-for="{ template, items } in previews"
                    :key="template.id"
                    type="button"
                    class="template-card"
                    :title="template.about"
                    :data-test="`template-${template.id}`"
                    @click="choose(template)"
                >
                    <!-- A picture of it, which takes no clicks of its own -->
                    <span class="template-picture">
                        <BoardView :items="items" :padding="10" plain />
                    </span>
                    <span class="template-name">{{ template.label }}</span>
                    <span class="template-about">{{ template.about }}</span>
                </button>
            </div>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.template-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(12.5rem, 1fr));
    gap: 0.75rem;
    max-height: min(32rem, 65svh);
    overflow-y: auto;
    padding: 0.125rem;
}

.template-card {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.5rem;
    text-align: left;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    cursor: pointer;
}
.template-card:hover,
.template-card:focus-visible {
    border-color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 6%, transparent);
}

.template-picture {
    display: block;
    height: 7.5rem;
    overflow: hidden;
    background-color: #ffffff;
    border-radius: var(--radius-md);
    pointer-events: none;
}

.template-name {
    font-size: 0.8125rem;
    font-weight: 600;
}

.template-about {
    font-size: 0.6875rem;
    line-height: 1.35;
    color: var(--muted-foreground);
}
</style>
