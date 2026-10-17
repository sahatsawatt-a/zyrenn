// What a prototype's items and screens do in Play beyond going to a screen:
// overlays, how each move looks, more than a tap, scrolling screens, toggles
// and showing or hiding, typing into fields, and simple conditions. Kept on
// the board's items and frames, so they are drawn, copied and kept live like
// everything else on it; the server checks the same shapes
// (App\Support\Board\BoardItems). Phase 2 of prototypes: the editor sets
// these, Play does them.

/** How a move looks. Left out: 'slide' (on, or back by direction). */
export type Transition = 'slide' | 'push' | 'fade' | 'up' | 'none';

/** Where a screen opened over the current one sits. */
export type OverlayPlace = 'center' | 'bottom' | 'top' | 'left' | 'right';

/** What sets an action off, besides a tap (which is `link`). */
export type Trigger =
    | 'long-press'
    | 'hover'
    | 'swipe-left'
    | 'swipe-right'
    | 'swipe-up'
    | 'swipe-down';

/** A value Play keeps while it is played: typed into a field, or set. */
export type Value = string | boolean;

/**
 * One thing that happens. The first two are the phase 1 link, with how the
 * move looks; `overlay` opens the screen over the current one instead, which
 * stays dimmed behind it until a `close` (or a tap on the dim).
 */
export type Action =
    | { to: string; transition?: Transition; overlay?: OverlayPlace }
    | { back: true; transition?: Transition }
    // The overlay on top goes; with none open, nothing
    | { close: true }
    // Items on the same screen, by id: flipped, shown or hidden
    | { toggle: string[] }
    | { show: string[] }
    | { hide: string[] }
    // A named value: a condition's yes/no, or text
    | { set: string; value: Value }
    // By a value: `equals` left out, whether it is set and not false or ''
    | { if: string; equals?: Value; then: Action; else?: Action }
    // Several, in order (a move, if any, last)
    | { all: Action[] }
    // A project table as the prototype's database (phase 3): a row added
    // ('new', the default) or the current row changed, its columns from
    // `values` -- column label => words that may say {name} -- and, for the
    // columns left out, the fields named like them on the screen it is done
    // from (and an overlay open over it): not ones typed into on other
    // screens, which would change columns the form never showed. The row
    // saved becomes the current one.
    | { save: string; row?: 'new' | 'current'; values?: Record<string, string> }
    // The table's current row deleted; there is none current after
    | { remove: string };

/** An item's actions for triggers other than a tap, by trigger. */
export type ItemOn = Partial<Record<Trigger, Action>>;

/**
 * What a screen (a frame) does by itself. Taller than its device, a screen
 * scrolls inside it; its items with `fixed` stay put while it does.
 */
export type ScreenSettings = {
    // Moves on by itself, e.g. a splash screen: after so many seconds (0.5-30)
    after?: { seconds: number; do: Action } | null;
};

/**
 * What an item does in Play, as kept on it -- all optional, beside `link`
 * (the tap, now any Action):
 * - `when`: ItemOn, other triggers (not `on`, which every item has: the
 *   on/off a kit switch, checkbox, radio or tab draws and Play flips);
 * - `fixed`: 'top' | 'bottom' -- a header or tab bar that stays put while
 *   its screen scrolls;
 * - `startsHidden`: true -- not shown until a show or toggle;
 * - `field`: a name -- a UI kit text field typed into in Play, what is typed
 *   kept under that name. Any item's words may say {name} to show it.
 * A UI kit switch, checkbox, radio or tab with no link flips its own `on`
 * when tapped; radios and tabs with the same `choice` are one choice (not
 * `group`, which is the board's Group/Ungroup).
 * And on a frame: `screen`: ScreenSettings.
 */
/**
 * A row card drawn once, shown for each row of a project table (phase 3).
 * Set on a container -- a rectangle or a kit card: everything lying inside
 * its box on the same screen is the row, repeated `gap` apart down (or
 * across) for each row; its words' {Column} -- a column's label, any case --
 * are that row's. Tapping inside one makes that row its table's current row
 * before the tap's action runs, so the next screen's {Column} shows it.
 * `table` is the table's ref_id, in the prototype's own project.
 */
export type Repeat = {
    table: string;
    // Only rows whose column is (words, which may say {name}); all must hold
    where?: { column: string; is: string }[];
    sort?: { column: string; desc?: boolean } | null;
    // At most so many rows (1-200); left out, 50
    limit?: number;
    gap?: number;
    across?: boolean;
};

/**
 * Where words get {name} from, in order: inside a row card, the row it shows
 * (else every card would show what a form named like a column just had
 * typed); a value typed or set; {Table.Column}, a table's current row; the
 * latest table's current row, then any other's; nothing ('' -- the braces
 * are not shown).
 */

export type ItemInteractions = {
    when?: ItemOn | null;
    repeat?: Repeat | null;
    fixed?: 'top' | 'bottom' | null;
    startsHidden?: boolean;
    field?: string | null;
    choice?: string | null;
};
