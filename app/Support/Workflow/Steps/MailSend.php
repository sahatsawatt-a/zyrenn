<?php

namespace App\Support\Workflow\Steps;

use App\Support\Connections\Services;
use App\Support\Outside\RemoteDownloadFailed;
use App\Support\Outside\SmtpTransports;
use App\Support\Workflow\References;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Send an email through the owner's own mail server, by SMTP (an "Email
 * (SMTP)" connection): to, cc, bcc and reply-to, a subject, and text --
 * plain, HTML or Markdown. Its Message-ID is the same on every try, so a
 * mail server that sees a repeat can drop it.
 */
final class MailSend implements Step
{
    /** The most people one email goes to, all told. */
    public const MOST_TO = 50;

    public function run(StepContext $step): StepResult
    {
        $smtp = References::connection($step->owner(), (string) $step->setting('connection'), [Services::SMTP])
            ?? throw new StepFailed('Its mail server is gone, or is not this workflow\'s.');
        $to = self::addresses($step, 'to', 'To');
        $cc = self::addresses($step, 'cc', 'Cc');
        $bcc = self::addresses($step, 'bcc', 'Bcc');
        $replyTo = self::addresses($step, 'reply_to', 'Reply to');

        if ($to === []) {
            throw new StepFailed('Say whom to send it to.');
        }

        if (count($to) + count($cc) + count($bcc) > self::MOST_TO) {
            throw new StepFailed('One email goes to at most '.self::MOST_TO.' people.');
        }

        $subject = trim($step->text('subject'));
        $body = $step->text('body');

        if ($subject === '' && trim($body) === '') {
            throw new StepFailed('Give it a subject or something to say.');
        }

        try {
            $from = new Address((string) $smtp->setting('from'), (string) ($smtp->setting('from_name') ?? ''));
        } catch (\Throwable) {
            throw new StepFailed("\"{$smtp->name}\" has no address to send from.");
        }

        $email = (new Email)->from($from)->to(...$to)->cc(...$cc)->bcc(...$bcc)->replyTo(...$replyTo)->subject($subject);

        match ($step->setting('format')) {
            'html' => $email->html($body)->text(trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
            'markdown' => $email->html(Str::markdown($body, ['html_input' => 'escape', 'allow_unsafe_links' => false]))->text($body),
            default => $email->text($body),
        };

        $id = $step->sendKey().'@workflow.zyrenn';
        $email->getHeaders()->addIdHeader('Message-ID', $id);

        try {
            $transport = app(SmtpTransports::class)->for(
                (string) $smtp->setting('host'),
                (int) ($smtp->setting('port') ?: 587),
                (string) ($smtp->setting('security') ?: 'tls'),
                $smtp->setting('username'),
                $smtp->secret('password'),
            );
        } catch (RemoteDownloadFailed $e) {
            throw new StepFailed($e->getMessage());
        }

        $step->deliver('mail', mb_strimwidth(implode(', ', array_map(fn (Address $one) => $one->getAddress(), $to)), 0, 180, '…'), $id, function () use ($transport, $email) {
            try {
                return $transport->send($email);
            } catch (TransportExceptionInterface $e) {
                // Not connected at all: it never went, and is tried again
                $neverLeft = str_contains($e->getMessage(), 'Connection could not be established');

                throw new StepFailed('The mail server said: '.mb_strimwidth(trim($e->getMessage()), 0, 300, '…'), retryable: $neverLeft);
            }
        });

        return new StepResult([
            'message_id' => $id,
            'to' => array_map(fn (Address $one) => $one->getAddress(), $to),
            'subject' => $subject,
        ]);
    }

    /**
     * A setting's addresses: "ada@example.com, Bo <bo@example.com>", one on
     * a line, or a list.
     *
     * @return list<Address>
     */
    private static function addresses(StepContext $step, string $name, string $label): array
    {
        $given = $step->template($name);
        $parts = is_array($given) ? $given : preg_split('/[,;\n]+/', (string) $given);
        $addresses = [];

        foreach ($parts ?: [] as $part) {
            $part = trim(is_scalar($part) ? (string) $part : '');

            if ($part === '') {
                continue;
            }

            try {
                $addresses[] = Address::create($part);
            } catch (\Throwable) {
                throw new StepFailed("\"{$part}\" in \"{$label}\" is not an email address.");
            }
        }

        return $addresses;
    }
}
