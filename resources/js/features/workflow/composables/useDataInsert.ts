import { ref } from 'vue';

type Field = HTMLInputElement | HTMLTextAreaElement;

/**
 * A part of the test data, clicked rather than dragged: it goes into the
 * setting last written in, where the cursor was -- as {{ trigger.chat.id }}
 * into a template, or bare into a formula. Which settings take data is
 * marked on them (data-takes-data), as it is for dragging.
 */
export function useDataInsert() {
    // What holds the step's settings
    const panels = ref<HTMLElement | null>(null);
    let last: Field | null = null;

    const takers = () =>
        Array.from(
            panels.value?.querySelectorAll<Field>(
                '[data-takes-data]:not(:disabled)',
            ) ?? [],
        );

    function focused(event: FocusEvent) {
        const target = event.target as HTMLElement | null;

        if (target?.matches?.('[data-takes-data]')) {
            last = target as Field;
        }
    }

    /** Puts it in, and answers with whether there was somewhere for it to go. */
    function insert(path: string): boolean {
        const open = takers();
        // The one written in last; or the only one there is
        const field =
            last && open.includes(last)
                ? last
                : open.length === 1
                  ? open[0]
                  : null;

        if (!field) {
            return false;
        }

        const text =
            field.dataset.takesData === 'formula' ? path : `{{ ${path} }}`;
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? start;

        field.value =
            field.value.slice(0, start) + text + field.value.slice(end);
        field.dispatchEvent(new Event('input', { bubbles: true }));
        last = field;

        // Once it is on show again, on a screen that shows one panel at a time
        requestAnimationFrame(() => {
            field.focus({ preventScroll: false });
            field.setSelectionRange(start + text.length, start + text.length);
        });

        return true;
    }

    return { panels, focused, insert };
}
