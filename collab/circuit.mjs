// A circuit written as text, laid out as a board's items: the same reading
// and the same layout the browser does for a ```circuit block (features/
// boards/circuit), run here for the server, which cannot run them itself.
// The text comes in on stdin; what goes out is one line of JSON --
// {"ok": true, "items": […]} or {"ok": false, "error": "…"}.
//
//     node --import ./collab/ts-loader.mjs collab/circuit.mjs < circuit.txt
import { layOut } from '@/features/boards/circuit/layout';
import { parseCircuit } from '@/features/boards/circuit/parse';

let text = '';

for await (const chunk of process.stdin) {
    text += chunk;
}

const answer = () => {
    const circuit = parseCircuit(text);

    if (!circuit.ok) {
        return circuit;
    }

    if (circuit.circuit.parts.length === 0) {
        return {
            ok: false,
            error: 'There are no parts yet: write one as "R1: 10k".',
        };
    }

    return { ok: true, items: layOut(circuit.circuit) };
};

let said;

try {
    said = answer();
} catch (error) {
    said = {
        ok: false,
        error: error instanceof Error ? error.message : String(error),
    };
}

process.stdout.write(JSON.stringify(said));
