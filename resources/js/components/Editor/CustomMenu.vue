<template>
    <div class="notion-dropdown" :style="style">
        <div class="dropdown-header">BASIC BLOCKS</div>

        <div
            v-for="(item, index) in items"
            :key="index"
            class="dropdown-item"
            :class="{ 'is-active': index === selectedIndex }"
            @mousedown.prevent
            @click="$emit('select', index)"
        >
            <!-- Item Decorative Icon wrapper -->
            <div class="item-icon">{{ item.icon }}</div>

            <!-- Text Description Content block -->
            <div class="item-meta">
                <span class="item-title">{{ item.title }}</span>
                <span class="item-description">{{ item.description }}</span>
            </div>
        </div>

        <!-- Fallback indicator when filtering leaves array blank -->
        <div v-if="items.length === 0" class="dropdown-empty">
            No results found
        </div>
    </div>
</template>

<script setup lang="ts">
import type { CSSProperties } from 'vue';
import type { SlashCommandItem } from '../../composables/useSlashCommands';

// Strict compiler type verification guards
defineProps<{
    items: SlashCommandItem[];
    selectedIndex: number;
    style: CSSProperties;
}>();

defineEmits<{
    (e: 'select', index: number): void;
}>();
</script>

<style scoped>
/* 
  Custom Dropdown Grid system optimized for your Tailwind v4 Theme Tokens.
  Automatically responds to changes in light and dark mode colors!
*/
.notion-dropdown {
    background-color: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow:
        0 4px 12px rgba(0, 0, 0, 0.08),
        0 0 0 1px rgba(0, 0, 0, 0.04);
    padding: 6px;
    width: 280px;
    max-height: 320px;
    overflow-y: auto;
    z-index: 9999;
}

.dropdown-header {
    font-size: 11px;
    font-weight: 600;
    color: var(--muted-foreground);
    padding: 6px 8px;
    letter-spacing: 0.5px;
}

.dropdown-item {
    display: flex;
    align-items: center;
    padding: 6px 8px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    gap: 12px;
    transition: background-color 100ms ease;
}

/* Uses your custom --muted var tokens when selected via cursor/arrow navigations */
.dropdown-item.is-active,
.dropdown-item:hover {
    background-color: var(--muted);
}

.item-icon {
    font-size: 14px;
    font-weight: bold;
    width: 28px;
    height: 28px;
    background-color: var(--background);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border);
    color: var(--foreground);
    flex-shrink: 0;
}

.item-meta {
    display: flex;
    flex-direction: column;
}

.item-title {
    font-size: 14px;
    color: var(--foreground);
    font-weight: 500;
}

.item-description {
    font-size: 11px;
    color: var(--muted-foreground);
}

.dropdown-empty {
    padding: 12px;
    font-size: 13px;
    color: var(--muted-foreground);
    text-align: center;
}
</style>
