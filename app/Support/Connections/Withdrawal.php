<?php

namespace App\Support\Connections;

use App\Models\Connection;
use App\Support\Audit\Audit;
use App\Support\Workflow\Inbound\Hooks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Meta telling the app that someone took back what they gave it: they took
 * the app off their account ("deauthorize"), or asked for what it holds of
 * them to be deleted. Either comes as a "signed request" -- a signature and
 * what is said, with a dot between -- signed with the secret of the Meta,
 * Instagram or Threads app it came through.
 *
 * What the app holds of someone who signed a connection in is that sign-in:
 * the token, and who it is. Taking it back signs the connection out. The
 * connection itself is its owner's, with the app ID and secret they typed,
 * and stays. A deletion request also lets go of what came in through the
 * connection and is still kept.
 */
final class Withdrawal
{
    /** The services whose sign-ins Meta may take back. */
    private const SERVICES = [Services::FACEBOOK, Services::INSTAGRAM, Services::THREADS];

    /** Where a connection keeps who signed it in: the account, by either of its ids, or the person who looks after its Page. */
    private const WHO = ['member', 'scoped_id', 'signer'];

    /**
     * Whose sign-ins a signed request is about: the connections signed in
     * as them, through the app that signed it. Null when no app a
     * connection here is of signed it -- then it is not Meta's, or not ours.
     *
     * @return array{user: string, connections: Collection<int, Connection>}|null
     */
    public static function of(string $signed): ?array
    {
        $user = (string) (self::said($signed)['user_id'] ?? '');

        if ($user === '') {
            return null;
        }

        $theirs = Connection::whereIn('service', self::SERVICES)
            ->where(fn ($query) => collect(self::WHO)->each(fn (string $key) => $query->orWhere("settings->{$key}", $user)))
            ->get()
            ->filter(fn (Connection $connection) => self::signedBy($signed, $connection))
            ->values();

        // Nothing of theirs is held any longer, yet it may still be Meta asking: any app known here that signed it will do
        $ours = $theirs->isNotEmpty() || Connection::whereIn('service', self::SERVICES)->lazyById(200)
            ->contains(fn (Connection $connection) => self::signedBy($signed, $connection));

        return $ours ? ['user' => $user, 'connections' => $theirs] : null;
    }

    /**
     * Sign a connection out: what signing in gave is let go, what its owner
     * typed is kept. Forgetting also deletes what came in through it.
     */
    public static function signOut(Connection $connection, string $why, bool $forget = false): void
    {
        $granted = (Services::get($connection->service) ?? [])['granted'] ?? ['settings' => [], 'secrets' => []];

        $connection->settings = array_diff_key($connection->settings ?? [], array_flip($granted['settings']));
        $connection->secrets = array_diff_key($connection->secrets ?? [], array_flip($granted['secrets'])) ?: null;
        $connection->save();

        if ($forget) {
            DB::table('inbound_events')->where('source', "{$connection->service}:{$connection->ref_id}")->delete();
        }

        Audit::record($connection, 'connection.signed_out', null, ['by' => $connection->label(), 'why' => $why]);
    }

    /**
     * What whoever asked for a deletion is given to look it up by: when it
     * was done and how many sign-ins went, signed so the page that shows it
     * needs nothing kept.
     */
    public static function receipt(int $removed): string
    {
        $said = now()->timestamp.'-'.$removed;

        return $said.'-'.self::mark($said);
    }

    /**
     * What a receipt says, or null for one the app never gave.
     *
     * @return array{at: Carbon, removed: int}|null
     */
    public static function receiptSays(string $receipt): ?array
    {
        if (preg_match('/^(\d{9,11})-(\d{1,4})-([a-f0-9]{16})$/', $receipt, $parts) !== 1 || ! hash_equals(self::mark("{$parts[1]}-{$parts[2]}"), $parts[3])) {
            return null;
        }

        return ['at' => Carbon::createFromTimestamp((int) $parts[1]), 'removed' => (int) $parts[2]];
    }

    /**
     * The app's address for anyone outside: its public one where it has one.
     */
    public static function site(string $path = ''): string
    {
        $hooks = Hooks::url('');
        $base = $hooks !== null ? substr($hooks, 0, -strlen('/hooks/')) : rtrim((string) config('app.url'), '/');

        return $base.'/'.ltrim($path, '/');
    }

    /**
     * What a signed request says, before anyone is taken at their word.
     *
     * @return array<string, mixed>|null
     */
    private static function said(string $signed): ?array
    {
        $said = json_decode(self::decoded(explode('.', $signed, 2)[1] ?? ''), true);

        return is_array($said) ? $said : null;
    }

    /**
     * Whether the app a connection is of signed it.
     */
    private static function signedBy(string $signed, Connection $connection): bool
    {
        [$signature, $said] = [...explode('.', $signed, 2), ''];
        $secret = $connection->secret('client_secret');

        return $secret !== null && $said !== '' && hash_equals(hash_hmac('sha256', $said, $secret, true), self::decoded($signature));
    }

    /**
     * Meta writes both parts in the base64 that goes in an address.
     */
    private static function decoded(string $part): string
    {
        return (string) base64_decode(strtr($part, '-_', '+/'), false);
    }

    private static function mark(string $said): string
    {
        return substr(hash_hmac('sha256', "deletion:{$said}", (string) config('app.key')), 0, 16);
    }
}
