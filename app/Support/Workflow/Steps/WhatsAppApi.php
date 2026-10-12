<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\Meta;

/**
 * What every WhatsApp step shares: the business number it speaks as, who it
 * speaks to, and a message sent by the Cloud API's one call for them.
 *
 * WhatsApp takes words of one's own only within 24 hours of the other's
 * last message; before that, or after, only a template it has approved.
 */
final class WhatsAppApi
{
    public const NAME = 'WhatsApp';

    /**
     * The step's number: one of the owner's WhatsApp connections.
     *
     * @throws StepFailed
     */
    public static function number(StepContext $step): Connection
    {
        return MetaApi::account($step, 'whatsapp');
    }

    /**
     * Where a number's calls go.
     */
    public static function at(Connection $number, string $what): string
    {
        return Meta::FACEBOOK.'/'.rawurlencode((string) $number->setting('phone_number_id'))."/{$what}";
    }

    /**
     * What Meta is given to prove the webhook's address is the app's: made
     * from the connection, so it can be shown again whenever it is asked for.
     */
    public static function verifyToken(Connection $number): string
    {
        return substr(hash_hmac('sha256', "whatsapp-verify:{$number->ref_id}", (string) config('app.key')), 0, 32);
    }

    /**
     * Who a step writes to: their number with its country, digits only.
     *
     * @throws StepFailed
     */
    public static function to(StepContext $step): string
    {
        $to = preg_replace('/\D+/', '', $step->text('to')) ?? '';

        return $to !== '' ? $to : throw new StepFailed('There is no one to send to: a number with its country code, like 66812345678.');
    }

    /**
     * Send a message, recorded so it never simply goes twice. Gives out
     * WhatsApp's id for it, and who it went to.
     *
     * @param  array<string, mixed>  $message  its type, and that type's own part
     * @return array{id: string|null, to: string}
     *
     * @throws StepFailed
     */
    public static function send(StepContext $step, Connection $number, string $to, array $message): array
    {
        $quote = trim($step->text('reply_to'));

        $sent = MetaApi::send($step, self::NAME, $to, fn () => MetaApi::as($number->secret('access_token'))->post(self::at($number, 'messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            // Under the message it answers, when it answers one
            ...$quote === '' ? [] : ['context' => ['message_id' => $quote]],
            ...$message,
        ]));

        return ['id' => $sent->json('messages.0.id'), 'to' => (string) ($sent->json('contacts.0.wa_id') ?? $to)];
    }
}
