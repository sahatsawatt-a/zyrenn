<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as connectionsIndex, models } from '@/routes/connections';
import { update } from '@/routes/chats';
import type { Connection, Room } from '../types';
import ModelPicker from './ModelPicker.vue';

// The agent of one's own room: a connection, a model, and instructions.
// Without a connection the room only keeps what is written in it.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    room: Pick<Room, 'ref_id' | 'connection' | 'model' | 'system_prompt'>;
    connections: Connection[];
}>();

const connection = ref(props.room.connection ?? '');
const model = ref(props.room.model ?? '');
const prompt = ref(props.room.system_prompt ?? '');
const offered = ref<string[]>([]);
const offeredFree = ref<string[] | null>(null);
const offeredFailure = ref<string | null>(null);
const loadingModels = ref(false);

// Reka's Select can't hold an empty value, so "no connection" has a name of its own
const NONE = '__none';
const chosenConnection = computed(() =>
    props.connections.find((c) => c.ref_id === connection.value),
);

// A model of the old connection means nothing to the new one, so a change starts the choice over
function chooseConnection(value: unknown) {
    connection.value = value === NONE ? '' : String(value);
    model.value = '';
    void loadModels();
}

async function loadModels() {
    offered.value = [];
    offeredFree.value = null;
    offeredFailure.value = null;

    if (!connection.value) {
        return;
    }

    loadingModels.value = true;

    try {
        const response = await fetch(models(connection.value).url, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json();

        if (response.ok) {
            offered.value = body.models;
            offeredFree.value = body.free;
        } else {
            offeredFailure.value = body.message;
        }
    } catch {
        offeredFailure.value = 'Could not ask for its models.';
    } finally {
        loadingModels.value = false;
    }
}

watch(open, (now) => now && void loadModels());

const usual = () =>
    props.connections.find((c) => c.ref_id === connection.value)
        ?.default_model ?? '';

function save() {
    router.patch(
        update(props.room.ref_id).url,
        {
            connection: connection.value || null,
            model: model.value || null,
            system_prompt: prompt.value || null,
        },
        {
            preserveScroll: true,
            only: ['room', 'connections'],
            onSuccess: () => (open.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Agent</DialogTitle>
                <DialogDescription>
                    Who answers in this chat, and how. Leave the connection
                    empty for a chat that only keeps what you write.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-2">
                    <Label for="chat-connection">Connection</Label>
                    <Select
                        :model-value="connection || NONE"
                        @update:model-value="chooseConnection"
                    >
                        <SelectTrigger
                            id="chat-connection"
                            class="w-full"
                            data-connection-select
                        >
                            <SelectValue>
                                <template v-if="chosenConnection">
                                    {{ chosenConnection.name }}
                                    <span
                                        class="text-muted-foreground text-xs"
                                        >{{ chosenConnection.label }}</span
                                    >
                                </template>
                                <template v-else
                                    >None: only keep what I write</template
                                >
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">
                                None: only keep what I write
                            </SelectItem>
                            <SelectItem
                                v-for="c in connections"
                                :key="c.ref_id"
                                :value="c.ref_id"
                                :data-connection-option="c.name"
                            >
                                {{ c.name }}
                                <span class="text-muted-foreground text-xs">{{
                                    c.label
                                }}</span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="!connections.length"
                        class="text-muted-foreground text-xs"
                    >
                        You have none yet.
                        <Link
                            :href="connectionsIndex()"
                            class="text-primary underline underline-offset-4"
                        >
                            Add one in settings.
                        </Link>
                    </p>
                </div>

                <div v-if="connection" class="grid gap-2">
                    <Label>Model</Label>
                    <ModelPicker
                        v-model="model"
                        :models="offered"
                        :free="offeredFree"
                        :loading="loadingModels"
                        :error="offeredFailure"
                        :usual="usual()"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="chat-prompt">Instructions</Label>
                    <textarea
                        id="chat-prompt"
                        v-model="prompt"
                        rows="4"
                        maxlength="10000"
                        class="border-input dark:bg-input/30 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                        placeholder="e.g. Answer briefly, in the language I write in."
                    />
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancel</Button
                    >
                    <Button type="submit">Save</Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
