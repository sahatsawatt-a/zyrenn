import type { DiagramTemplate } from '@/features/notes/lib/diagramKind';

// Graphs to start from, in the order they are offered
export const plotExamples: DiagramTemplate[] = [
    {
        id: 'line',
        label: 'Straight line',
        source: [
            'title: A straight line',
            'x = -5 to 5',
            'm = 1 from -3 to 3',
            'c = 0 from -5 to 5',
            'y(x) = m * x + c',
        ].join('\n'),
    },
    {
        id: 'sine',
        label: 'Sine wave',
        source: [
            'title: A sine wave',
            'x: Time (s)',
            'y: Voltage (V), -6 to 6',
            't = 0 to 2',
            'A = 3 from 0 to 5',
            'f = 1 from 0.5 to 5',
            'v(t) = A * sin(2 * pi * f * t)',
        ].join('\n'),
    },
    {
        id: 'rc',
        label: 'Capacitor charging',
        source: [
            'title: A capacitor charging through a resistor',
            'x: Time (ms)',
            'y: Voltage (V), 0 to 6',
            't = 0 to 50',
            'R = 10 from 1 to 47',
            'C = 1 from 0.1 to 4.7',
            'Vs = 5',
            '# kΩ times µF is ms',
            'tau = R * C',
            'Vc(t) = Vs * (1 - exp(-t / tau))',
            'Vr(t) = Vs * exp(-t / tau)',
        ].join('\n'),
    },
    {
        id: 'divider',
        label: 'Voltage divider',
        source: [
            'title: What a divider gives as R2 grows',
            'x: R2 (kΩ)',
            'y: Vout (V), 0 to 5',
            'R2 = 0 to 100',
            'Vin = 5',
            'R1 = 10 from 1 to 100',
            'Vout(R2) = Vin * R2 / (R1 + R2)',
        ].join('\n'),
    },
    {
        id: 'ohm',
        label: "Ohm's law",
        source: [
            "title: Ohm's law",
            'x: Voltage (V)',
            'y: Current (mA), 0 to 30',
            'V = 0 to 12',
            'R = 1 from 0.47 to 10',
            '# R in kΩ, so the answer is in mA',
            'I(V) = V / R',
        ].join('\n'),
    },
];

export const defaultPlot = plotExamples[1];

export const plotExampleFor = (source: string): DiagramTemplate | undefined =>
    plotExamples.find((each) => each.source.trim() === source.trim());
