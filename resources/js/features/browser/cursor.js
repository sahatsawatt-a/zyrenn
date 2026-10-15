// The pointer drawn on a page, and a ring where it presses: for someone to
// follow what is being done for them. Drawn by a headed browser suite
// (tests/e2e/harness.mjs, which hands this function to the page as text) and
// by a tour of the app (tour.ts) -- so it uses nothing from outside itself.
// It takes no clicks.
export function drawCursor() {
    let cursor = document.getElementById('e2e-cursor');

    if (!cursor) {
        const style = document.createElement('style');
        style.textContent = `
            #e2e-cursor { position: fixed; left: 0; top: 0; z-index: 2147483647; pointer-events: none;
                width: 22px; height: 22px; margin: -3px 0 0 -3px; transition: transform 160ms ease-out;
                filter: drop-shadow(0 1px 2px rgb(0 0 0 / 0.45)); }
            .e2e-press { position: fixed; z-index: 2147483646; pointer-events: none; width: 34px; height: 34px;
                margin: -17px 0 0 -17px; border-radius: 9999px; border: 3px solid rgb(239 68 68);
                animation: e2e-press 600ms ease-out forwards; }
            @keyframes e2e-press { from { transform: scale(0.3); opacity: 1 } to { transform: scale(1.4); opacity: 0 } }`;
        document.head.appendChild(style);

        cursor = document.createElement('div');
        cursor.id = 'e2e-cursor';
        cursor.innerHTML =
            '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M3 2l7 19 2.5-7.5L20 11z" fill="#111" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/></svg>';
        document.body.appendChild(cursor);
    }

    return {
        /** Moves there, gliding for `ms` (a mouse's own moves are quick). */
        moveTo(x, y, ms = 160) {
            cursor.style.transitionDuration = `${ms}ms`;
            cursor.style.transform = `translate(${x}px, ${y}px)`;
        },
        press(x, y) {
            const ring = document.createElement('div');
            ring.className = 'e2e-press';
            ring.style.left = `${x}px`;
            ring.style.top = `${y}px`;
            document.body.appendChild(ring);
            setTimeout(() => ring.remove(), 650);
        },
        remove() {
            cursor.remove();
        },
    };
}
