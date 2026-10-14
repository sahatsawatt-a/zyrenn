# Formulas

A **Formula** field works out a value for each row, the way a spreadsheet does. Name another field by its name, or in brackets by its label when the label has spaces in it:

```markdown
cost * nights
[Cost (THB)] / people
if(paid, 0, cost)
```

## Functions

| Function                                     | Gives                                               |
| -------------------------------------------- | --------------------------------------------------- |
| `if(test, yes, no)`                          | One of two values                                   |
| `sum`, `avg`, `min`, `max`, `count`          | Over the values given, or a whole column            |
| `round(number, places)`                      | Rounded, to so many places                          |
| `floor`, `ceil`, `abs`                       | Down, up, and without its sign                      |
| `coalesce(values…)`                          | The first that is not empty                         |
| `filter(list, tests)`                        | Only the items that pass                            |
| `number(text)`, `text(value, format)`        | One into the other                                  |
| `upper`, `lower`, `len`                      | Capitals, small letters, length                     |
| `starts(text, with)`, `contains(text, part)` | Whether it does                                     |
| `item(list, n)`                              | The nth item, counting from 1; from the end below 0 |
| `date("2026-12-03")`, `date(y, m, d)`        | A date                                              |
| `today()`                                    | Today                                               |
| `year`, `month`, `day`, `weekday`            | Parts of a date                                     |
| `days(from, to)`                             | The days between two dates                          |

Comparisons (`>`, `<`, `=`), `and`, `or` and `not` work as you would expect.

## Parameters

**Parameters**, above the table, are named values every formula in it can use: a rate, a tax, a date. Add one, such as `rate` with the value `5`, then write `cost * rate`. Change the parameter, and every row follows.

## Other tables and trips

A formula can reach another table, or a trip, by its title:

| Write                                     | Gives                                       |
| ----------------------------------------- | ------------------------------------------- |
| `sum(table("Budget").cost)`               | The Budget table's Cost column, added up    |
| `trip("Kyoto").day(day).cost`             | What this row's day of the Kyoto trip costs |
| `trip("Kyoto").total_cost`                | What the whole trip costs                   |
| `trip("Kyoto").stay("Hotel Gion").nights` | Nights at one hotel                         |

A note can show the same formulas in a sentence: see Notes, then Live values.

:::callout 💡
A formula that cannot be worked out for a row says what went wrong in its cell, such as a field it cannot find.
:::
