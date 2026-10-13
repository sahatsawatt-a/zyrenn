<script setup lang="ts">
import { CircleCheck } from '@lucide/vue';
import LegalArticle from '@/components/legal/LegalArticle.vue';

defineProps<{
    contact: string | null;
    updated: string;
    // A deletion asked for through Facebook, Instagram or Threads, looked up by its receipt
    done: { receipt: string; at: string; removed: number } | null;
}>();

const when = (at: string) =>
    new Date(at).toLocaleString([], { dateStyle: 'long', timeStyle: 'short' });
</script>

<template>
    <LegalArticle title="Delete your data" :updated="updated">
        <div
            v-if="done"
            class="bg-card border-border/70 flex gap-3 rounded-xl border p-4"
            data-deletion-done
        >
            <CircleCheck
                class="text-primary mt-0.5 size-5 shrink-0"
                aria-hidden="true"
            />
            <div class="min-w-0">
                <div class="text-foreground font-medium">
                    Your deletion request is done
                </div>
                <div class="text-muted-foreground mt-1 text-sm">
                    Carried out on {{ when(done.at) }}.
                    <template v-if="done.removed">
                        {{ done.removed }}
                        {{
                            done.removed === 1 ? 'sign-in was' : 'sign-ins were'
                        }}
                        removed, with what had come in through
                        {{ done.removed === 1 ? 'it' : 'them' }}.
                    </template>
                    <template v-else>
                        Nothing of this account was held any longer.
                    </template>
                </div>
                <div class="text-muted-foreground mt-1 text-xs break-all">
                    Request {{ done.receipt }}
                </div>
            </div>
        </div>

        <p>
            You can have what {{ $page.props.name }} holds of you deleted in any
            of these ways.
        </p>

        <h2>Delete your whole account</h2>
        <ol>
            <li>Sign in to {{ $page.props.name }}.</li>
            <li>Open Settings, then Profile.</li>
            <li>Choose Delete account, and confirm with your password.</li>
        </ol>
        <p>
            Your account goes at once, with what is yours in it: your notes,
            boards, tables, trips, files, workflows and connections. What
            belongs to a project you were in stays with that project.
        </p>

        <h2>Delete one connected account</h2>
        <p>
            To take back a Facebook Page, an Instagram account, a Threads
            profile or any other service you connected:
        </p>
        <ul>
            <li>
                <strong>Here:</strong> open Settings, then Connections, and
                remove the connection. Its sign-in and keys are deleted with it.
            </li>
            <li>
                <strong>On Facebook, Instagram or Threads:</strong> in that
                account's settings, find the apps and websites you have
                connected, remove {{ $page.props.name }}, and ask for your data
                to be deleted. We are told at once: the sign-in is removed here,
                with what came in through it, and you are given a link to this
                page that confirms it.
            </li>
        </ul>

        <h2>Ask us</h2>
        <p v-if="contact">
            Write to
            <a :href="`mailto:${contact}`">{{ contact }}</a> from the email
            address of your account, saying what you want deleted. We answer
            within 30 days.
        </p>
        <p v-else>
            Write to whoever runs this {{ $page.props.name }} from the email
            address of your account, saying what you want deleted.
        </p>

        <h2>What stays a little longer</h2>
        <p>
            Backups made before a deletion are overwritten as they age out. A
            record that something was deleted, without what it was, is kept for
            about a year.
        </p>
    </LegalArticle>
</template>
