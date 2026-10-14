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
            'blank' => [
                'name' => 'New agent',
                'description' => 'No instructions yet: write your own.',
                'instructions' => '',
            ],
        ];
    }
}
