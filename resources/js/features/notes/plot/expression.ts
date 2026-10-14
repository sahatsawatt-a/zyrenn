// A formula as it is written -- "5 * (1 - exp(-t / (R * C)))" -- made into
// something that can be worked out for any values of its names. It is read
// by hand: nothing typed into a note is ever run as code.

export type Scope = Record<string, number>;

export type Expression = {
    /** The names it needs values for. */
    names: string[];
    value: (scope: Scope) => number;
};

type Token =
    | { kind: 'number'; value: number }
    | { kind: 'name'; name: string }
    | { kind: 'sign'; sign: string };

// 4.7k, 100n: the multipliers written straight after a number
const MULTIPLIERS: Record<string, number> = {
    p: 1e-12,
    n: 1e-9,
    u: 1e-6,
    µ: 1e-6,
    m: 1e-3,
    k: 1e3,
    M: 1e6,
    G: 1e9,
};

const CONSTANTS: Record<string, number> = { pi: Math.PI, e: Math.E };

const FUNCTIONS: Record<string, (...values: number[]) => number> = {
    sin: Math.sin,
    cos: Math.cos,
    tan: Math.tan,
    asin: Math.asin,
    acos: Math.acos,
    atan: Math.atan,
    sinh: Math.sinh,
    cosh: Math.cosh,
    tanh: Math.tanh,
    sqrt: Math.sqrt,
    abs: Math.abs,
    exp: Math.exp,
    ln: Math.log,
    log: Math.log10,
    log2: Math.log2,
    floor: Math.floor,
    ceil: Math.ceil,
    round: Math.round,
    sign: Math.sign,
    min: Math.min,
    max: Math.max,
    // 0 before zero and 1 from it on: something switched on
    step: (value) => (value >= 0 ? 1 : 0),
};

// How many values each takes, where it is not one
const TAKES: Record<string, [number, number]> = {
    min: [1, 8],
    max: [1, 8],
};

// Only what a table was given: "constructor" is a name like any other
const own = <T>(table: Record<string, T>, name: string): T | undefined =>
    Object.hasOwn(table, name) ? table[name] : undefined;

const NUMBER = /^(\d+\.?\d*|\.\d+)(e[+-]?\d+)?/i;
const NAME = /^[A-Za-z_][A-Za-z0-9_]*/;

const tokens = (text: string): Token[] => {
    const found: Token[] = [];
    let rest = text;

    while ((rest = rest.trimStart()) !== '') {
        const number = NUMBER.exec(rest);

        if (number) {
            let value = Number(number[0]);
            rest = rest.slice(number[0].length);

            // A letter right after it multiplies it, when that is all it is
            if (
                rest !== '' &&
                own(MULTIPLIERS, rest[0]) !== undefined &&
                !/^.[A-Za-z0-9_]/.test(rest)
            ) {
                value *= MULTIPLIERS[rest[0]];
                rest = rest.slice(1);
            }

            found.push({ kind: 'number', value });
            continue;
        }

        const name = NAME.exec(rest);

        if (name) {
            found.push({ kind: 'name', name: name[0] });
            rest = rest.slice(name[0].length);
            continue;
        }

        if (!'+-*/^(),'.includes(rest[0])) {
            throw new Error(`"${rest[0]}" is not something a formula can hold`);
        }

        found.push({ kind: 'sign', sign: rest[0] });
        rest = rest.slice(1);
    }

    return found;
};

type Worked = (scope: Scope) => number;

/**
 * Read a formula. What is wrong with one that cannot be read is thrown, in
 * words for whoever wrote it.
 */
export const compile = (text: string): Expression => {
    const list = tokens(text);
    const names = new Set<string>();
    let at = 0;

    const sign = (wanted: string): boolean => {
        const token = list[at];

        if (token?.kind === 'sign' && token.sign === wanted) {
            at += 1;

            return true;
        }

        return false;
    };

    const said = (token: Token | undefined): string => {
        if (!token) {
            return 'the end';
        }

        return token.kind === 'number'
            ? String(token.value)
            : `"${token.kind === 'name' ? token.name : token.sign}"`;
    };

    const atom = (): Worked => {
        const token = list[at];

        if (token?.kind === 'number') {
            at += 1;

            return () => token.value;
        }

        if (token?.kind === 'name') {
            at += 1;

            if (!sign('(')) {
                const constant = own(CONSTANTS, token.name);

                if (constant !== undefined) {
                    // A name of one's own comes before pi and e
                    return (scope) => own(scope, token.name) ?? constant;
                }

                names.add(token.name);

                return (scope) => own(scope, token.name) ?? NaN;
            }

            const run = own(FUNCTIONS, token.name);

            if (!run) {
                throw new Error(
                    `${token.name}( ) is not known: there are ${Object.keys(FUNCTIONS).join(', ')}`,
                );
            }

            const given: Worked[] = [];

            if (!sign(')')) {
                do {
                    given.push(sum());
                } while (sign(','));

                if (!sign(')')) {
                    throw new Error(`${token.name}( is never closed`);
                }
            }

            const [least, most] = own(TAKES, token.name) ?? [1, 1];

            if (given.length < least || given.length > most) {
                throw new Error(
                    `${token.name}( ) takes ${least === most ? (least === 1 ? 'one value' : `${least} values`) : `${least} to ${most} values`}`,
                );
            }

            return (scope) => run(...given.map((each) => each(scope)));
        }

        if (sign('(')) {
            const inside = sum();

            if (!sign(')')) {
                throw new Error('A bracket is never closed');
            }

            return inside;
        }

        throw new Error(
            at === 0 && !token
                ? 'There is nothing to work out'
                : `Something is missing before ${said(token)}`,
        );
    };

    // -2^2 is -4, and 2^-1 a half
    const signed = (): Worked => {
        if (sign('-')) {
            const value = signed();

            return (scope) => -value(scope);
        }

        if (sign('+')) {
            return signed();
        }

        const base = atom();

        if (sign('^')) {
            const power = signed();

            return (scope) => base(scope) ** power(scope);
        }

        return base;
    };

    const product = (): Worked => {
        let left = signed();

        for (;;) {
            if (sign('*')) {
                const before = left;
                const right = signed();
                left = (scope) => before(scope) * right(scope);
            } else if (sign('/')) {
                const before = left;
                const right = signed();
                left = (scope) => before(scope) / right(scope);
            } else {
                return left;
            }
        }
    };

    function sum(): Worked {
        let left = product();

        for (;;) {
            if (sign('+')) {
                const before = left;
                const right = product();
                left = (scope) => before(scope) + right(scope);
            } else if (sign('-')) {
                const before = left;
                const right = product();
                left = (scope) => before(scope) - right(scope);
            } else {
                return left;
            }
        }
    }

    const value = sum();

    if (at < list.length) {
        const before = list[at - 1];
        const next = list[at];

        // "2 x": two things side by side, with nothing said between them
        throw new Error(
            next.kind !== 'sign' || next.sign === '('
                ? `Something is missing between ${said(before)} and ${said(next)}: write * to multiply`
                : `${said(next)} is not expected there`,
        );
    }

    return { names: [...names], value };
};

/** A number written as a formula with no names in it: "2 * pi", "4.7k". */
export const number = (text: string): number => {
    const read = compile(text);

    if (read.names.length > 0) {
        throw new Error(`"${read.names[0]}" is not a number`);
    }

    const value = read.value({});

    if (!Number.isFinite(value)) {
        throw new Error(`"${text.trim()}" does not come to a number`);
    }

    return value;
};
