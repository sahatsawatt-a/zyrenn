// A circuit worked out: the voltage on every net and the current through
// every part, for a circuit standing still on DC -- sources, resistors, and
// diodes and LEDs taken as a fixed drop once they conduct. It is the sum a
// student does by hand with Ohm's and Kirchhoff's laws, done for the whole
// circuit at once (nodal analysis), from the same text the drawing is made of.
import type { Net, Part } from './parse';

/**
 * What there is to work out: parts by name, and the nets their pins are on.
 * A circuit read from text is one, and so is one read from a board's drawing.
 */
export type Solvable = {
    parts: Pick<Part, 'name' | 'symbol' | 'value'>[];
    nets: Net[];
};

type Piece = Solvable['parts'][number];

/** What one part is doing. */
export type PartAnswer = {
    // Across it, first pin over second, in volts
    voltage: number;
    // Through it, in at its first pin and out at its second, in amps
    current: number;
    // What it turns to heat (or, when negative, gives out), in watts
    power: number;
    // Said of a part that is not simply conducting: an LED that is off
    state?: string;
    // Said of a part asked for more than it is good for
    warning?: string;
};

export type Solved =
    | {
          ok: true;
          // Volts on each net, against ground
          nets: Map<Net, number>;
          parts: Map<string, PartAnswer>;
          // The net everything is measured against
          ground: Net;
          // What was assumed along the way, for the reader to know
          notes: string[];
          // Parts asked for more than they are good for, each in a sentence
          warnings: string[];
      }
    | { ok: false; error: string };

const SCALE: Record<string, number> = {
    p: 1e-12,
    n: 1e-9,
    u: 1e-6,
    µ: 1e-6,
    m: 1e-3,
    '': 1,
    k: 1e3,
    M: 1e6,
    G: 1e9,
};

/** The number in a value as written: "4.7 kΩ" is 4700, "100 nF" is 1e-7. */
export const quantity = (written: string): number | null => {
    const found = written
        .trim()
        .match(/^([+-]?\d+(?:\.\d+)?)\s*([pnuµmkMG]?)(?![a-zµ])/);

    return found ? Number(found[1]) * SCALE[found[2]] : null;
};

/** A number as an engineer writes it: 0.003 A is "3 mA". */
export const written = (value: number, unit: string): string => {
    const size = Math.abs(value);

    if (size < 1e-12) {
        return `0 ${unit}`;
    }

    const [scale, letter] = (
        [
            [1e9, 'G'],
            [1e6, 'M'],
            [1e3, 'k'],
            [1, ''],
            [1e-3, 'm'],
            [1e-6, 'µ'],
            [1e-9, 'n'],
            [1e-12, 'p'],
        ] as [number, string][]
    ).find(([step]) => size >= step * 0.9995) ?? [1e-12, 'p'];

    return `${Number((value / scale).toPrecision(3))} ${letter}${unit}`;
};

/** What a rail is at, by its name: 5V, 3V3, +12V, -5V. */
const railVoltage = (name: string): number | null => {
    const split = name.match(/^([+-]?)(\d+)v(\d+)$/i);
    const plain = name.match(/^([+-]?\d+(?:\.\d+)?)v?$/i);

    if (split) {
        return Number(`${split[1]}${split[2]}.${split[3]}`);
    }

    return plain ? Number(plain[1]) : null;
};

/** How far an LED drops once it is lit, by its colour. */
const ledDrop = (value: string): number => {
    const said = /(\d+(?:\.\d+)?)\s*v\b/i.exec(value);

    if (said) {
        return Number(said[1]);
    }

    if (/blue|white|uv|violet/i.test(value)) return 3;
    if (/green/i.test(value)) return 2.1;
    if (/yellow|amber|orange/i.test(value)) return 2;
    if (/infra|ir\b/i.test(value)) return 1.2;

    return 1.8;
};

/** How each kind of part behaves once the circuit has settled. */
type Model =
    | { is: 'resistor'; ohms: number }
    // Holds its first pin `volts` below its second: a battery, and with 0 a
    // closed switch, a fuse, a wire
    | { is: 'source'; volts: number }
    | { is: 'current'; amps: number }
    | { is: 'open' }
    // Conducts one way once `drop` is across it; a zener the other way at `breaks`
    | { is: 'diode'; drop: number; breaks?: number };

class Unsolvable extends Error {}

/**
 * What a part is good for: as its value says ("100 1W", "led red 30mA"), or
 * as an ordinary one of its kind is.
 */
