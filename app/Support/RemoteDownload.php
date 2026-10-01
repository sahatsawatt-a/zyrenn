<?php

namespace App\Support;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Mime\MimeTypes;

/**
 * Fetch a file from the public web into a temporary file, for upload-file's
 * "source_url".
 *
 * The server makes the request, so it must never be talked into fetching
 * from itself or its own network (the database, the collab server, a cloud
 * metadata address). Every hop's host is resolved here and has to be a
 * public address, and the connection is pinned to that address, so a second
 * DNS answer can't swap a private one in between the check and the fetch.
 * Redirects are followed by hand for the same reason.
 */
class RemoteDownload
{
    private const MAX_REDIRECTS = 5;

    private const CHUNK_BYTES = 1024 * 1024;

    /**
     * @return array{path: string, name: string} a temporary file the caller deletes, and a name for it
     *
     * @throws RemoteDownloadFailed
     */
    public static function fetch(string $url, int $maxBytes): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = self::publicTarget($url);

            try {
                $response = Http::withoutRedirecting()
                    ->connectTimeout(10)
                    ->timeout(300)
                    ->withUserAgent('Zyrenn')
                    ->withOptions([
                        'stream' => true,
                        // An IPv6 literal is already the address to connect to
                        'curl' => str_contains($ip, ':') ? [] : [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                    ])
                    ->get($url);
            } catch (ConnectionException) {
                throw new RemoteDownloadFailed("Could not reach {$host}.");
            }

            if ($response->redirect()) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new RemoteDownloadFailed("{$host} answered with a redirect that goes nowhere.");
                }

                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }

            if (! $response->successful()) {
                throw new RemoteDownloadFailed("{$host} answered {$response->status()} for that URL.");
            }

            if ((int) $response->header('Content-Length') > $maxBytes) {
                throw new RemoteDownloadFailed(self::tooBig($maxBytes));
            }

            return [
                'path' => self::save($response->toPsrResponse()->getBody(), $maxBytes),
                'name' => self::nameFor($url, $response->header('Content-Type')),
            ];
        }

        throw new RemoteDownloadFailed('That URL redirects more than '.self::MAX_REDIRECTS.' times.');
    }

    /**
     * The URL's host and port, and the public address it resolves to.
     *
     * @return array{string, int, string}
     */
    private static function publicTarget(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RemoteDownloadFailed('Only an http:// or https:// URL can be fetched.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $literal = trim($host, '[]');

        // Anything else gethostbynamel turns into an IPv4 address -- including
        // spellings like "2130706433" for 127.0.0.1 -- so it is checked as one
        $ips = filter_var($literal, FILTER_VALIDATE_IP) ? [$literal] : gethostbynamel($host);

        if (! $ips) {
            throw new RemoteDownloadFailed("Could not find {$host}.");
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                throw new RemoteDownloadFailed("{$host} is not on the public internet, so it can't be fetched from here.");
            }
        }

        return [$host, $port, $ips[0]];
    }

    /**
     * Copy the body to a temporary file, giving up as soon as it passes the
     * limit: a server can leave out Content-Length, or lie in it.
     */
    private static function save(StreamInterface $body, int $maxBytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mcp-fetch-');
        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new RemoteDownloadFailed('There was nowhere to put the download.');
        }

        $bytes = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);
                $bytes += strlen($chunk);

                if ($bytes > $maxBytes) {
                    throw new RemoteDownloadFailed(self::tooBig($maxBytes));
                }

                fwrite($out, $chunk);
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($path);

            throw $e instanceof RemoteDownloadFailed ? $e : new RemoteDownloadFailed('The download broke off part way.');
        }

        fclose($out);

        return $path;
    }

    /**
     * The last part of the URL's path, e.g. "cat.png", with an extension from
     * the Content-Type when it has none -- the extension decides how the
     * Drive serves the file back.
     */
    private static function nameFor(string $url, string $contentType): string
    {
        $name = basename(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        $name = $name === '' || $name === '.' || $name === '..' ? 'download' : $name;

        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $mime = strtolower(trim(explode(';', $contentType)[0]));
            $ext = $mime === '' ? null : (MimeTypes::getDefault()->getExtensions($mime)[0] ?? null);
            $name .= $ext ? ".{$ext}" : '';
        }

        return mb_substr($name, 0, 255);
    }

    private static function tooBig(int $maxBytes): string
    {
        return 'That file is larger than the '.intdiv($maxBytes, 1024 * 1024).' MB limit.';
    }
}
