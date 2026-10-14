<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { renderCircuit } from '@/features/boards/circuit/render';
import { written } from '@/features/boards/circuit/solve';
import { DIVIDER, noteDemo } from '@/features/labs/noteDemo';
import TiptapEditor from '@/features/notes/components/TiptapEditor.vue';
import { board, circuitBoard } from '@/routes/labs';

// What was added last, each with something to click: the same blocks and
// parts every note and board has, set up here to be tried.

// ------------------------------------------------------ a circuit worked out
const CIRCUITS = [
    { name: 'Divider', source: DIVIDER.replace('values hidden\n', '') },
    {
        name: 'In series',
        source: 'V1: battery 9V\nR1: 1k\nR2: 2k\nV1 -- R1 -- R2 -- V1',
    },
    {
        name: 'In parallel',
        source: 'V1: battery 9V\nR1: 1k\nR2: 2k\nV1.+ -- R1 -- V1.-\nV1.+ -- R2 -- V1.-',
    },
    {
        name: 'An LED',
        source: 'V1: battery 9V\nR1: 470\nD1: led red\nV1 -- R1 -- D1 -- V1',
    },
    {
        name: 'Too much',
        source: 'V1: battery 9V\nR1: 100\nD1: led red\nV1 -- R1 -- D1 -- V1',
    },
];

const circuit = ref(CIRCUITS[1].source);
const drawn = computed(() => renderCircuit(circuit.value, { values: true }));
const answers = computed(() => {
    const made = drawn.value;

    if (!made.ok || !made.solved?.ok) {
        return null;
    }

    const { solved } = made;

    return {
        parts: made.circuit.parts.flatMap((part) => {
            const answer = solved.parts.get(part.name);

            return answer
                ? [
                      {
                          name: part.name,
                          voltage: written(Math.abs(answer.voltage), 'V'),
                          current: written(Math.abs(answer.current), 'A'),
                          power: written(Math.abs(answer.power), 'W'),
                          over: !!answer.warning,
                      },
                  ]
                : [];
        }),
        notes: solved.notes,
        warnings: solved.warnings,
    };
});

// ------------------------------------------- a circuit laid out by the server
const layout = useForm({
    circuit:
        'U1: esp32\nR1: 330\nD1: led green\nS1: button\nU1.GPIO2 -- R1 -- D1 -- GND\nU1.GPIO4 -- S1 -- GND\nU1.3V3 -- 3V3\nU1.GND -- GND',
});
</script>

