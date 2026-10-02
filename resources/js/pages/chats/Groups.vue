<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Plus, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import PeoplePicker from '@/components/chat/PeoplePicker.vue';
import type { Person } from '@/components/chat/PeoplePicker.vue';
import RoomList from '@/components/chat/RoomList.vue';
import EmptyState from '@/components/folders/EmptyState.vue';
import PageHeader from '@/components/folders/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { join } from '@/routes/chats';
import { store as startGroup } from '@/routes/projects/chat';

type Group = {
    ref_id: string;
    title: string;
    members: number;
    joined: boolean;
    unread: number;
    updated_at: string;
};

const props = defineProps<{
    groups: Group[];
    // The others in the project: who a new group can take in
    people: Person[];
}>();

const page = usePage();
const project = computed(() => page.props.project!);

const rooms = computed(() =>
    props.groups.map((group) => ({
        ...group,
        open: group.joined,
        detail: `${group.members} ${group.members === 1 ? 'person' : 'people'}${group.joined ? '' : ' · not joined'}`,
    })),
);

const starting = ref(false);
const title = ref('');
const chosen = ref<number[]>([]);
const errors = ref<Record<string, string>>({});

function open() {
    title.value = '';
    chosen.value = [];
    errors.value = {};
    starting.value = true;
}

function start() {
    router.post(
        startGroup(project.value.ref_id).url,
        { title: title.value, people: chosen.value },
        { onError: (problems) => (errors.value = problems) },
    );
}

const joinGroup = (ref_id: string) => router.post(join(ref_id).url);
</script>

<template>
    <Head :title="`Chat · ${project.name}`" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-6">
        <PageHeader
            :icon="Users"
            title="Groups"
            :description="`Conversations in ${project.name}. Anyone in the project can start one, and join any of them.`"
        >
            <Button data-new-group @click="open"><Plus /> New group</Button>
        </PageHeader>

        <RoomList v-if="rooms.length" :icon="Users" :rooms="rooms">
            <template #action="{ room }">
                <Button
                    v-if="!room.open"
                    size="sm"
                    variant="outline"
                    data-join
                    @click="joinGroup(room.ref_id)"
                >
                    Join
                </Button>
            </template>
        </RoomList>
        <EmptyState v-else :icon="Users" title="No groups yet">
            <p class="text-muted-foreground text-sm">
                Start one for a topic, and choose who is in it.
            </p>
        </EmptyState>

        <Dialog v-model:open="starting">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>New group</DialogTitle>
                    <DialogDescription>
                        Choose who is in it from {{ project.name }}. Anyone else
                        in the project can join it later.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="start">
                    <div class="grid gap-2">
                        <Label for="group-title">Name</Label>
                        <Input
                            id="group-title"
                            v-model="title"
                            required
                            maxlength="80"
                            autocomplete="off"
                            placeholder="e.g. Launch"
                        />
                        <InputError :message="errors.title" />
                    </div>

                    <div class="grid gap-2">
                        <Label>
                            People
                            <span class="text-muted-foreground font-normal">
                                ({{ chosen.length }} chosen, and you)
                            </span>
                        </Label>
                        <PeoplePicker
                            v-model:selected="chosen"
                            :people="people"
                            multiple
                        />
                        <InputError
                            :message="errors['people.0'] ?? errors.people"
                        />
                    </div>

                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            @click="starting = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="!title.trim()"
                            data-start-group
                        >
                            Start group
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
