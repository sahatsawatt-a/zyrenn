<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const open = defineModel<boolean>('open', { required: true });

defineProps<{
    name: string;
    description: string;
    busy?: boolean;
}>();

const emit = defineEmits<{
    (e: 'confirm'): void;
}>();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Delete “{{ name }}”?</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="secondary" @click="open = false"
                    >Cancel</Button
                >
                <Button
                    variant="destructive"
                    :disabled="busy"
                    @click="emit('confirm')"
                    >Delete</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