const ratingOf = (
    part: Piece,
): { limit: number; unit: 'W' | 'A'; said: boolean; kind: string } | null => {
    const value = part.value;
    const fraction = /(\d+)\s*\/\s*(\d+)\s*W\b/.exec(value);
    const watts = /(\d+(?:\.\d+)?)\s*(m?)W\b/.exec(value);
    const amps = /(\d+(?:\.\d+)?)\s*([mµu]?)A\b/.exec(value);
    const power = fraction
        ? Number(fraction[1]) / Number(fraction[2])
        : watts
          ? Number(watts[1]) * SCALE[watts[2]]
          : null;
    const current = amps ? Number(amps[1]) * SCALE[amps[2]] : null;
    const rated = (
        unit: 'W' | 'A',
        usual: number,
        kind: string,
        said: number | null,
    ) => ({ limit: said ?? usual, unit, said: said !== null, kind });

    switch (part.symbol.id) {
        case 'resistor':
        case 'resistor_us':
        case 'potentiometer':
            return rated('W', 0.25, 'resistor', power);
        case 'led':
            return rated('A', 0.02, 'LED', current);
        case 'diode':
            return rated('A', 1, 'diode', current);
        case 'zener':
            return rated('W', 0.5, 'zener', power);
        default:
            return null;
    }
};

/** What is wrong with how hard a part is driven, or nothing. */
const judged = (part: Piece, answer: PartAnswer): string | undefined => {
    const rating = ratingOf(part);

    if (!rating) {
        return undefined;
    }

    const asked =
        rating.unit === 'W' ? Math.abs(answer.power) : Math.abs(answer.current);

    if (asked <= rating.limit * 1.001) {
        return undefined;
    }

    const doing =
        rating.unit === 'W'
            ? `turns ${written(asked, 'W')} to heat`
            : `carries ${written(asked, 'A')}`;
    const limit = written(rating.limit, rating.unit);

    return rating.said
        ? `${part.name} ${doing}: more than the ${limit} it is rated for.`
        : `${part.name} ${doing}: more than the ${limit} an ordinary ${rating.kind} is good for.`;
};

const modelOf = (part: Piece, notes: string[]): Model => {
    const value = part.value;
    const number = quantity(value);
    const need = (unit: string, example: string): number => {
        if (number === null) {
            throw new Unsolvable(
                `${part.name} needs a value to be worked out: give it ${unit}, as in "${part.name}: ${example}"`,
            );
        }

        return number;
    };

    switch (part.symbol.id) {
        case 'resistor':
        case 'resistor_us':
        case 'potentiometer':
            return { is: 'resistor', ohms: need('its ohms', '10k') };
        case 'lamp':
        case 'motor':
        case 'coil':
            return {
                is: 'resistor',
                ohms: need('its resistance in ohms', 'lamp 100'),
            };
        case 'vsource':
        case 'battery':
            return { is: 'source', volts: need('its volts', '9V') };
        case 'isource':
            return { is: 'current', amps: need('its amps', '1m') };
        // Standing still, a capacitor has charged and passes nothing...
        case 'capacitor':
        case 'capacitor_polarized':
            notes.push(
                `${part.name} is taken as charged: on DC a capacitor passes nothing once it is.`,
            );

            return { is: 'open' };
        // ...and an inductor is only its wire
        case 'inductor':
            notes.push(
                `${part.name} is taken as a wire: on DC an inductor drops nothing once the current is steady.`,
            );

            return { is: 'source', volts: 0 };
        case 'fuse':
        case 'breaker':
        case 'ammeter':
        case 'contact_nc':
            return { is: 'source', volts: 0 };
        case 'voltmeter':
        case 'crystal':
            return { is: 'open' };
        case 'switch':
        case 'button': {
            const closed = /\b(closed|on|pressed)\b/i.test(value);

            notes.push(
                closed
                    ? `${part.name} is taken as closed.`
                    : `${part.name} is taken as open, as it is drawn: write "${part.name}: ${part.symbol.id} closed" to close it.`,
            );

            return closed ? { is: 'source', volts: 0 } : { is: 'open' };
        }
        case 'diode':
            return { is: 'diode', drop: 0.7 };
        case 'led':
            return { is: 'diode', drop: ledDrop(value) };
        case 'zener':
            return {
                is: 'diode',
                drop: 0.7,
                breaks: need('the voltage it holds', 'zener 5.1V'),
            };
        case 'ac':
            throw new Unsolvable(
                `${part.name} is an AC source, and only DC is worked out`,
            );
        default:
            throw new Unsolvable(
                `${part.name} (${part.symbol.label}) is not worked out yet: sources, resistors, diodes and LEDs are`,
            );
    }
};

