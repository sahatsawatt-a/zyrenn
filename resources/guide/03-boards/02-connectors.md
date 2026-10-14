# Connectors

A connector is a line from one thing to another. Pick the connector (**A**) and drag from one shape to the next. Its ends stay joined to both shapes: move either, resize it, and the line follows.

## Its kind

Select a connector to choose what kind of line it is. Each sets both ends and the dash together:

| Kind                       | For                                 |
| -------------------------- | ----------------------------------- |
| Arrow, Line, Both ways     | Ordinary diagrams                   |
| Dashed arrow               | Something that happens now and then |
| Uses, Depends on           | Software: one part needs another    |
| Is a kind of, Implements   | Classes: inheritance and interfaces |
| Has, Is made of            | Classes: one holds another          |
| One to one, One to many, … | Data models, in crow's-foot         |

Each end can also be set on its own: none, an arrow, an open arrow, a hollow arrow, a circle, a ring, a diamond, a bar, or the crow's-foot ends (one and only one, many, one or many, zero or one, zero or many).

## How it runs

**Routing** chooses the way between its ends: straight, in right angles, or curved. **Auto** keeps each end facing whatever is at the other; choose a side to keep it there.

Where two connectors cross, one hops over the other, so it is clear which goes where.

## Words on a line

Double-click a connector to write on it, such as "yes" and "no" out of a decision.

:::callout 💡
A connector can join one row of a table or class to another, such as a foreign key to the column it points at. See Diagrams.
:::