<template>
    <Head title="Labs" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-10 px-4 py-8">
        <header class="flex flex-col gap-3">
            <h1 class="text-2xl font-semibold">Labs</h1>
            <p class="text-muted-foreground text-sm">
                What was added last, set up to try. All of it is in your own
                notes and boards too.
            </p>
        </header>

        <section class="flex flex-col gap-3" data-test="labs-note">
            <h2 class="text-lg font-semibold">In a note</h2>
            <p class="text-muted-foreground text-sm">
                An answer that stays hidden until it is clicked, a circuit that
                writes its own voltages and currents, and a graph with values to
                slide. This is the real editor: type
                <kbd>/</kbd> for more of each.
            </p>
            <div class="labs-note overflow-hidden rounded-xl border">
                <TiptapEditor :content="noteDemo" />
            </div>
        </section>

        <section class="flex flex-col gap-3" data-test="labs-solver">
            <h2 class="text-lg font-semibold">A circuit, worked out</h2>
            <p class="text-muted-foreground text-sm">
                Sources, resistors, diodes and LEDs at DC. Change a value and
                the answers follow; a part asked for more than it is good for is
                said.
            </p>
            <div class="flex flex-wrap gap-1.5">
                <Button
                    v-for="each in CIRCUITS"
                    :key="each.name"
                    size="sm"
                    :variant="circuit === each.source ? 'default' : 'outline'"
                    @click="circuit = each.source"
                >
                    {{ each.name }}
                </Button>
            </div>
            <textarea
                v-model="circuit"
                rows="6"
                spellcheck="false"
                class="bg-background rounded-lg border p-3 font-mono text-sm"
                aria-label="The circuit, as text"
                data-test="labs-circuit"
            />
            <p
                v-if="!drawn.ok"
                class="text-destructive text-sm"
                data-test="labs-circuit-error"
            >
                {{ drawn.error }}
            </p>
            <template v-else>
                <!-- eslint-disable-next-line vue/no-v-html -- drawn from the symbol library's own paths, with the words escaped -->
                <div
                    class="labs-drawing overflow-x-auto rounded-lg border p-4"
                    v-html="drawn.svg"
                ></div>
                <table
                    v-if="answers"
                    class="w-full text-sm"
                    data-test="labs-answers"
                >
                    <thead class="text-muted-foreground text-left text-xs">
                        <tr>
                            <th class="py-1 font-medium">Part</th>
                            <th class="py-1 text-right font-medium">Across</th>
                            <th class="py-1 text-right font-medium">Through</th>
                            <th class="py-1 text-right font-medium">Power</th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        <tr
                            v-for="part in answers.parts"
                            :key="part.name"
                            class="border-t"
                            :class="{ 'text-destructive': part.over }"
                        >
                            <td class="py-1 font-medium">{{ part.name }}</td>
                            <td class="py-1 text-right">{{ part.voltage }}</td>
                            <td class="py-1 text-right">{{ part.current }}</td>
                            <td class="py-1 text-right">{{ part.power }}</td>
                        </tr>
                    </tbody>
                </table>
                <p
                    v-for="warning in answers?.warnings ?? []"
                    :key="warning"
                    class="text-destructive text-sm"
                    data-test="labs-warning"
                >
                    ⚠ {{ warning }}
                </p>
                <p
                    v-for="note in answers?.notes ?? []"
                    :key="note"
                    class="text-muted-foreground text-xs"
                >
                    {{ note }}
                </p>
            </template>
        </section>

        <section class="flex flex-col gap-3" data-test="labs-logic">
            <h2 class="text-lg font-semibold">On a board</h2>
            <p class="text-muted-foreground text-sm">
                A push-button that is on only while held, gates with three
                inputs, and a 7-segment display, wired up to click (they are in
                the shapes list under Electrical). And a circuit to work out:
                Values, at the top of the board, writes the voltages and
                currents on the drawing, and marks a part asked for more than it
                is good for.
            </p>
            <Button as-child class="self-start" data-test="labs-open-board">
                <Link :href="board()">Open the board</Link>
            </Button>
        </section>

        <section class="flex flex-col gap-3" data-test="labs-layout">
            <h2 class="text-lg font-semibold">
                A circuit laid out by the server
            </h2>
            <p class="text-muted-foreground text-sm">
                What an AI client now does by sending
                <code>circuit</code> to create-board, or
                <code>add_circuit</code> to update-board: the text is laid out
                on the server and put on a board, wires clear of one another.
                This makes a new board of your own.
            </p>
            <textarea
                v-model="layout.circuit"
                rows="8"
                spellcheck="false"
                class="bg-background rounded-lg border p-3 font-mono text-sm"
                aria-label="The circuit to lay out, as text"
                data-test="labs-layout-text"
            />
            <p
                v-if="layout.errors.circuit"
                class="text-destructive text-sm"
                data-test="labs-layout-error"
            >
                {{ layout.errors.circuit }}
            </p>
            <Button
                class="self-start"
                :disabled="layout.processing"
                data-test="labs-layout-go"
                @click="layout.post(circuitBoard.url())"
            >
                Put it on a new board
            </Button>
        </section>

        <section class="flex flex-col gap-3" data-test="labs-more">
            <h2 class="text-lg font-semibold">In your own pages</h2>
            <ul
                class="text-muted-foreground flex list-disc flex-col gap-2 pl-5 text-sm"
            >
                <li>
                    <strong class="text-foreground">Linked from</strong> on a
                    board, a table or a trip: when a note links to one, as
                    <code>[[board:Its title]]</code>, the page shows a link
                    button with how many notes do, and lists them.
                </li>
                <li>
                    <strong class="text-foreground">A graph of a table</strong>:
                    in a note, type <kbd>/graph</kbd> and write
                    <code>table: Its title</code>, <code>x: A column</code> and
                    <code>y: Another, And another</code>. The columns have to
                    hold numbers.
                </li>
                <li>
                    <strong class="text-foreground">Graph</strong> and
                    <strong class="text-foreground">Reveal</strong> in the
                    <kbd>/</kbd> menu of a note, the new logic parts in a
                    board's shapes list under Electrical, and
                    <strong class="text-foreground">Values</strong> on any
                    circuit with a battery, a source or a rail in it.
                </li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
/* The note is one section of the page here, not a page of its own: as tall
   as what it holds, and close to the edge on a phone */
.labs-note :deep(.editor-surface) {
    min-height: 0;
    margin: 0;
    padding: 1rem 2.5rem;
}
@media (max-width: 640px) {
    .labs-note :deep(.editor-surface) {
        padding: 0.5rem 0.75rem;
    }
    .labs-note :deep(.diagram-preview) {
        padding: 0.75rem 0.5rem;
    }
}

.labs-drawing :deep(svg) {
    max-width: 100%;
    height: auto;
    margin: 0 auto;
}
</style>
