# Code

**/code** gives a block for code, coloured by its language. Choose the language from the bar at its top; **Copy** copies the whole block.

```python
def total(rows):
    return sum(row["cost"] for row in rows)
```

In Markdown, a code block goes between lines of three backticks, with its language after the first:

````markdown
```sql
SELECT name FROM customers WHERE city = 'Kyoto';
```
````

A short piece of code in a sentence goes between single backticks: `npm run dev`.

:::callout 💡
Some languages are drawn instead of shown: `mermaid` for diagrams, `circuit` for schematics, `plot` for graphs and `timing` for signals. Each has a page of its own here.
:::
