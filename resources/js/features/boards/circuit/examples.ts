// Circuits to start from: each a few lines of text, chosen in a note's
// circuit block or when putting a circuit on a board.
export type CircuitExample = { id: string; label: string; source: string };

export const circuitExamples: CircuitExample[] = [
    {
        id: 'led',
        label: 'LED and resistor',
        source: `V1: 9V
R1: 330
D1: led red
V1 -- R1 -- D1 -- V1`,
    },
    {
        id: 'divider',
        label: 'Voltage divider',
        source: `V1: 12V
R1: 10k
R2: 4k7
OUT: terminal
V1 -- R1 -- out
out -- R2 -- gnd
out -- OUT
V1.- -- gnd`,
    },
    {
        id: 'filter',
        label: 'RC low-pass filter',
        source: `IN: terminal
OUT: terminal
R1: 1k
C1: 100n
IN -- R1 -- out
out -- C1 -- gnd
out -- OUT`,
    },
    {
        id: 'amplifier',
        label: 'Transistor amplifier',
        source: `IN: terminal
OUT: terminal
Q1: npn BC547
C1: 10u
C2: 10u
R1: 47k
R2: 10k
RC: resistor 4k7
RE: resistor 1k
IN -- C1 -- Q1.b
vcc -- R1 -- Q1.b
Q1.b -- R2 -- gnd
vcc -- RC -- Q1.c
Q1.e -- RE -- gnd
Q1.c -- C2 -- OUT`,
    },
    {
        id: 'opamp',
        label: 'Inverting op-amp',
        source: `IN: terminal
OUT: terminal
U1: opamp LM358
R1: 10k
RF: resistor 100k
IN -- R1 -- U1.-
U1.- -- RF -- U1.out
U1.+ -- gnd
U1.out -- OUT`,
    },
    {
        id: 'esp32',
        label: 'ESP32 with a sensor',
        source: `U1: esp32
S1: dht22
R1: 220
D1: led green
U1.3V3 -- 3V3
S1.VCC -- 3V3
U1.GPIO4 -- S1.DATA
U1.GPIO2 -- R1 -- D1 -- gnd
S1.GND -- gnd
U1.GND -- gnd`,
    },
    {
        id: 'adder',
        label: 'Logic: half adder',
        source: `A: input
B: input
U1: xor
U2: and
S: output
C: output
A -- U1.a
B -- U1.b
A -- U2.a
B -- U2.b
U1.y -- S
U2.y -- C`,
    },
    {
        id: 'motor',
        label: 'Motor starter',
        source: `V1: ac 230V
CB1: breaker 16A
F1: fuse 10A
SW1: switch
M1: motor
V1 -- CB1 -- F1 -- SW1 -- M1 -- V1`,
    },
];

export const defaultCircuit = circuitExamples[0];

/** The example a block still holds untouched, if it is one. */
export const exampleFor = (source: string): CircuitExample | undefined =>
    circuitExamples.find((example) => example.source.trim() === source.trim());
