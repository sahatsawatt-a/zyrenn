<?php

namespace App\Support\Connections;

use App\Models\Connection;
use App\Support\Workflow\Inbound\Hooks;

/**
 * The services a connection is signed in to on their own pages, and what
 * the connections page is told of one: the way there, the address it comes
 * back to -- which the service has to be told of first -- and how it stands.
 */
final class SignIns
{
    /** @var array<string, class-string<SignsIn>> */
    private const BY = [
        Services::LINKEDIN => LinkedInSignIn::class,
        Services::FACEBOOK => FacebookSignIn::class,
        Services::INSTAGRAM => InstagramSignIn::class,
        Services::THREADS => ThreadsSignIn::class,
    ];

    /**
     * @return class-string<SignsIn>|null
     */
    public static function for(string $service): ?string
    {
        return self::BY[$service] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function services(): array
    {
        return array_keys(self::BY);
    }

    /**
     * Where a service sends its member back: the one address for every
     * connection of that service, since a service takes only those its app
     * lists. Where the app has a public address it is that one's -- the
     * same wherever the app is opened from, and one every service takes --
     * which only passes the member on to where they set out
     * (ConnectionSignInController).
     */
    public static function callback(string $service): string
    {
        return Hooks::url("{$service}/signed-in") ?? route('connections.signed-in');
    }

    /**
     * @return array{url: string, callback: string, service: string, hint: string, state: string, detail: string}|null
     */
    public static function describe(Connection $connection): ?array
    {
        $signIn = self::for($connection->service);

        if ($signIn === null) {
            return null;
        }

        $who = $signIn::signedIn($connection);
        $until = $who['until'] ?? null;
        $day = $until?->isoFormat('D MMM YYYY');

        return [
            'url' => route('connections.sign-in', $connection),
            'callback' => self::callback($connection->service),
            'service' => $connection->label(),
            'hint' => Services::get($connection->service)['signs_in'] ?? '',
            // 'no' before it ever was, 'over' once its time has run out
            'state' => match (true) {
                $who === null => 'no',
                $until?->isPast() === true => 'over',
                default => 'yes',
            },
            'detail' => match (true) {
                $who === null => 'Not signed in yet',
                $until?->isPast() === true => "Its sign-in ran out on {$day}",
                default => 'Signed in as '.($who['name'] !== '' ? $who['name'] : 'a member').($day === null ? '' : " until {$day}"),
            },
        ];
    }
}
