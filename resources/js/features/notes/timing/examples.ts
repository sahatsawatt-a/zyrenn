// Timing diagrams to start from: each a few lines of text, chosen in a
// note's timing block.
export type TimingExample = { id: string; label: string; source: string };

export const timingExamples: TimingExample[] = [
    {
        id: 'clock',
        label: 'Clock and data',
        source: `title: Data read on the rising edge
CLK:  pppppppp
DATA: x=.=.=.x | A | B | C
EN:   01.....0`,
    },
    {
        id: 'spi',
        label: 'SPI byte',
        source: `title: Writing a byte over SPI
CS:   10.........1
SCK:  0.nnnnnnnn0.
MOSI: x.========x. | D7 | D6 | D5 | D4 | D3 | D2 | D1 | D0
MISO: z.x.......z.
mark: 2 first bit, 10 done`,
    },
    {
        id: 'i2c',
        label: 'I2C start and address',
        source: `title: I2C: start, address, acknowledge
SCL: 1.0pppppppp0
SDA: 10.=======x0 | A6 | A5 | A4 | A3 | A2 | A1 | A0
mark: 1 start, 10 ack`,
    },
    {
        id: 'uart',
        label: 'UART frame',
        source: `title: One UART byte, 8N1
TX: 1.0========1. | D0 | D1 | D2 | D3 | D4 | D5 | D6 | D7
mark: 2 start bit, 11 stop bit`,
    },
    {
        id: 'pwm',
        label: 'PWM duty cycle',
        source: `title: PWM at 25%, 50% and 75%
25%: 1000100010001000
50%: 1100110011001100
75%: 1110111011101110`,
    },
    {
        id: 'handshake',
        label: 'Request and acknowledge',
        source: `title: Four-phase handshake
REQ:  01...0....
ACK:  0.1...0...

DATA: x=....x... | value`,
    },
];

export const defaultTiming = timingExamples[0];

/** The example this text still is, if it has not been changed. */
export const timingExampleFor = (source: string): TimingExample | undefined =>
    timingExamples.find((example) => example.source.trim() === source.trim());
