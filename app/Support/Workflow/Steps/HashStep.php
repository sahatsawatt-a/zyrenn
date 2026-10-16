<?php

namespace App\Support\Workflow\Steps;

use App\Support\Workflow\References;
use App\Support\Workflow\Template;
use Illuminate\Support\Str;

/**
 * Hash, sign and encode: a hash of a text (MD5, SHA-1, SHA-256, SHA-512), an
 * HMAC signature with a secret kept as a connection -- what a service checks
 * a request by -- base64, base64url, hex or URL encoding either way, or a
 * new UUID.
 */
final class HashStep implements Step
{
    public const ALGORITHMS = ['md5' => 'MD5', 'sha1' => 'SHA-1', 'sha256' => 'SHA-256', 'sha512' => 'SHA-512'];

    public function run(StepContext $step): StepResult
    {
        $do = (string) ($step->setting('do') ?: 'hash');

        if ($do === 'uuid') {
            return new StepResult(['value' => (string) Str::uuid()]);
        }

        $text = Template::text($step->template('value'));
        $algorithm = array_key_exists((string) $step->setting('algorithm'), self::ALGORITHMS) ? (string) $step->setting('algorithm') : 'sha256';
        $base64 = $step->setting('as') === 'base64';

        $value = match ($do) {
            'hash' => $base64 ? base64_encode(hash($algorithm, $text, true)) : hash($algorithm, $text),
            'hmac' => $base64 ? base64_encode(hash_hmac($algorithm, $text, self::secret($step), true)) : hash_hmac($algorithm, $text, self::secret($step)),
            'encode' => match ($step->setting('encoding')) {
                'base64url' => rtrim(strtr(base64_encode($text), '+/', '-_'), '='),
                'hex' => bin2hex($text),
                'url' => rawurlencode($text),
                default => base64_encode($text),
            },
            default => self::decode($text, (string) $step->setting('encoding')),
        };

        return new StepResult(['value' => $value]);
    }

    private static function secret(StepContext $step): string
    {
        $key = References::connection($step->owner(), (string) $step->setting('secret'), ['webhook_secret'])
            ?? throw new StepFailed('Its secret is gone, or is not this workflow\'s.');

        return (string) $key->secret('secret');
    }

    private static function decode(string $text, string $encoding): string
    {
        $decoded = match ($encoding) {
            'base64url' => base64_decode(strtr($text, '-_', '+/'), true),
            'hex' => ctype_xdigit($text) && strlen($text) % 2 === 0 ? hex2bin($text) : false,
            'url' => rawurldecode($text),
            default => base64_decode(trim($text), true),
        };

        if ($decoded === false) {
            throw new StepFailed('That is not '.match ($encoding) {
                'hex' => 'hex',
                'base64url' => 'base64url',
                default => 'base64',
            }.' text, so it can\'t be read back.');
        }

        // Bytes that are no text are given back as base64, not broken
        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : 'base64:'.base64_encode($decoded);
    }
}
