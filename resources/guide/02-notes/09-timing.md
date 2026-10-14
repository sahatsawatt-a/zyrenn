# Timing diagrams

**/timing** draws signals over time: a clock, a bus, a chip-select. Each signal is a line, written as its name and then a character for each step of time.

```markdown
title: Sending one byte
CLK: p.......
CS: 1.0.....1
MOSI: x=.=.=.x | D7 | D6 | D5
mark: 2 start, 8 stop
```

```timing
title: Sending one byte
CLK: p.......
CS: 1.0.....1
MOSI: x=.=.=.x | D7 | D6 | D5
mark: 2 start, 8 stop
```

| Write | For a step that is                                           |
| ----- | ------------------------------------------------------------ |
| `0`   | Low                                                          |
| `1`   | High                                                         |
| `.`   | The same as the step before                                  |
| `p`   | A clock pulse, starting high (`n` starts low)                |
| `x`   | Not known                                                    |
| `z`   | Let go of                                                    |
| `=`   | A value, named in order after a bar, `\|`, at the line's end |

`title:` and `mark:` are lines of their own: a mark draws a line down the diagram at a step, with its words.
