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
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

// What is selected, kept as a shape of one's own: named, and on a project's
// board kept for everyone in the project if wanted. It is a copy -- changed
// later, it does not change the boards it is already on.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    // A small picture of what is kept
    preview: string | null;
    // The project the board is in, when its owners and editors may keep it
    // for everyone there
    project: string | null;
    saving: boolean;
}>();

const emit = defineEmits<{ save: [name: string, forProject: boolean] }>();

const name = ref('');
const forProject = ref(false);

watch(open, (now) => {
    if (now) {
        name.value = '';
        forProject.value = false;
    }
});

function save() {
    if (name.value.trim() && !props.saving) {
        emit('save', name.value.trim(), forProject.value);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-sm" data-test="save-shape-dialog">
            <DialogHeader>
                <DialogTitle>Save as my shape</DialogTitle>
                <DialogDescription>
                    It goes under My shapes in the shape library of every board.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-3" @submit.prevent="save">
                <img
                    v-if="preview"
                    :src="preview"
                    alt=""
                    class="bg-muted/40 mx-auto max-h-28 rounded-md border object-contain p-2"
                />
                <div class="grid gap-1.5">
                    <Label for="shape-name">Name</Label>
                    <Input
                        id="shape-name"
                        v-model="name"
                        maxlength="80"
                        placeholder="e.g. Our logo"
                        autofocus
                        data-test="save-shape-name"
                    />
                </div>
                <label
                    v-if="project"
                    class="flex items-center justify-between gap-3 text-sm"
                >
                    <span>For everyone in {{ project }}</span>
                    <Switch
                        v-model="forProject"
                        data-test="save-shape-project"
                    />
                </label>

                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="!name.trim() || saving"
                        data-test="save-shape-confirm"
                    >
                        Save
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
