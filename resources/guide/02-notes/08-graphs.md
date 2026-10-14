# Graphs

**/graph** draws curves from formulas. Give it what runs along the bottom, the values the formula uses, and a curve on each line. A value given a range gets a slider, so the reader can see what changing it does.

```markdown
t = 0 to 5
R = 1 from 0.2 to 5
C = 1
V(t) = 5 * (1 - exp(-t / (R * C)))
title: Charging a capacitor
x: Time (s)
y: Volts, 0 to 6
```

```plot
t = 0 to 5
R = 1 from 0.2 to 5
C = 1
V(t) = 5 * (1 - exp(-t / (R * C)))
title: Charging a capacitor
x: Time (s)
y: Volts, 0 to 6
```

| Line                  | Means                                   |
| --------------------- | --------------------------------------- |
| `t = 0 to 5`          | What runs along the bottom, and how far |
| `R = 1 from 0.2 to 5` | A value with a slider, starting at 1    |
| `C = 1`               | A value that stays                      |
| `V(t) = …`            | A curve. Write several, one to a line   |
| `title:`, `x:`, `y:`  | Its title and what its sides are called |
| `y: Volts, 0 to 6`    | A side's name, and the range it shows   |

The functions are `sin`, `cos`, `tan`, `atan`, `sqrt`, `abs`, `exp`, `ln` (natural), `log` (base 10), `floor`, `round`, `min`, `max` and `step` (0 below zero, 1 from it); `pi` and `e` are there too.

## A table's columns

A graph can draw a table's numbers instead: `table: Budget`, then `x:` with the column along the bottom and `y:` with the columns to draw, by name.
