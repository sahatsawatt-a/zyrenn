# Circuits on a board

Open **Electrical** in **Shapes** for parts with pins: resistors, capacitors, LEDs, transistors, switches, op-amps, logic gates, and boards and sensors such as an ESP32 or a DHT22, each with its pins named.

## Wires

Draw a connector from one pin to another and it becomes a wire. Each part is numbered as it is put down: R1, R2, C1. **R** turns a selected part.

## Lay one out from text

**From text…**, at the top of **Electrical**, takes a circuit written the way a note's **/circuit** block is, and lays it out on the board:

```markdown
V1: 9V
R1: 330
D1: led red
V1 -- R1 -- D1 -- V1
```

See Notes, then Circuits, for everything the text can say.

## The Circuit panel

**Circuit**, at the top of the board, lists the parts and their values, what each wire joins, and anything left unconnected. **Copy as text** gives the circuit in the note's form; **Copy parts (CSV)** gives a parts list for a spreadsheet.

**Values** works the circuit out and writes each wire's voltage and each part's current beside it.

## Live logic

A logic circuit on a board runs. Click a switch to flip it, or a clock to start it ticking, and the wires and lamps show what follows. A switch that is on feeds the circuit a 1.
