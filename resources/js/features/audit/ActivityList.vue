<script setup lang="ts">
// What was done to something, the latest first: who published a workflow,
// switched it on, ran it by hand; who added or changed a connection. Shared
// by every page that keeps such a record (App\Support\Audit\Audit).

export type Activity = {
    id: number;
    action: string;
    subject: { type: string; ref: string; name: string | null };
    details: Record<string, unknown>;
    by: string | null;
    at: string;
};

defineProps<{
    activity: Activity[];
    // Name what each is about: on a list of many things, not on one's own page
    named?: boolean;
    empty?: string;
}>();

const SAID: Record<string, string> = {
    'workflow.created': 'made it',
    'workflow.published': 'published',
    'workflow.enabled': 'switched it on',
    'workflow.disabled': 'switched it off',
    'workflow.deleted': 'deleted it',
    'workflow.run': 'ran it by hand',
    'workflow.stopped': 'stopped a run',
    'workflow.approved': 'approved a step',
    'workflow.rejected': 'rejected a step',
    'connection.added': 'added',
    'connection.changed': 'changed',
    'connection.removed': 'removed',
    'connection.signed_out': 'signed out',
};

function said(item: Activity): string {
    const what = SAID[item.action] ?? item.action;

    if (item.action === 'workflow.published' && item.details.version) {
        return `published version ${item.details.version}`;
    }

    if (
        item.action === 'connection.changed' &&
        Array.isArray(item.details.fields)
    ) {
        return `changed ${(item.details.fields as string[]).join(', ').replaceAll('_', ' ')}`;
    }

    // Signed out by word from its service, where whoever signed it in took that back
    if (item.action === 'connection.signed_out') {
        return item.details.why === 'deletion'
            ? `signed out: its account asked ${item.details.by ?? 'the service'} for its data to be deleted`
            : `signed out: the app was removed from its account on ${item.details.by ?? 'the service'}`;
    }

    return what;
}

const when = (at: string) =>
    new Date(at).toLocaleString([], {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
</script>

<template>
    <p v-if="!activity.length" class="text-muted-foreground text-sm">
        {{ empty ?? 'Nothing yet.' }}
    </p>
    <ol v-else class="space-y-1.5" data-activity>
        <li
            v-for="item in activity"
            :key="item.id"
            class="flex flex-wrap items-baseline gap-x-1.5 text-sm"
            :data-activity-action="item.action"
        >
            <span class="font-medium">{{ item.by ?? 'The app' }}</span>
            <span>{{ said(item) }}</span>
            <span v-if="named && item.subject.name" class="font-medium"
                >“{{ item.subject.name }}”</span
            >
            <span class="text-muted-foreground ml-auto text-xs tabular-nums">{{
                when(item.at)
            }}</span>
        </li>
    </ol>
</template>
