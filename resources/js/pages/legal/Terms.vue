<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import LegalArticle from '@/components/legal/LegalArticle.vue';

defineProps<{
    contact: string | null;
    updated: string;
}>();
</script>

<template>
    <LegalArticle title="Terms of Service" :updated="updated">
        <p>
            These are the terms on which {{ $page.props.name }} is used. By
            making an account or using it, you agree to them.
        </p>

        <h2>Your account</h2>
        <p>
            Keep your password to yourself, and tell us if someone else has used
            your account. You answer for what is done with it.
        </p>

        <h2>What you make is yours</h2>
        <p>
            Your notes, boards, tables, files and everything else you put in
            stay yours. You let us store them and show them to you and to the
            people you share them with, which is all we do with them. How we
            treat them is in the
            <Link href="/privacy">Privacy Policy</Link>.
        </p>

        <h2>Using it fairly</h2>
        <ul>
            <li>Do not use it to break the law or to harm anyone.</li>
            <li>
                Do not send spam, or messages people have not agreed to receive,
                through a workflow.
            </li>
            <li>
                Do not try to get at what belongs to someone else, or to
                overload or break the service.
            </li>
        </ul>

        <h2>Services you connect</h2>
        <p>
            When you connect another service, such as Facebook, Instagram,
            Threads, WhatsApp, Telegram, LINE, LinkedIn or an AI provider, you
            use it under that service's own terms, and you answer for what your
            workflows post and send through it. Connect only accounts you are
            allowed to act for.
        </p>

        <h2>No promises</h2>
        <p>
            {{ $page.props.name }} is offered as it is. We work to keep it
            running and your work safe, but we do not promise it will always be
            available or free of faults, and, as far as the law allows, we are
            not liable for what is lost when it is not.
        </p>

        <h2>Ending</h2>
        <p>
            You can stop at any time and delete your account in Settings. We may
            suspend an account that breaks these terms.
        </p>

        <h2>Changes</h2>
        <p>
            When these terms change, the date at the top changes with them.
            Using {{ $page.props.name }} after a change means you agree to it.
        </p>

        <template v-if="contact">
            <h2>Contact</h2>
            <p>
                Questions about these terms:
                <a :href="`mailto:${contact}`">{{ contact }}</a
                >.
            </p>
        </template>
    </LegalArticle>
</template>
