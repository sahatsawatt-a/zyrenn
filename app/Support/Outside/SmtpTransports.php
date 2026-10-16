<?php

namespace App\Support\Outside;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The mail servers a workflow sends through by SMTP: only one on the public
 * internet, on a port mail is sent on, connected to at the address that was
 * checked -- its certificate checked against its name. Bound in the
 * container, so a test can stand in for every server at once.
 */
class SmtpTransports
{
    /** The ports mail is sent on: nothing else on a public host is knocked on. */
    public const PORTS = [25, 465, 587, 2525];

    /**
     * @param  string  $security  "ssl" (TLS from the start), "tls" (STARTTLS, required) or "none"
     *
     * @throws RemoteDownloadFailed when the server may not be reached from here
     */
    public function for(string $host, int $port, string $security, ?string $username, ?string $password): TransportInterface
    {
        if (! in_array($port, self::PORTS, true)) {
            throw new RemoteDownloadFailed('Mail is only sent on port '.implode(', ', self::PORTS).'.');
        }

        [, , $ip] = RemoteDownload::publicTarget("https://{$host}");
        $transport = new EsmtpTransport(str_contains($ip, ':') ? "[{$ip}]" : $ip, $port, $security === 'ssl');
        $transport->setAutoTls($security !== 'none');
        $transport->setRequireTls($security === 'tls');

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout(20);
            $stream->setStreamOptions(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        }

        if (filled($username)) {
            $transport->setUsername((string) $username);
            $transport->setPassword((string) $password);
        }

        return $transport;
    }
}
