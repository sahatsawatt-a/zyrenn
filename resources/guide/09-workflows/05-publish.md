# Publish and switch on

A workflow is made as a **draft**. The editor keeps it as you go, every move and every change, but the draft never runs by itself.

1. **Publish** freezes the draft as a numbered version. Until then the workflow says **Not published**.
2. **Switch it on**, with the switch beside Publish, and its first step starts runs by itself: on its schedule, when a message comes, when its webhook is called.

Change the workflow afterwards and the change stays in the draft until it is published again; what is switched on is always what was published.

Runs already going keep the version they started with, so publishing never changes a run under way.

:::callout ⚠️
Steps that send messages, post, or change tables do so for real when they run, even from **Run**. Try a new workflow on a test chat or a test table first.
:::

## Two people at once

If someone else changes a workflow you have open, it says so: keep yours, or take theirs.
