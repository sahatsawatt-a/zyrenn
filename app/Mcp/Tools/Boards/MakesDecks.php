<?php

namespace App\Mcp\Tools\Boards;

use App\Support\Board\BoardItems;
use App\Support\Board\Deck;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * A board's frames made into a deck, as the board tools take it: the deck's
 * look and what is written on every slide, and slides laid out for you.
 */
trait MakesDecks
{
    /**
     * Schema for the deck: a theme, what is said over it, and what every
     * slide has written on it.
     */
    protected function deckArgument(JsonSchema $schema): Type
    {
        $themes = collect(Deck::themes())
            ->map(fn (array $theme) => "{$theme['id']} ({$theme['background']} with {$theme['ink']} words, {$theme['accent']} accent, {$theme['headingFont']} titles)")
            ->implode(', ');

        return $schema->object([
            'theme' => $schema->string()->enum(Deck::themeIds())->description("The slides' look: {$themes}."),
            'background' => $schema->string()->max(32)->description('Each slide\'s colour, as hex, over the theme\'s.'),
            'ink' => $schema->string()->max(32)->description('The colour words are written in.'),
            'muted' => $schema->string()->max(32)->description('The colour of the header, the footer, the number and a subtitle.'),
            'accent' => $schema->string()->max(32)->description('The colour of a kicker and a big number.'),
            'font' => $schema->string()->enum(BoardItems::FONTS)->description('The typeface words are set in.'),
            'headingFont' => $schema->string()->enum(BoardItems::FONTS)->description('The typeface titles are set in.'),
            'header' => $schema->string()->max(200)->description('Written small across the top of every slide. {n} is its number, {count} how many, {section} the part of the deck it is in (from the last frame with a "section"), {title} the board\'s title, {slide} the slide\'s own. "" for none.'),
            'footer' => $schema->string()->max(200)->description('Written small across the foot of every slide, as the header: a source, "{title} · {section}".'),
            'numbers' => $schema->boolean()->description('true: each slide\'s number in its bottom right corner.'),
        ])->description('The deck the frames make: one look, and what is written on every slide (not on a frame with "bare": true, a title slide). Only what is sent changes; a field sent as "" or null is taken away, and "deck": null takes the deck away. Changing it changes no slide already drawn -- lay new ones out with slides.');
    }

    /**
     * Schema for slides laid out for you, each from a layout.
     */
    protected function slidesArgument(JsonSchema $schema, string $description): Type
    {
        $slot = fn (string $about) => $schema->string()->max(5000)->description($about);

        return $schema->array()->max(Deck::MAX_SLIDES)->items($schema->object([
            'id' => $schema->string()->max(48)->description('Your name for the slide\'s frame ("intro"). Its parts are named after it and their slot: "intro-title", "intro-body" -- to change with update_items. Made up when left out ("slide1").'),
            'layout' => $schema->string()->enum(Deck::layoutIds())->description('How it is laid out: '.Deck::described().'. "title-body" when left out.'),
            'kicker' => $slot('A short line above a title, in capitals and the accent: "Results", "Part 2".'),
            'title' => $slot('What the slide says, as a sentence where it can be -- "Sign-ups doubled after the redesign", not "Sign-ups". Also names the frame.'),
            'subtitle' => $slot('A line under a title slide\'s title.'),
            'body' => $slot('Words or bullets, as light Markdown: "- one\n- two", **bold**.'),
            'left' => $slot('The left column, as body.'),
            'right' => $slot('The right column, as body.'),
            'picture' => $schema->string()->max(200)->description('For "picture-right": the picture\'s address -- upload it first (request-upload or upload-file) and pass its "url".'),
            'number' => $slot('For "big-number": the figure, "42%", "3x".'),
            'caption' => $slot('What the number means.'),
            'quote' => $slot('For "quote": the words said.'),
            'by' => $slot('Who said them: "-- A customer".'),
            'notes' => $schema->string()->max(10000)->description('Speaker notes, shown to the presenter alone.'),
            'section' => $schema->string()->max(120)->description('The part of the deck this slide begins, for a {section} in the header or footer. A "section" slide begins one with its title.'),
            'bare' => $schema->boolean()->description('true: no header, footer or number on it. A "title" slide has none already.'),
            'src' => $schema->string()->max(200)->description('A picture filling the slide behind everything on it, by its address as "picture".'),
        ]))->description($description);
    }

    /**
     * Validation rules for the deck and the slides, keyed as sent.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function deckRules(string $slides = 'slides'): array
    {
        $picture = ['nullable', 'string', 'max:200', 'regex:#^(https?://|/drive/files/)#'];

        return [
            ...Deck::rules(),
            $slides => ['sometimes', 'array', 'max:'.Deck::MAX_SLIDES],
            "{$slides}.*" => ['array'],
            "{$slides}.*.id" => ['nullable', 'string', 'max:48', 'regex:/^[A-Za-z0-9_-]+$/'],
            "{$slides}.*.layout" => ['nullable', 'string', 'in:'.implode(',', Deck::layoutIds())],
            ...collect(Deck::SLOTS)
                ->reject(fn (string $slot) => $slot === 'picture')
                ->mapWithKeys(fn (string $slot) => ["{$slides}.*.{$slot}" => ['nullable', 'string', 'max:5000']])
                ->all(),
            "{$slides}.*.picture" => $picture,
            "{$slides}.*.src" => $picture,
            "{$slides}.*.notes" => ['nullable', 'string', 'max:10000'],
            "{$slides}.*.section" => ['nullable', 'string', 'max:120'],
            "{$slides}.*.bare" => ['nullable', 'boolean'],
        ];
    }

    /**
     * The slides sent, in the order they were sent.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    protected function slides(array $validated, string $key): array
    {
        $slides = is_array($validated[$key] ?? null) ? $validated[$key] : [];
        ksort($slides);

        /** @var list<array<string, mixed>> */
        return array_values($slides);
    }
}
