<script setup lang="ts">
import { Form, Head, router, setLayoutProps } from '@inertiajs/vue3';
import { FolderKanban, LogOut, UserMinus } from '@lucide/vue';
import { computed, ref, watchEffect } from 'vue';
import { toast } from 'vue-sonner';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import Heading from '@/components/common/Heading.vue';
import InputError from '@/components/common/InputError.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
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
import { getInitials } from '@/composables/useInitials';
import type { ProjectRole } from '@/lib/projects';
import { destroy, edit, update } from '@/routes/projects';
import * as members from '@/routes/projects/members';

type Member = {
    // The membership's, for changing or ending it
    ref_id: string;
    name: string;
    email: string;
    role: ProjectRole;
    is_me: boolean;
};

const props = defineProps<{
    settings: {
        ref_id: string;
        name: string;
        created_at: string;
        members: Member[];
        can: { update: boolean; manage_members: boolean; delete: boolean };
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

const roles: { value: ProjectRole; label: string }[] = [
    { value: 'owner', label: 'Owner' },
    { value: 'editor', label: 'Editor' },
    { value: 'viewer', label: 'Viewer' },
];

const me = computed(() =>
    props.settings.members.find((member) => member.is_me),
);

const membership = (member: Member) => ({
    project: props.settings.ref_id,
    membership: member.ref_id,
});

const firstError = (errors: Record<string, string>) =>
    toast.error(Object.values(errors)[0] ?? 'Something went wrong.');

const changeRole = (member: Member, role: string) =>
    router.patch(
        members.update.url(membership(member)),
        { role },
        { preserveScroll: true, onError: firstError },
    );

const removing = ref<Member | null>(null);

const removeMember = () => {
    if (!removing.value) {
        return;
    }

    router.delete(members.destroy.url(membership(removing.value)), {
        preserveScroll: true,
        onSuccess: () => (removing.value = null),
        onError: (errors) => {
            removing.value = null;
            firstError(errors);
        },
    });
};

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

            <Form
                v-if="settings.can.manage_members"
                v-bind="members.store.form(settings.ref_id)"
                :options="{ preserveScroll: true }"
                reset-on-success
                class="flex flex-col gap-2"
                data-test="add-member"
                v-slot="{ errors, processing }"
            >
                <div class="flex flex-col gap-2 sm:flex-row">
                    <Label for="member-email" class="sr-only">Email</Label>
                    <Input
                        id="member-email"
                        name="email"
                        type="email"
                        placeholder="Their email address"
                        required
                        class="flex-1"
                    />
                    <Label for="member-role" class="sr-only">Role</Label>
                    <select
                        id="member-role"
                        name="role"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option
                            v-for="role in roles"
                            :key="role.value"
                            :value="role.value"
                            :selected="role.value === 'editor'"
                        >
                            {{ role.label }}
                        </option>
                    </select>
                    <Button :disabled="processing">Add</Button>
                </div>
                <InputError :message="errors.email ?? errors.role" />
                <p class="text-muted-foreground text-xs">
                    They need an account here already.
                </p>
            </Form>

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
                            <span
                                v-if="member.is_me"
                                class="text-muted-foreground font-normal"
                                >(you)</span
                            >
                        </p>
                        <p class="text-muted-foreground truncate text-xs">
                            {{ member.email }}
                        </p>
                    </div>
                    <template v-if="settings.can.manage_members">
                        <select
                            :value="member.role"
                            :aria-label="`${member.name}’s role`"
                            class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                            data-test="member-role"
                            @change="
                                changeRole(
                                    member,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="role in roles"
                                :key="role.value"
                                :value="role.value"
                            >
                                {{ role.label }}
                            </option>
                        </select>
                        <Button
                            v-if="!member.is_me"
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :title="`Remove ${member.name}`"
                            data-test="remove-member"
                            @click="removing = member"
                        >
                            <UserMinus />
                        </Button>
                    </template>
                    <Badge v-else variant="secondary" class="capitalize">{{
                        member.role
                    }}</Badge>
                </li>
            </ul>
        </section>

        <section v-if="me" class="flex flex-col gap-4">
            <Heading
                variant="small"
                title="Leave project"
                description="You lose access to everything in it. What you made stays with the project."
            />
            <div
                class="flex flex-wrap items-center justify-between gap-4 rounded-xl border p-4"
            >
                <p class="text-sm">
                    {{
                        me.role === 'owner'
                            ? 'If you are its only owner, make someone else an owner first.'
                            : 'Someone would have to add you again.'
                    }}
                </p>
                <Button
                    variant="outline"
                    data-test="leave-project"
                    @click="removing = me"
                >
                    <LogOut />
                    Leave project
                </Button>
            </div>
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

    <Dialog
        :open="removing !== null"
        @update:open="(open) => !open && (removing = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    removing?.is_me
                        ? `Leave “${settings.name}”?`
                        : `Remove ${removing?.name}?`
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        removing?.is_me
                            ? 'You won’t see anything in the project until someone adds you again.'
                            : 'They won’t see anything in the project any more. What they made stays.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="secondary" @click="removing = null"
                    >Cancel</Button
                >
                <Button
                    variant="destructive"
                    data-test="confirm-remove"
                    @click="removeMember"
                    >{{ removing?.is_me ? 'Leave' : 'Remove' }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <DeleteDialog
        v-model:open="deleteOpen"
        :name="settings.name"
        description="The project and every note, board, table and file in it will be deleted for good, for everyone in it."
        :busy="deleting"
        @confirm="remove"
    />
</template>
