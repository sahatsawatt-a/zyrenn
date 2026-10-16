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

// Someone else saved the workflow after it was opened here: keep this copy
// over theirs, or take theirs. Closed any other way, this copy is kept.

defineProps<{ open: boolean; editedBy: string | null | undefined }>();

const emit = defineEmits<{ keep: []; take: [] }>();
</script>

<template>
    <Dialog :open="open" @update:open="$event || emit('keep')">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Someone else changed this workflow</DialogTitle>
                <DialogDescription>
                    {{ editedBy ?? 'Someone' }} saved it after you opened it.
                    Keep your copy over theirs, or take theirs and lose the
                    changes made here.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="outline" @click="emit('take')"
                    >Take theirs</Button
                >
                <Button @click="emit('keep')">Keep mine</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
