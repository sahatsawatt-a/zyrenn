# Live values

**/value** puts a number in a sentence that is worked out each time the note is read, from a table or a trip. It stays right as the table or trip changes.

A live value is a formula between double braces. Reach a table or a trip by its title:

| Write                                        | Gives                                     |
| -------------------------------------------- | ----------------------------------------- |
| `{{ sum(table("Budget").cost) }}`            | The sum of the Budget table's Cost column |
| `{{ count(table("Guests").name) }}`          | How many guests there are                 |
| `{{ trip("Kyoto").day(2).cost }}`            | What day 2 of the Kyoto trip costs        |
| `{{ trip("Kyoto").total_cost }}`             | What the whole trip costs                 |
| `{{ trip("Kyoto").nights }}`                 | How many nights it has                    |
| `{{ round(sum(table("Budget").cost) / 4) }}` | Each of four people's share               |

Click a live value to change its formula. One that cannot be worked out, such as a table that has been renamed, shows what went wrong in red.

Formulas here are the same as a table's formula fields: see Tables.
