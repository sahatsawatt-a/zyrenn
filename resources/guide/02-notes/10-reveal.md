# Reveal

**/reveal** keeps what is inside it folded away until the reader clicks it: an answer to a question, a worked example, a spoiler. Anything can go inside, such as text, a picture, a formula or a circuit.

What is the current through a 220 Ω resistor across 5 V?

:::reveal Answer
$I = \dfrac{V}{R} = \dfrac{5}{220} \approx 22.7\ \text{mA}$
:::

In Markdown, a reveal starts with `:::reveal` and the words on its button, and ends with `:::` on a line of its own:

```markdown
:::reveal Answer
The words that stay hidden.
:::
```

:::callout 💡
A circuit can keep its worked-out values back in the same way: write `values hidden` in it. See Circuits.
:::
