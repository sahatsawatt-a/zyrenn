<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { leave as leaveRoom } from '@/routes/chats';

// Asked in the app's own way before leaving a group: one can join it again
// from the project

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{ room: { ref_id: string; title: string } }>();

function leave() {
    router.post(leaveRoom(props.room.ref_id).url);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Leave “{{ room.title }}”?</DialogTitle>
                <DialogDescription>
                    You can join it again from the project.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="secondary" @click="open = false"
                    >Stay</Button
                >
                <Button type="button" @click="leave">Leave</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
