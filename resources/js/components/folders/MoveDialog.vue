<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type FolderPath = { ref_id: string; path: string };

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    name: string;
    folders: FolderPath[];
    // Where it is now (null = top level)
    current: string | null;
    // Label for the top level, e.g. "Drive" or "Notes"
    rootLabel: string;
    // When moving a folder: it can't go into itself or anything inside it
    movingFolder?: string;
    busy?: boolean;
}>();

const emit = defineEmits<{
    (e: 'submit', destination: string | null): void;
}>();

// Reka's Select can't hold null or ''
const ROOT = '__root';
const destination = ref(ROOT);

watch(open, (isOpen) => {
    if (isOpen) {
        destination.value = props.current ?? ROOT;
    }
});

const options = computed(() => {
    const self = props.folders.find(
        (folder) => folder.ref_id === props.movingFolder,
    );

    if (!self) {
        return props.folders;
    }

    return props.folders.filter(
        (folder) =>
            folder.ref_id !== self.ref_id &&
            !folder.path.startsWith(`${self.path} / `),
    );
});

const submit = () =>
    emit('submit', destination.value === ROOT ? null : destination.value);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Move “{{ name }}”</DialogTitle>
                    <DialogDescription
                        >Choose where it should go.</DialogDescription
                    >
                </DialogHeader>
                <Select v-model="destination">
                    <SelectTrigger class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ROOT"
                            >{{ rootLabel }} (top level)</SelectItem
                        >
                        <SelectItem
                            v-for="option in options"
                            :key="option.ref_id"
                            :value="option.ref_id"
                        >
                            {{ option.path }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="busy">Move</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
