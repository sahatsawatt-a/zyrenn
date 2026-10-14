# Diagrams

A board is for any diagram: a flowchart, an org chart, a data model, how a system fits together. The shapes for each kind are there, and a template starts one for you.

## Start from a template

**Templates**, at the top of the board, puts one down as shapes of your own, ready to change:

| Template      | Shows                                                         |
| ------------- | ------------------------------------------------------------- |
| Flowchart     | Steps and a decision, from a start to an end                  |
| Swimlanes     | Who does what, in a lane each, with the work crossing between |
| Org chart     | Who reports to whom, from the top down                        |
| Mind map      | One idea in the middle, and what comes off it                 |
| Sequence      | Who asks whom for what, in the order it happens               |
| Data model    | Tables with their columns and keys                            |
| Class diagram | Classes with their fields and methods                         |
| Architecture  | What a system is made of and what talks to what               |
| Roadmap       | What is being done now, what comes next, and what can wait    |
| Retro         | What went well, what to improve, and what to do about it      |

## Tables and classes with rows

The **Table** and **Class** shapes, under **Software**, hold real rows: a table's columns with their types and keys, a class's fields and methods. Select one to add, remove and reorder its rows in the panel, or write the whole list as text, a line to each.

A connector can start and end on a row, so a foreign key is drawn to the very column it points at, and it stays on that row as rows are added.

**Tables as SQL**, under **More**, shows the tables on the board as `CREATE TABLE` statements for PostgreSQL, MySQL or SQLite, or as a Mermaid diagram for a note, ready to copy.

## Who talks to whom, in order

A **Lifeline** is a participant with a line down from it. Connectors between lifelines are messages, and stay in their place along the lines as the lifelines move.

## Architecture

Users, browsers, phones, servers, queues, buckets, gateways, firewalls and the internet, under **Architecture**, are drawn as themselves, so a picture of a system reads at a glance.
