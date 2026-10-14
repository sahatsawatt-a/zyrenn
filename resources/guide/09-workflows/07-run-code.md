# Run code

**Run code** runs JavaScript you write, on what came before, and gives out what it returns. It is for working something out that a formula cannot say easily.

```javascript
// trigger, input and node("key") are what formulas read.
const words = String(trigger.text ?? '')
    .trim()
    .split(/\s+/);

return {
    words: words.length,
    first: words[0] ?? '',
};
```

- Write the body of a function. A record it returns is given out as it is; anything else is given out as `value`.
- `console.log` writes in the run's log.
- It can only work things out: it reaches no network and no files.
- **Stop it after, in seconds** ends it if it runs too long: at most 30.

To call a service, use **HTTP request**; to keep a file, **Save to Drive**.
