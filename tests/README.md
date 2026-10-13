# Writing tests that stay fast

The PHP suite runs in about 12 seconds and the browser suites in a few minutes.
Both got there by taking waiting out, and one careless test puts it back. These
are the rules for adding to either.

## PHP (`tests/Feature`, `tests/Unit`)

- **Run queued jobs with `$this->work()`**, from `Tests\TestCase`. Never call
  `artisan('queue:work')` in a test: a worker that finds the queue empty sleeps
  three seconds before it stops, and that was 92% of the suite's time.
- **Never wait on the clock.** A job due later is reached with `travel()`. No
  `sleep()`, no `usleep()`.
- **Never reach the network.** Fake every call with `Http::fake()`. Hostnames
  are not looked up either: `TestCase` answers for them, so `RemoteDownload`
  sees any name as a public address. Use `93.184.215.14` where a test needs a
  public site, and an address like `10.0.0.5` for one it must refuse.
- **Expect to run beside other tests.** `composer test` runs in parallel. Keep
  files on the faked disk, keep nothing in static state from one test to the
  next, and do not rely on the order tests run in.
- **Share a helper, do not copy it.** One used by a second test class goes in
  `tests/TestCase.php`.
- **Look at the time.** `php artisan test --parallel --profile` lists the
  slowest tests. One that takes over a second is waiting on something: find
  out what.

## Browser (`tests/e2e`)

Everything below is in `tests/e2e/harness.mjs`.

- **Wait for the thing, not for a while.** No `waitForTimeout` for something
  that arrives when it arrives. Use:
    - `soon(label, read, detail)`: a check that asks `read` until it is true.
    - `until(read)`: the same, for a value the suite goes on to use.
    - `steady(read)`: for something still finding its size or place, before a
      click that goes by where it is.
    - a locator's own `waitFor()`, for an element.
- **A fixed wait is only for proving nothing happens** (a menu that must not
  open, a change that must not be undone), and says so in a comment.
- **Never `networkidle`.** Open a page with `open(page, url)` and reload it with
  `reopen(page)`, then wait for the element the next step uses.
- **Two kinds of "saved".** `saved(page)` waits for the page to say so, which
  is enough before leaving or reloading. What the app itself has kept comes a
  couple of seconds later for a note or board the collaboration server keeps,
  so anything read from the app (`boardKept()`, MCP, `tinker()`, a board shown
  in a note) is asked with `until()`.
- **A slash command is `slash(page, 'board')`**, which waits for the menu.
- **One menu at a time.** Wait for one to be gone before opening the next.
- **Set up behind the scenes what the suite is not about.** `makeNote()`,
  `shareProject()` and `tinker()` take a fraction of a second; the same through
  the screens takes several. Go through the screens only for what is under
  test.
- **Keep a suite short.** A run can never finish sooner than its longest suite.
  A new subject gets a file of its own rather than a new section in a long
  one; aim for under 30 seconds.
- **Lanes share nothing.** Take accounts from `E2E_EMAIL` and `E2E_MEMBER`, name
  what you make after the run (`Date.now()`), and take it away again with
  `afterwards()`.
- **See where the time goes.** `E2E_TIMES=1 node tests/e2e/run.mjs <suite>`
  prints the slowest stretches. Run a new or changed suite three times before
  calling it done: a wait that is too short shows up as one failure in three.
