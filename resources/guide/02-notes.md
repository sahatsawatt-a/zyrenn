# Notes

A note is a page made of blocks: a paragraph, a heading, a list, a table, a picture. Write as you would anywhere; reach for a block when you need more than text.

## Add a block

Type **/** on an empty line and pick from the menu, or keep typing to narrow it.

![The menu of blocks that opens when you type a slash](/images/guide/notes-menu.png)

| Type      | To get                                               |
| --------- | ---------------------------------------------------- |
| /heading  | A heading, in three sizes                            |
| /to-do    | A list with boxes to tick                            |
| /callout  | A coloured box for something worth noticing          |
| /table    | A small table inside the note                        |
| /image    | A picture: uploaded, from your Drive, or by its link |
| /code     | Code, coloured by its language                       |
| /equation | A formula written as LaTeX                           |
| /mermaid  | A diagram drawn from a few lines of text             |
| /circuit  | A circuit drawn from its parts and wires, as text    |

## Draw a circuit

**/circuit** draws a schematic from a few lines. Write each part on a line of its own, then the wires between them:

```circuit
V1: 9V
R1: 330
D1: led red
V1 -- R1 -- D1 -- V1
```

A part is a name and a value. The name says what it is: R1 a resistor, C1 a capacitor, D1 a diode, Q1 a transistor. Say it yourself where the name does not, as in `D1: led red`. A wire joins ends with `--`: a pin (`Q1.b`), a part to run through, or a net's name such as `gnd`, `3V3` or `out`.

Boards and sensors have their pins by name: `U1: esp32`, `S1: dht22`, then `U1.GPIO4 -- S1.DATA`. For a chip of your own, list its pins: `U2: chip 74HC595 [SER, CLK | QA, QB]`.

Logic works the same way: `A: input`, `U1: and`, `Y: output`, then `A -- U1.a` and `U1.y -- Y`.

On a board the same parts are in the shapes menu, under **Electrical**. A logic circuit there is live: click a switch to flip it or a clock to start it, and the wires and lamps show what follows. Draw a connector from one pin to another and it becomes a wire; **Circuit**, at the top of the board, lists the parts and what is left unconnected.

## Show other things in a note

A note can hold what lives elsewhere, and it stays up to date:

- **/board** shows a board, or one of its frames.
- **/trip** shows a trip, or one of its days.
- **/map** shows a map.
- **/value** shows a number from a trip or a table.

## Link to another page

Type **[[** and the first letters of a page's name, then pick it from the list. The link shows the page's name and follows it when the page is renamed. It can point at a note, a board, a table or a trip.

A note lists the notes that link to it at the bottom, under **Linked from**.

## Move and change blocks

Hover a block and use the handle to its left: drag it to move the block, or open it for the block's menu.

## History and PDF

The bar above the note says whether it is saved and who else has it open. From there you can go back to an earlier version, or export the note as a PDF. The **⋯** menu holds the rest: the note's width, its links, its folder, and deleting it.

:::callout ✏️
In a project, everyone with the note open writes in the same copy. You see their cursors as they type.
:::
