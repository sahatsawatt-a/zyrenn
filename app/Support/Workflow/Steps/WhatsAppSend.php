<?php

namespace App\Support\Workflow\Steps;

/**
 * Send on WhatsApp: words from a business number to someone who wrote to it
 * in the last 24 hours, with up to three buttons under them when the step
 * names any. A press comes back to a "WhatsApp message" trigger set to
 * buttons. Recorded and never simply repeated (WhatsAppApi::send).
 */
final class WhatsAppSend implements Step
{
    private const MAX_LETTERS = 4096;

    private const MAX_BUTTONS = 3;

    private const MAX_BUTTON_LETTERS = 20;

    public function run(StepContext $step): StepResult
    {
        $number = WhatsAppApi::number($step);
        $to = WhatsAppApi::to($step);
        $text = MetaApi::needs($step, 'text', 'message: it came to nothing');
        $buttons = self::buttons($step);

        if (mb_strlen($text) > ($buttons === [] ? self::MAX_LETTERS : 1024)) {
            throw new StepFailed('A WhatsApp message is at most '.number_format($buttons === [] ? self::MAX_LETTERS : 1024).' letters'.($buttons === [] ? '' : ' with buttons under it').', and this is '.number_format(mb_strlen($text)).'.');
        }

        return new StepResult(WhatsAppApi::send($step, $number, $to, $buttons === []
            ? ['type' => 'text', 'text' => ['body' => $text, 'preview_url' => true]]
            : ['type' => 'interactive', 'interactive' => ['type' => 'button', 'body' => ['text' => $text], 'action' => ['buttons' => $buttons]]]));
    }

    /**
     * Buttons from a step's named values: each name is what the button
     * says, its value what a press sends back -- the name itself when there
     * is none.
     *
     * @return list<array{type: string, reply: array{id: string, title: string}}>
     *
     * @throws StepFailed
     */
    private static function buttons(StepContext $step): array
    {
        $buttons = [];

        foreach ((array) $step->setting('buttons') as $field) {
            $label = trim((string) ($field['name'] ?? ''));

            if ($label === '') {
                continue;
            }

            if (mb_strlen($label) > self::MAX_BUTTON_LETTERS) {
                throw new StepFailed('A WhatsApp button says at most '.self::MAX_BUTTON_LETTERS." letters, and \"{$label}\" is longer.");
            }

            $back = trim($step->text("buttons.{$label}", (string) ($field['value'] ?? '')));
            $buttons[] = ['type' => 'reply', 'reply' => ['id' => mb_substr($back !== '' ? $back : $label, 0, 256), 'title' => $label]];
        }

        return count($buttons) <= self::MAX_BUTTONS
            ? $buttons
            : throw new StepFailed('WhatsApp shows at most '.self::MAX_BUTTONS.' buttons under a message, and this names '.count($buttons).'.');
    }
}
