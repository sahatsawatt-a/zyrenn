<script setup lang="ts">
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    title: string;
    submitLabel: string;
    // Pre-filled when renaming
    initial?: string;
    busy?: boolean;
}>();

const emit = defineEmits<{
    (e: 'submit', name: string): void;
}>();

const name = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        name.value = props.initial ?? '';
    }
});

const submit = () => {
    const trimmed = name.value.trim();

    if (trimmed) {
        emit('submit', trimmed);
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription class="sr-only"
                        >Enter a name.</DialogDescription
                    >
                </DialogHeader>
                <Input
                    v-model="name"
                    placeholder="Name"
                    maxlength="255"
                    autofocus
                />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="busy || !name.trim()">{{
                        submitLabel
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
