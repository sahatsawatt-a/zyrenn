# Math

Formulas are written in LaTeX and drawn with KaTeX, either inside a line of text or as an equation of their own.

## In a line

Type **/inline math**, or write the formula between single dollar signs: `$E = mc^2$` gives $E = mc^2$, in the middle of a sentence.

A dollar sign followed by a digit stays text, so "$5 and $10" is not taken for a formula.

## An equation of its own

Type **/equation**, or put the formula on lines of its own between `$$`:

```markdown
$$
\int_0^\infty e^{-x^2}\,dx = \frac{\sqrt{\pi}}{2}
$$
```

$$
\int_0^\infty e^{-x^2}\,dx = \frac{\sqrt{\pi}}{2}
$$

Click an equation to change it. It is drawn again as you type.

## What can be written

Anything KaTeX knows. Some that come up often:

| Write                                          | To get                                         |
| ---------------------------------------------- | ---------------------------------------------- |
| `x^2`, `x_i`                                   | $x^2$, $x_i$                                   |
| `\frac{a}{b}`                                  | $\frac{a}{b}$                                  |
| `\sqrt{x}`, `\sqrt[3]{x}`                      | $\sqrt{x}$, $\sqrt[3]{x}$                      |
| `\sum_{i=1}^{n} i`                             | $\sum_{i=1}^{n} i$                             |
| `\alpha, \beta, \pi, \Omega`                   | $\alpha, \beta, \pi, \Omega$                   |
| `\le, \ge, \ne, \approx`                       | $\le, \ge, \ne, \approx$                       |
| `\vec{F} = m\vec{a}`                           | $\vec{F} = m\vec{a}$                           |
| `\begin{pmatrix} a & b \\ c & d \end{pmatrix}` | $\begin{pmatrix} a & b \\ c & d \end{pmatrix}$ |

Several lines lined up on their `=` go in an `aligned` block:

$$
\begin{aligned}
V &= I R \\
P &= V I = I^2 R
\end{aligned}
$$

:::callout 💡
A board has formulas too: the **Formula** tool (**E**) puts one anywhere on the canvas.
:::