/** Solves A·x = b, or answers null when there is no one answer. */
const solved = (a: number[][], b: number[]): number[] | null => {
    const size = b.length;

    for (let column = 0; column < size; column++) {
        let pivot = column;

        for (let row = column + 1; row < size; row++) {
            if (Math.abs(a[row][column]) > Math.abs(a[pivot][column])) {
                pivot = row;
            }
        }

        if (Math.abs(a[pivot][column]) < 1e-14) {
            return null;
        }

        [a[column], a[pivot]] = [a[pivot], a[column]];
        [b[column], b[pivot]] = [b[pivot], b[column]];

        for (let row = column + 1; row < size; row++) {
            const factor = a[row][column] / a[column][column];

            if (factor) {
                for (let at = column; at < size; at++) {
                    a[row][at] -= factor * a[column][at];
                }

                b[row] -= factor * b[column];
            }
        }
    }

    const x = Array.from({ length: size }, () => 0);

    for (let row = size - 1; row >= 0; row--) {
        let sum = b[row];

        for (let at = row + 1; at < size; at++) {
            sum -= a[row][at] * x[at];
        }

        x[row] = sum / a[row][row];
    }

    return x;
};

/** Works a circuit out, or says why it cannot be. */
export const solveCircuit = (circuit: Solvable): Solved => {
    const notes: string[] = [];
    const warnings: string[] = [];

    try {
        const netOf = new Map<string, Net>();

        for (const net of circuit.nets) {
            for (const pin of net.pins) {
                netOf.set(`${pin.part}.${pin.pin}`, net);
            }
        }

        // Measured against the ground; without one, against the negative
        // end of the first source, as a meter's black lead would be
        const source = circuit.parts.find((part) => part.symbol.source);
        const ground =
            circuit.nets.find((net) => net.kind === 'ground') ??
            (source
                ? netOf.get(`${source.name}.${source.symbol.pins[0].name}`)
                : undefined) ??
            circuit.nets[0];

        if (!ground) {
            throw new Unsolvable('there is nothing wired up to work out');
        }

        if (ground.kind !== 'ground') {
            notes.push(
                source
                    ? `Voltages are measured from the negative end of ${source.name}: there is no ground in the circuit.`
                    : 'Voltages are measured from the first net: there is no ground in the circuit.',
            );
        }

        const nodes = circuit.nets.filter((net) => net !== ground);
        const index = new Map(nodes.map((net, at) => [net, at]));

        // Every part between two nets, with how it behaves
        const parts = circuit.parts.flatMap((part) => {
            const pins = part.symbol.pins;

            // A terminal is only a name for the net it is on
            if (pins.length === 1) {
                return [];
            }

            if (pins.length !== 2) {
                throw new Unsolvable(
                    `${part.name} (${part.symbol.label}) is not worked out yet: sources, resistors, diodes and LEDs are`,
                );
            }

            const from = netOf.get(`${part.name}.${pins[0].name}`);
            const to = netOf.get(`${part.name}.${pins[1].name}`);
            const model = modelOf(part, notes);

            if (!from || !to) {
                notes.push(
                    `${part.name} has an end joined to nothing, so nothing flows through it.`,
                );

                return [];
            }

            return [{ part, from, to, model }];
        });

        // A rail is held at the voltage in its name
        const rails = circuit.nets
            .filter((net) => net.kind === 'power')
            .map((net) => {
                const volts = railVoltage(net.name);

                if (volts === null) {
                    throw new Unsolvable(
                        `the rail "${net.name}" does not say its voltage: name it for it, as in 5V or 3V3`,
                    );
                }

                return { net, volts };
            });

        // Which way each diode is taken to be: off, on, or (a zener) held backwards
        const diodes = parts.filter((each) => each.model.is === 'diode');
        const states = new Map<Piece, 'off' | 'on' | 'held'>(
            diodes.map((each) => [each.part, 'on']),
        );

        let answer: {
            volts: (net: Net) => number;
            through: Map<Piece, number>;
        } | null = null;

        for (let round = 0; round < 40; round++) {
            // The unknowns: each net's voltage, then the current through
            // each thing that holds a voltage rather than obeying Ohm
            const holders = parts.filter(
                (each) =>
                    each.model.is === 'source' ||
                    (each.model.is === 'diode' &&
                        states.get(each.part) !== 'off'),
            );
            const size = nodes.length + holders.length + rails.length;
            const a = Array.from({ length: size }, () =>
                Array.from({ length: size }, () => 0),
            );
            const b = Array.from({ length: size }, () => 0);
            const at = (net: Net) => index.get(net);

            // A whisper of a path to ground from every net, so one joined to
            // nothing sits at 0 V instead of having no answer
            nodes.forEach((_, row) => (a[row][row] += 1e-12));

            for (const { from, to, model } of parts) {
                const i = at(from);
                const j = at(to);

                if (model.is === 'resistor') {
                    const g = 1 / Math.max(model.ohms, 1e-9);

                    if (i !== undefined) a[i][i] += g;
                    if (j !== undefined) a[j][j] += g;
                    if (i !== undefined && j !== undefined) {
                        a[i][j] -= g;
                        a[j][i] -= g;
                    }
                }

                // Pushed in at its first pin and out at its second
                if (model.is === 'current') {
                    if (i !== undefined) b[i] -= model.amps;
                    if (j !== undefined) b[j] += model.amps;
                }
            }

            holders.forEach(({ part, from, to, model }, count) => {
                const row = nodes.length + count;
                const i = at(from);
                const j = at(to);
                // V(second) - V(first): what a source holds; a lit diode
                // has its first pin `drop` above its second
                const holds =
                    model.is === 'source'
                        ? model.volts
                        : model.is === 'diode'
                          ? states.get(part) === 'held'
                              ? (model.breaks ?? 0)
                              : -model.drop
                          : 0;

                // Its current leaves the first net and comes into the second
                if (i !== undefined) {
                    a[i][row] += 1;
                    a[row][i] -= 1;
                }

                if (j !== undefined) {
                    a[j][row] -= 1;
                    a[row][j] += 1;
                }

                b[row] = holds;
            });

            rails.forEach(({ net, volts }, count) => {
                const row = nodes.length + holders.length + count;
                const i = at(net);

                if (i !== undefined) {
                    a[i][row] -= 1;
                    a[row][i] += 1;
                }

                b[row] = volts;
            });

            const x = solved(a, b);

            if (!x) {
                throw new Unsolvable(
                    diodes.length
                        ? 'there is no one answer: a diode or LED may be straight across a source with no resistor in line to limit its current, or a source is shorted'
                        : 'there is no one answer: two sources may be holding the same nets at different voltages, or a source is shorted',
                );
            }

            const volts = (net: Net) => {
                const where = at(net);

                return where === undefined ? 0 : x[where];
            };
            const through = new Map<Piece, number>();

            for (const { part, from, to, model } of parts) {
                if (model.is === 'resistor') {
                    through.set(
                        part,
                        (volts(from) - volts(to)) / Math.max(model.ohms, 1e-9),
                    );
                } else if (model.is === 'current') {
                    through.set(part, model.amps);
                } else {
                    through.set(part, 0);
                }
            }

            holders.forEach(({ part }, count) =>
                through.set(part, x[nodes.length + count]),
            );

            // Was each diode taken the right way? One that would have to
            // pass current backwards is off; one with more than its drop
            // across it is on
            let changed = false;

            for (const { part, from, to, model } of diodes) {
                if (model.is !== 'diode') continue;

                const state = states.get(part)!;
                const across = volts(from) - volts(to);
                const current = through.get(part) ?? 0;
                let next = state;

                if (state === 'on' && current < -1e-9) next = 'off';
                if (state === 'held' && current > 1e-9) next = 'off';
                if (state === 'off' && across > model.drop + 1e-9) next = 'on';
                if (
                    state === 'off' &&
                    model.breaks !== undefined &&
                    across < -model.breaks - 1e-9
                ) {
                    next = 'held';
                }

                if (next !== state) {
                    states.set(part, next);
                    changed = true;
                }
            }

            answer = { volts, through };

            if (!changed) {
                break;
            }

            if (round === 39) {
                throw new Unsolvable(
                    'the diodes do not settle one way or the other',
                );
            }
        }

        const nets = new Map<Net, number>(
            circuit.nets.map((net) => [net, answer!.volts(net)]),
        );
        const answers = new Map<string, PartAnswer>();

        for (const { part, from, to, model } of parts) {
            const voltage = answer!.volts(from) - answer!.volts(to);
            const current = answer!.through.get(part) ?? 0;
            const state =
                model.is === 'diode'
                    ? states.get(part) === 'off'
                        ? 'off'
                        : states.get(part) === 'held'
                          ? 'holding'
                          : 'on'
                    : undefined;

            const worked: PartAnswer = {
                voltage,
                current,
                power: voltage * current,
                ...(state ? { state } : {}),
            };
            const warning = judged(part, worked);

            if (warning) {
                worked.warning = warning;
                warnings.push(warning);
            }

            answers.set(part.name, worked);
        }

        if (diodes.length) {
            notes.push(
                'A diode or LED is taken as dropping a fixed voltage once it conducts (0.7 V, or about 2 V for an LED by its colour) and as passing nothing below that.',
            );
        }

        return { ok: true, nets, parts: answers, ground, notes, warnings };
    } catch (problem) {
        if (problem instanceof Unsolvable) {
            return {
                ok: false,
                error: `Not worked out: ${problem.message}.`,
            };
        }

        throw problem;
    }
};
