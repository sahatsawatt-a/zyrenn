# Runs

**Run** runs the workflow from its **Run by hand** step, with its test data. Every run, however it started, is listed under **Runs**.

## Read a run

Open a run to go through it step by step. Each step shows what went into it beside what it gave out, and whether it worked.

![A run, with one step opened to show its input beside its output](/images/guide/workflows-run.png)

## When a run fails

Find the step that failed: its input is on one side and what went wrong on the other. Put the step right, then run again.

A step can be set to carry on when it fails, passing on what went wrong: see Steps.

## Runs that wait

A run on a **Delay** or waiting for an approval stays in the list until it is done. Publishing again does not change it: a run always finishes as the version it started with.
