# Using what came before

Each step can read what the steps before it gave out.

## In a setting

Write a value between double braces where it should go:

| Write                       | Gives                                          |
| --------------------------- | ---------------------------------------------- |
| `{{ trigger.text }}`        | What the first step was given: here, a message |
| `{{ input.name }}`          | A value from the step just before              |
| `{{ node("lookup").rows }}` | A value from any earlier step, by its key      |

So with the test data `{"customer": "Ada", "total": 120}`, a message step might say `Thanks {{ trigger.customer }}, your order of {{ trigger.total }} is in.`

## In a formula

**If**, **Switch** and **Set data** take formulas, with no braces: `trigger.total > 100`, `len(trigger.files) > 0`, `upper(input.name)`. They are the formulas tables use: see Tables, then Formulas.

## Test data

**Test data**, beside a step's settings, shows what that step was given and gave out the last time it ran, as a card for each step with its parts marked by kind. Click a part to put it into the setting you are writing, so nothing has to be typed by hand.

Run the workflow once with **Run** to have test data to work from.
