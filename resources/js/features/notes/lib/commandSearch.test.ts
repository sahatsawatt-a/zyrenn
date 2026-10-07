import { describe, expect, it } from 'vitest';
import { commandItems } from '../config/commandsConfig';
import { searchCommands, squash } from './commandSearch';

const first = (query: string) => searchCommands(commandItems, query)[0]?.title;

describe('finding a block in the slash menu', () => {
    it('by the words people use for it', () => {
        expect(first('h1')).toBe('Heading 1');
        expect(first('h2')).toBe('Heading 2');
        expect(first('url')).toBe('Link');
        expect(first('link')).toBe('Link');
        expect(first('website')).toBe('Link');
        expect(first('ul')).toBe('Bullet List');
        expect(first('ol')).toBe('Numbered List');
        expect(first('img')).toBe('Image');
        expect(first('location')).toBe('Map');
        expect(first('checkbox')).toBe('To-do List');
    });

    it('as Markdown writes it', () => {
        expect(first('##')).toBe('Heading 2');
        expect(first('-')).toBe('Bullet List');
        expect(first('$$')).toBe('Equation');
    });

    it('with spaces, dashes and case not counting', () => {
        expect(squash('To-do List')).toBe('todolist');
        expect(first('heading1')).toBe('Heading 1');
        expect(first('TODO')).toBe('To-do List');
    });

    it('a name over a word that only mentions it', () => {
        // "map" is the Map block, not a trip that has a map in it
        expect(first('map')).toBe('Map');
        expect(first('table')).toBe('Table');
    });

    it('a letter off, once there is enough to tell', () => {
        expect(first('tabel')).toBe('Table');
        expect(first('vidoe')).toBe('Video');
        expect(searchCommands(commandItems, 'zzzz')).toEqual([]);
    });

    it('everything, before anything is typed', () => {
        expect(searchCommands(commandItems, '')).toHaveLength(
            commandItems.length,
        );
    });
});
