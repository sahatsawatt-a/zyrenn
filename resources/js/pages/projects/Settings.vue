<script setup lang="ts">
import { Form, Head, router, setLayoutProps } from '@inertiajs/vue3';
import { FolderKanban } from '@lucide/vue';
import { ref, watchEffect } from 'vue';
import DeleteDialog from '@/components/folders/DeleteDialog.vue';
import PageHeader from '@/components/folders/PageHeader.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getInitials } from '@/composables/useInitials';
import type { ProjectRole } from '@/lib/projects';
import { destroy, edit, update } from '@/routes/projects';

const props = defineProps<{
    settings: {
        ref_id: string;
        name: string;
        created_at: string;
        members: { name: string; email: string; role: ProjectRole }[];
        can: { update: boolean; delete: boolean };
    };
}>();

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: props.settings.name, href: edit(props.settings.ref_id) },
            { title: 'Settings', href: edit(props.settings.ref_id) },
        ],
    });
});

const deleteOpen = ref(false);
const deleting = ref(false);

const remove = () =>
    router.delete(destroy.url(props.settings.ref_id), {
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
    });
</script>

<template>
    <Head :title="`Settings · ${settings.name}`" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-10 p-4 md:p-6">
        <PageHeader
            :icon="FolderKanban"
            :title="settings.name"
            description="A shared space. What is in it belongs to the project, not to whoever made it."
        />

        <section class="flex flex-col gap-6">
            <Heading
                variant="small"
                title="Name"
                :description="
                    settings.can.update
                        ? 'What everyone in the project sees it called.'
                        : 'Only the project’s owners can rename it.'
                "
            />

            <Form
                v-bind="update.form(settings.ref_id)"
                class="flex flex-col gap-4 sm:flex-row sm:items-start"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing, recentlySuccessful }"
            >
                <div class="grid flex-1 gap-2">
                    <Label for="name" class="sr-only">Name</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="settings.name"
                        :disabled="!settings.can.update"
                        maxlength="255"
                        required
                    />
                    <InputError :message="errors.name" />
                </div>
                <div v-if="settings.can.update" class="flex items-center gap-3">
                    <Button :disabled="processing">Save</Button>
                    <span
                        v-show="recentlySuccessful"
                        class="text-muted-foreground text-sm"
                        >Saved.</span
                    >
                </div>
            </Form>
        </section>

        <section class="flex flex-col gap-4">
            <Heading
                variant="small"
                title="Members"
                description="Owners run the project, editors add and change what is in it, viewers look."
            />

            <ul class="divide-border bg-card divide-y rounded-xl border">
                <li
                    v-for="member in settings.members"
                    :key="member.email"
                    class="flex items-center gap-3 px-4 py-3"
                >
                    <Avatar class="size-8">
                        <AvatarFallback class="text-xs">{{
                            getInitials(member.name)
                        }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ member.name }}
                        </p>
                        <p class="text-muted-foreground truncate text-xs">
                            {{ member.email }}
                        </p>
                    </div>
                    <Badge variant="secondary" class="capitalize">{{
                        member.role
                    }}</Badge>
                </li>
            </ul>
        </section>

        <section v-if="settings.can.delete" class="flex flex-col gap-4">
            <Heading
                variant="small"
                title="Delete project"
                description="Deletes every note, board, table and file in it, for everyone."
            />
            <div
                class="border-destructive/30 bg-destructive/5 flex flex-wrap items-center justify-between gap-4 rounded-xl border p-4"
            >
                <p class="text-sm">This can’t be undone.</p>
                <Button
                    variant="destructive"
                    data-test="delete-project"
                    @click="deleteOpen = true"
                    >Delete project</Button
                >
            </div>
        </section>
    </div>

    <DeleteDialog
        v-model:open="deleteOpen"
        :name="settings.name"
        description="The project and every note, board, table and file in it will be deleted for good, for everyone in it."
        :busy="deleting"
        @confirm="remove"
    />
</template>
