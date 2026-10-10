<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LayoutTemplate } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import PickOne from '@/features/workflow/components/editor/PickOne.vue';
import { owned } from '@/lib/projects';
import { store as projectStore } from '@/routes/projects/workflows';
import { store as ownStore } from '@/routes/workflows';

// A workflow made from a template, already joined up: choose one, then the
// connections it needs, each from one's own.

type Template = {
    key: string;
    title: string;
    description: string;
    // service => what it is called here ("Bot", "AI connection")
    needs: Record<string, string>;
};

type OwnConnection = {
    ref_id: string;
    name: string;
    service: string;
    ai: boolean;
};

const props = defineProps<{
    templates: Template[];
    connections: OwnConnection[];
    folder: string | null;
}>();

const open = ref(false);
const chosen = ref<Template | null>(null);
const picked = ref<Record<string, string | null>>({});
const errors = ref<Record<string, string>>({});
const making = ref(false);

const store = owned(ownStore, projectStore);

const choose = (template: Template) => {
    chosen.value = template;
    errors.value = {};
    // One of a kind is chosen already
    picked.value = Object.fromEntries(
        Object.keys(template.needs).map((service) => {
            const offered = offeredFor(service);

            return [service, offered.length === 1 ? offered[0].value : null];
        }),
    );
};

const offeredFor = (service: string) =>
    props.connections
        .filter((c) => (service === 'ai' ? c.ai : c.service === service))
        .map((c) => ({ value: c.ref_id, label: c.name }));

const ready = computed(
    () =>
        chosen.value !== null &&
        Object.keys(chosen.value.needs).every((s) => picked.value[s]),
);

function make() {
    if (!chosen.value) {
        return;
    }

    router.post(
        store().url,
        {
            template: chosen.value.key,
            connections: picked.value,
            folder: props.folder,
        },
        {
            onStart: () => (making.value = true),
            onFinish: () => (making.value = false),
            onError: (problems) => (errors.value = problems),
        },
    );
}
</script>

<template>
    <Button variant="outline" data-from-template @click="open = true">
        <LayoutTemplate />
        From a template
    </Button>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Start from a template</DialogTitle>
                <DialogDescription>
                    Already joined up; change anything after.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <button
                    v-for="template in templates"
                    :key="template.key"
                    type="button"
                    class="rounded-lg border p-3 text-left transition-colors"
                    :class="
                        chosen?.key === template.key
                            ? 'border-primary bg-primary/5'
                            : 'hover:bg-muted/50'
                    "
                    :data-template="template.key"
                    @click="choose(template)"
                >
                    <span class="block text-sm font-medium">{{
                        template.title
                    }}</span>
                    <span class="text-muted-foreground block text-xs">{{
                        template.description
                    }}</span>
                </button>
            </div>

            <div v-if="chosen" class="grid gap-3 border-t pt-3">
                <div
                    v-for="(label, service) in chosen.needs"
                    :key="service"
                    class="grid gap-1.5"
                >
                    <Label>{{ label }}</Label>
                    <PickOne
                        :model-value="picked[service]"
                        :options="offeredFor(String(service))"
                        :placeholder="`Choose a ${label.toLowerCase()}…`"
                        @update:model-value="picked[service] = $event"
                    />
                    <p
                        v-if="!offeredFor(String(service)).length"
                        class="text-muted-foreground text-xs"
                    >
                        Add one in Connections first.
                    </p>
                    <InputError :message="errors[`connections.${service}`]" />
                </div>
            </div>

            <DialogFooter>
                <Button variant="ghost" @click="open = false">Cancel</Button>
                <Button
                    :disabled="!ready || making"
                    data-make-from-template
                    @click="make"
                >
                    Make it
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
