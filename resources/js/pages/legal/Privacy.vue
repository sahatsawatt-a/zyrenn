<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import LegalArticle from '@/components/legal/LegalArticle.vue';

defineProps<{
    contact: string | null;
    updated: string;
}>();
</script>

<template>
    <LegalArticle title="Privacy Policy" :updated="updated">
        <p>
            {{ $page.props.name }} is a place for notes, boards, tables, trips,
            files, chats and workflows. This page says what it keeps, why, who
            else sees it, and how to have it deleted.
        </p>

        <h2>What we keep</h2>
        <ul>
            <li>
                <strong>Your account:</strong> your name, your email address,
                and your password, kept only as a hash that cannot be read back.
            </li>
            <li>
                <strong>What you make:</strong> notes, boards, tables, trips,
                saved places, files in the Drive, chat messages and workflows,
                yours or a project's you are in.
            </li>
            <li>
                <strong>Your connections:</strong> the keys and sign-ins of the
                services you connect, such as an AI provider, Telegram, LINE,
                WhatsApp, Facebook, Instagram, Threads or LinkedIn. They are
                kept encrypted.
            </li>
            <li>
                <strong>What a connected service sends:</strong> the messages,
                comments and other events it sends to start your workflows, with
                the name or id of whoever wrote them, and what a workflow step
                reads from it.
            </li>
            <li>
                <strong>What a visit leaves:</strong> a session cookie that
                keeps you signed in, and the address and browser it came from.
            </li>
        </ul>

        <h2>What we use it for</h2>
        <p>
            To run {{ $page.props.name }} for you: to show you what you made, to
            share it with the people you share it with, and to do what your
            workflows say. We do not sell it, show adverts with it, or train AI
            models on it.
        </p>

        <h2>Who else sees it</h2>
        <ul>
            <li>
                <strong>People you share with:</strong> everyone in a project
                sees what belongs to that project.
            </li>
            <li>
                <strong>Services you connect:</strong> when a chat or a workflow
                step uses a connection, what it sends goes to that service: the
                words of a prompt to your AI provider, a message to Telegram, a
                post to Instagram. Each handles it under its own policy.
            </li>
            <li>
                <strong>Maps:</strong> a place you search for is sent to the map
                services that answer the search.
            </li>
            <li>
                <strong>Those who host the service</strong> for us, and anyone
                the law requires us to answer.
            </li>
        </ul>

        <h2>Facebook, Instagram, Threads and WhatsApp</h2>
        <p>
            When you sign a connection in to one of Meta's services, we receive
            a sign-in token and the name and id of the Page, account or profile.
            With the permissions you grant, your workflows can post, read posts
            and comments, and read and answer messages, as that account. We use
            this only to do what your workflows say. We do not use it for
            anything else, and share it with no one.
        </p>
        <p>
            Removing {{ $page.props.name }} from your account on Facebook,
            Instagram or Threads signs the connection out here. To have it
            deleted, see <Link href="/data-deletion">Delete your data</Link>.
        </p>

        <h2>How long we keep it</h2>
        <ul>
            <li>What you make, and your connections: until you delete them.</li>
            <li>
                Workflow runs: about 30 days after they end. What a connected
                service sent in: about 14 days.
            </li>
            <li>The record of who changed what: about a year.</li>
        </ul>

        <h2>Your choices</h2>
        <ul>
            <li>
                Change or delete anything you made, and remove any connection,
                at any time.
            </li>
            <li>
                Delete your account, and what is yours with it, in Settings.
            </li>
            <li v-if="contact">
                Write to <a :href="`mailto:${contact}`">{{ contact }}</a> to ask
                what we hold of you, or to have it corrected or deleted.
            </li>
            <li v-else>
                Ask what we hold of you, or ask for it to be corrected or
                deleted.
            </li>
        </ul>

        <h2>Children</h2>
        <p>
            {{ $page.props.name }} is not meant for children under 13, and we do
            not knowingly keep anything of theirs.
        </p>

        <h2>Changes</h2>
        <p>When this page changes, the date at the top changes with it.</p>

        <template v-if="contact">
            <h2>Contact</h2>
            <p>
                Questions about this page or your data:
                <a :href="`mailto:${contact}`">{{ contact }}</a
                >.
            </p>
        </template>
    </LegalArticle>
</template>
