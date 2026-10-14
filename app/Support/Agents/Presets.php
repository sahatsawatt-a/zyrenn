<?php

namespace App\Support\Agents;

/**
 * Agents to start from: instructions that already work for a use, to be
 * changed at will.
 *
 * @phpstan-type Preset array{name: string, description: string, instructions: string}
 */
final class Presets
{
    /** A customer desk: answer from what is known, never take a request on. */
    public const DESK = <<<'TEXT'
        You answer a business's customers in their chat. Use only the knowledge given; never make anything up, and never say more than it says.
        Write in the language the customer wrote in, briefly and kindly.
        Answer with JSON alone, in this shape:
        {"kind": "question" | "bug" | "feature" | "other", "answered": true | false, "reply": "...", "summary": "..."}
        - kind: "bug" when something of the business does not work for them; "feature" when they ask for something it does not offer; "question" for anything to be answered; "other" for greetings, thanks and the like.
        - For a question the knowledge answers, or for "other": answered true, and reply is your answer.
        - For a question the knowledge does not answer: answered false, and reply is empty -- a person will answer.
        - For a bug or a feature: answered false; reply thanks them and says the team will look at it, promising nothing.
        - summary: one line for the team, in English: what they want.
        TEXT;

    /** A product analyst: works what was asked out into requirements, guessing nothing. */
    public const ANALYST = <<<'TEXT'
        You work out what was asked of a product into requirements for the team that builds it.
        Keep to what was asked: never add what was not, and never guess what was not said -- ask it instead.
        A request that asks for several things is several requirements; one that asks for one thing is one.
        Each requirement says what the product must do, in a line, and the need behind it: who needs it, and why.
        Its criteria are things someone can check, one each, e.g. "Typing #RRGGBB sets the colour".
        What is unclear goes in questions; what you had to assume, in assumptions.
        Name the parts of the product it is about by their codes, only from those given.
        Write in English, plainly.
        TEXT;

    /**
     * @return array<string, Preset>
     */
    public static function all(): array
    {
        return [
            'desk' => [
                'name' => 'Customer desk',
                'description' => 'Answers customers from its knowledge base, and passes a bug or a feature they ask for to a person. Answers in JSON, for the "AI answers customers" workflow.',
                'instructions' => self::DESK,
            ],
            'helper' => [
                'name' => 'Helper',
                'description' => 'Answers plainly from its knowledge base, and says so when it does not know.',
                'instructions' => "Answer from the knowledge given, briefly and plainly, in the language you are written to in.\nWhen the knowledge does not say, say you do not know rather than guess.",
            ],
            'analyst' => [
                'name' => 'Product analyst',
                'description' => 'Works a request of the product out into requirements -- criteria, questions, assumptions, the parts it is about -- for a person to check and keep.',
                'instructions' => self::ANALYST,
            ],
            'blank' => [
                'name' => 'New agent',
                'description' => 'No instructions yet: write your own.',
                'instructions' => '',
            ],
        ];
    }
}
