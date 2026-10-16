import { describe, expect, it } from 'vitest';
import type { BuildStep } from './build';
import { clicksOf, groupsOf, hiddenAfter, lengthOf, stepFor } from './build';

const step = (
    item: string,
    kind: BuildStep['kind'],
    start: BuildStep['start'],
    duration = 500,
    delay = 0,
): BuildStep => ({ item, kind, effect: 'fade', start, duration, delay });

describe("a slide's animations", () => {
    it('plays steps before the first click as the slide comes up', () => {
        const build = [
            step('title', 'in', 'with'),
            step('a', 'in', 'click'),
            step('b', 'in', 'click'),
        ];

        expect(
            groupsOf(build).map((group) => group.map((t) => t.step.item)),
        ).toEqual([['title'], ['a'], ['b']]);
        expect(clicksOf(build)).toBe(2);
    });

    it('times "with" from the step before it starts and "after" from when it ends', () => {
        const [, group] = groupsOf([
            step('a', 'in', 'click', 500),
            step('b', 'in', 'with', 300, 100),
            step('c', 'in', 'after', 400),
        ]);

        expect(group.map((t) => t.at)).toEqual([0, 100, 400]);
        expect(lengthOf(group)).toBe(800);
    });

    it('hides an item until it comes in, and after it goes out', () => {
        const build = [
            step('a', 'in', 'click'),
            step('b', 'emphasis', 'click'),
            step('a', 'out', 'click'),
        ];

        expect([...hiddenAfter(build, 0)]).toEqual(['a']);
        expect([...hiddenAfter(build, 2)]).toEqual([]);
        expect([...hiddenAfter(build, 3)]).toEqual([]);
        expect([...hiddenAfter(build, 4)]).toEqual(['a']);
        // Emphasis never hides: b shows from the start
        expect(hiddenAfter(build, 0).has('b')).toBe(false);
    });

    it('starts a new step on a click, with an effect that comes from a side when it has one', () => {
        expect(stepFor('a', 'in')).toMatchObject({
            effect: 'fade',
            start: 'click',
            duration: 500,
        });
        expect(stepFor('a', 'out')).toMatchObject({ effect: 'fade' });
        expect(stepFor('a', 'in').from).toBeUndefined();
    });
});
