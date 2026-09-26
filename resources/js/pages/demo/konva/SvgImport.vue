<script setup lang="ts">
import { X } from '@lucide/vue';
import { ref, useTemplateRef } from 'vue';

// Paste an SVG or pick a file, and it goes on the board as a shape. Markup is
// drawn through an <img>, so a document that carries script never executes it:
// canvas refuses to run script in an image, which is the point of doing it this
// way rather than injecting the markup into the page.
const emit = defineEmits<{ add: [string]; file: [File]; close: [] }>();

const markup = ref('');
const problem = ref('');
const file = useTemplateRef<HTMLInputElement>('file');

const EXAMPLE = `<svg viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2">
  <path d="M12 2 L22 22 L2 22 Z" />
</svg>`;

const looksLikeSvg = (text: string) => /<svg[\s>]/i.test(text);

const submit = () => {
    const text = markup.value.trim();

    if (!looksLikeSvg(text)) {
        problem.value = 'That does not look like SVG: it needs an <svg> tag.';

        return;
    }

    problem.value = '';
    emit('add', text);
    markup.value = '';
};

const onFile = (event: Event) => {
    const chosen = (event.target as HTMLInputElement).files?.[0];

    if (!chosen) {
        return;
    }

    problem.value = '';
    emit('file', chosen);
};
</script>

<template>
    <div class="svg-backdrop" @click.self="emit('close')">
        <div class="svg-dialog" data-test="svg-import">
            <div class="svg-head">
                <h2>Add a picture</h2>
                <button type="button" title="Close" @click="emit('close')">
                    <X class="size-4" />
                </button>
            </div>

            <p class="svg-hint">
                Pick a PNG, JPEG or SVG file, or paste SVG markup below. You can
                also paste a screenshot onto the board with Ctrl+V, or drop a
                file on it. Whatever arrives lands in the middle of the view and
                resizes like any other shape.
            </p>

            <textarea
                v-model="markup"
                class="svg-input"
                spellcheck="false"
                :placeholder="EXAMPLE"
                data-test="svg-markup"
            />

            <p v-if="problem" class="svg-problem" data-test="svg-problem">
                {{ problem }}
            </p>

            <div class="svg-actions">
                <input
                    ref="file"
                    type="file"
                    accept="image/*,.svg"
                    hidden
                    @change="onFile"
                />
                <button type="button" class="svg-file" @click="file?.click()">
                    Choose a file
                </button>
                <button
                    type="button"
                    class="svg-add"
                    :disabled="!markup.trim()"
                    data-test="svg-add"
                    @click="submit"
                >
                    Add to board
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.svg-backdrop {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: rgb(0 0 0 / 0.35);
}

.svg-dialog {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    width: min(32rem, calc(100vw - 2rem));
    padding: 1rem;
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    box-shadow: 0 24px 64px rgb(0 0 0 / 0.25);
}

.svg-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.svg-head h2 {
    font-size: 1rem;
    font-weight: 600;
}
.svg-head button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: var(--radius-md);
    cursor: pointer;
}
.svg-head button:hover {
    background-color: var(--muted);
}

.svg-hint,
.svg-problem {
    font-size: 0.75rem;
    color: var(--muted-foreground);
}
.svg-problem {
    color: var(--destructive);
}

.svg-input {
    height: 10rem;
    padding: 0.5rem;
    font-family: var(--font-mono, monospace);
    font-size: 0.75rem;
    line-height: 1.5;
    color: var(--foreground);
    background-color: var(--muted);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    resize: none;
}

.svg-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}
.svg-actions button {
    height: 2.25rem;
    padding: 0 0.875rem;
    font-size: 0.8125rem;
    font-weight: 500;
    border-radius: var(--radius-lg);
    cursor: pointer;
}
.svg-file {
    color: var(--foreground);
    border: 1px solid var(--border);
}
.svg-file:hover {
    background-color: var(--muted);
}
.svg-add {
    color: var(--primary-foreground);
    background-color: var(--primary);
}
.svg-add:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
