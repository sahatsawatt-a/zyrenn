import { nextTick } from 'vue';

/**
 * "The note is drawn" -- said by the page, to the PDF printer.
 *
 * The printer (docker/chrome/server.mjs) is a browser that has to decide when
 * to print, and from outside every signal is a guess: the network goes quiet
 * long before a Mermaid diagram has been laid out. So the page says it, once,
 * on an attribute the printer waits for:
 *
 *     <html data-print-ready="1">
 *
 * It is said when the editor is up and every block that draws itself later (a
 * diagram, a board) has let go of its hold. The printer still checks for itself
 * that the pictures arrived, and prints anyway if this is never said.
 */
export const PRINT_READY_ATTR = 'data-print-ready';

let editorUp = false;
let pending = 0;

async function check(): Promise<void> {
    if (!editorUp || pending > 0) {
        return;
    }

    // Let Vue mount what the editor just asked for, then let the browser lay it out
    await nextTick();
    await new Promise<void>((resolve) =>
        requestAnimationFrame(() => resolve()),
    );

    if (editorUp && pending === 0) {
        document.documentElement.setAttribute(PRINT_READY_ATTR, '1');
    }
}

/** The editor has its document; the page is ready once nothing holds it back. */
export function markEditorReady(): void {
    editorUp = true;
    void check();
}

/**
 * Hold the page back until something has finished drawing. Call it while the
 * block is set up, and the returned function once it has drawn (or failed to);
 * calling that more than once is harmless.
 */
export function holdPrint(): () => void {
    pending++;
    document.documentElement.removeAttribute(PRINT_READY_ATTR);

    let released = false;

    return () => {
        if (released) {
            return;
        }

        released = true;
        pending--;
        void check();
    };
}
