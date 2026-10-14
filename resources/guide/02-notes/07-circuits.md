# Circuits

**/circuit** draws a schematic from a few lines of text. Write the parts, then the wires between them; the note lays it out and draws it.

## Parts and wires

```markdown
V1: 9V
R1: 330
D1: led red
V1 -- R1 -- D1 -- V1
```

```circuit
V1: 9V
R1: 330
D1: led red
V1 -- R1 -- D1 -- V1
```

A part is a name and a value, one to a line. The first letter of the name says what it is:

| Name | Part          | Name | Part           |
| ---- | ------------- | ---- | -------------- |
| R    | Resistor      | V    | Voltage source |
| C    | Capacitor     | BT   | Battery        |
| L    | Inductor      | SW   | Switch         |
| D    | Diode or LED  | PB   | Push button    |
| Q    | Transistor    | F    | Fuse           |
| U    | Chip or board | M    | Motor          |

Say what it is yourself where the name does not: `D1: led red`, `Q1: npn BC547`, `R1: 10k`.

A wire joins ends with `--`. An end is a pin (`Q1.b`), a part to run through from its first pin to its second, or a net's name such as `gnd`, `3V3` or `sda`. Everything joined to the same net's name is joined together.

## Boards, sensors and chips

Boards and sensors come with their pins named:

```circuit
U1: esp32
S1: dht22
R1: 220
D1: led green
U1.3V3 -- 3V3
S1.VCC -- 3V3
U1.GPIO4 -- S1.DATA
U1.GPIO2 -- R1 -- D1 -- gnd
S1.GND -- gnd
U1.GND -- gnd
```

For a chip of your own, list its pins, those down its left, then after a `|` those down its right: `U2: chip 74HC595 [SER, CLK | QA, QB]`.

## Voltages and currents

**Values**, on a circuit's bar, works it out and writes each wire's voltage and each part's current on it. A line that says `values` shows them from the start. Either way, a part driven past what it can take is marked: give a rating after its value, as in `R1: 100 1W` or `D1: led red 30mA`; otherwise a resistor is taken to manage 0.25 W and an LED 20 mA.

```circuit
values
V1: 5V
R1: 220
D1: led red
V1 -- R1 -- D1 -- V1
```

`values hidden` works them out but keeps them back until **Values** is clicked, so that a reader can have a guess first.

## Logic

Logic gates take ins and outs by name: `A: input`, `U1: and`, `Y: output`, then `A -- U1.a` and `U1.y -- Y`. On a board, a logic circuit is live: click a switch to flip it, or a clock to start it, and the lamps show what follows. See Boards.
