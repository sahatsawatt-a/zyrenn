<script setup lang="ts">
import { computed } from 'vue';
import { iconNamed } from '@/lib/icons';

// An icon from the app's set, drawn from the name it was stored by. Class and
// style pass through to the svg, as with any Lucide icon.

const props = defineProps<{ name?: string | null }>();

const icon = computed(() => iconNamed(props.name).icon);
</script>

<template>
    <component :is="icon" />
</template>
