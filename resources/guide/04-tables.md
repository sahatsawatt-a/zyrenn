# Tables

A table is rows and columns, like a spreadsheet, where each column (a **field**) holds one kind of value: text, a number, a date, a choice from a list, a place on the map.

<video src="/images/guide/tour-table.webm" title="Show me: Start a table"></video>

![A budget table, with a sum under its Cost column](/images/guide/tables.png)

## In short

- Press **New table** and give it a name. Add fields with **Field**, and rows with **Row**.
- A **Formula** field works out a value for each row; **Parameters** are values all of them share.
- A number field can show its sum, average, smallest, largest or count under the column.
- **Search rows**, **Filter** and **Sort** find what you need; **Export** downloads a CSV.
- A table with a **Location** field can be seen as a map.
- In a project, everyone sees each other's changes as they are made.

| To learn about                     | Read                                                   |
| ---------------------------------- | ------------------------------------------------------ |
| Every kind of field, and rows      | [Fields](/guide/tables/fields)                         |
| Formulas, functions and parameters | [Formulas](/guide/tables/formulas)                     |
| Search, filter, sort and export    | [Search, filter and sort](/guide/tables/find-and-sort) |
| Pins on a map                      | [See a table on a map](/guide/tables/map-view)         |

:::callout 💡
A note can show a number from a table that stays up to date: type **/value** in the note and write it as a formula, such as `sum(table("Budget").cost)`.
:::
