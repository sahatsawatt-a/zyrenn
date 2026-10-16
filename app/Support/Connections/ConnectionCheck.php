<?php

namespace App\Support\Connections;

use App\Models\Connection;
use App\Support\Agents\Toolbox;
use App\Support\Ai\AiClient;
use App\Support\Ai\AiFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Whether a connection works, asked of the service itself in the cheapest
 * way it offers: the models a host lists, the bot Telegram says a token is,
 * the channel LINE says a token opens, the member or the Page a sign-in is
 * of, the number WhatsApp says a token speaks for, what is left of a search
 * key's allowance, the way into a PromptX account's flows. A failure is an
 * answer too, so it comes back as ok: false rather than as an error. A token
 * is never repeated in what comes back, even when the service says it.
 */
class ConnectionCheck
{
    public function __construct(private AiClient $ai) {}

    /**
     * @return array{ok: bool, message: string, models?: list<string>, free?: list<string>|null}
     */
    public function run(Connection $connection): array
    {
        if ($connection->isAi()) {
            return $this->ai($connection);
        }

        return match ($connection->service) {
            Services::TELEGRAM => $this->telegram($connection),
            Services::LINE => $this->line($connection),
            Services::LINKEDIN => $this->linkedin($connection),
            Services::FACEBOOK => $this->signedIn($connection, fn () => Http::timeout(10)->acceptJson()->withToken($connection->secret('page_token') ?? '')
                ->get(Meta::FACEBOOK.'/'.$connection->setting('page_id'), ['fields' => 'name']), 'name'),
            Services::INSTAGRAM => $this->signedIn($connection, fn () => Http::timeout(10)->acceptJson()
                ->get(InstagramSignIn::account($connection), ['fields' => 'username', 'access_token' => $connection->secret('access_token')]), 'username'),
            Services::THREADS => $this->signedIn($connection, fn () => Http::timeout(10)->acceptJson()
                ->get(Meta::THREADS.'/me', ['fields' => 'username', 'access_token' => $connection->secret('access_token')]), 'username'),
            Services::WHATSAPP => $this->whatsapp($connection),
            Services::SERPAPI => $this->serpapi($connection),
            Services::TAVILY => $this->tavily($connection),
            Services::PROMPTX => $this->promptx($connection),
            Services::MCP => $this->mcp($connection),
            default => ['ok' => true, 'message' => 'Nothing to try ahead: it is checked when it is used.'],
        };
    }

    /**
     * @return array{ok: bool, message: string, models?: list<string>, free?: list<string>|null}
     */
    private function ai(Connection $connection): array
    {
        if (($connection->setting('base_url') ?? '') === '') {
            return ['ok' => false, 'message' => 'Give the address of the host.'];
        }

        try {
            $catalog = $this->ai->catalog($connection);
        } catch (AiFailed $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $count = count($catalog['models']);

        return ['ok' => true, 'message' => "Works: {$count} ".($count === 1 ? 'model' : 'models').' available.', ...$catalog];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function telegram(Connection $connection): array
    {
        try {
            $response = Telegram::http(10)->withoutRedirecting()->acceptJson()->get(Telegram::method($connection, 'getMe'));
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach Telegram.'];
        }

        if ($response->successful() && $response->json('ok') === true) {
            $name = $response->json('result.username');

            return ['ok' => true, 'message' => is_string($name) ? "Works: this is @{$name}." : 'Works.'];
        }

        return ['ok' => false, 'message' => in_array($response->status(), [401, 404], true)
            ? 'Telegram does not know this token.'
            : "Telegram answered with {$response->status()}."];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function line(Connection $connection): array
    {
        try {
            $response = Line::http($connection, 10)->withoutRedirecting()->acceptJson()->get(Line::api('info'));
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach LINE.'];
        }

        if ($response->successful()) {
            $name = $response->json('displayName');

            return ['ok' => true, 'message' => is_string($name) ? "Works: this is “{$name}”." : 'Works.'];
        }

        return ['ok' => false, 'message' => $response->status() === 401
            ? 'LINE refused the channel access token.'
            : "LINE answered with {$response->status()}."];
    }

    /**
     * Its client id and secret can only be tried by signing in, so until
     * then there is nothing to ask; after, LinkedIn says who the sign-in is of.
     *
     * @return array{ok: bool, message: string}
     */
    private function linkedin(Connection $connection): array
    {
        $until = LinkedInSignIn::until($connection);

        if ($until === null) {
            return ['ok' => true, 'message' => 'Nothing to try yet: once it is saved, sign in with LinkedIn.'];
        }

        if ($until->isPast()) {
            return ['ok' => false, 'message' => "Its sign-in ran out on {$until->toFormattedDayDateString()}: sign in with LinkedIn again."];
        }

        try {
            $response = LinkedInSignIn::who($connection->secret('access_token') ?? '');
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach LinkedIn.'];
        }

        if ($response->successful()) {
            $name = $response->json('name');

            return ['ok' => true, 'message' => (is_string($name) ? "Works: signed in as {$name}" : 'Works')." until {$until->toFormattedDayDateString()}."];
        }

        return ['ok' => false, 'message' => $response->status() === 401
            ? 'LinkedIn no longer takes this sign-in: sign in with LinkedIn again.'
            : "LinkedIn answered with {$response->status()}."];
    }

    /**
     * One signed in on its service's own pages (SignIns): nothing to ask
     * until it is, then the service says who the sign-in is of. One near its
     * end that can be made new is, first.
     *
     * @param  callable(): Response  $ask
     * @return array{ok: bool, message: string}
     */
    private function signedIn(Connection $connection, callable $ask, string $named): array
    {
        /** @var class-string<SignsIn> $signIn */
        $signIn = SignIns::for($connection->service);
        $service = $connection->label();

        if (is_subclass_of($signIn, ProfileSignIn::class)) {
            $signIn::refresh($connection);
        }

        $who = $signIn::signedIn($connection);
        $until = $who['until'] ?? null;

        if ($who === null) {
            return ['ok' => true, 'message' => "Nothing to try yet: once it is saved, sign in with {$service}."];
        }

        if ($until?->isPast()) {
            return ['ok' => false, 'message' => "Its sign-in ran out on {$until->toFormattedDayDateString()}: sign in with {$service} again."];
        }

        try {
            $response = $ask();
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => "Could not reach {$service}."];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => "{$service} no longer takes this sign-in ({$response->status()}): sign in with {$service} again."];
        }

        $name = $response->json($named);

        return ['ok' => true, 'message' => (is_string($name) ? "Works: signed in as {$name}" : 'Works').($until === null ? '.' : " until {$until->toFormattedDayDateString()}.")];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function whatsapp(Connection $connection): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->withToken($connection->secret('access_token') ?? '')
                ->get(Meta::FACEBOOK.'/'.rawurlencode((string) $connection->setting('phone_number_id')), ['fields' => 'display_phone_number,verified_name']);
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach WhatsApp.'];
        }

        if ($response->successful()) {
            $number = $response->json('display_phone_number');
            $name = $response->json('verified_name');

            return ['ok' => true, 'message' => 'Works'.(is_string($number) ? ": this is {$number}".(is_string($name) && $name !== '' ? " ({$name})" : '') : '').'.'];
        }

        return ['ok' => false, 'message' => in_array($response->status(), [401, 403], true) || $response->json('error.code') === 190
            ? 'WhatsApp refused the access token: the one from API Setup lasts a day.'
            : 'WhatsApp said: '.Meta::said($response)];
    }

    /**
     * Asking of its account is free, and says how many searches are left.
     *
     * @return array{ok: bool, message: string}
     */
    private function serpapi(Connection $connection): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->get('https://serpapi.com/account.json', ['api_key' => $connection->secret('api_key') ?? '']);
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach SerpApi.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => $response->status() === 401 ? 'SerpApi does not know this key.' : "SerpApi answered with {$response->status()}."];
        }

        $left = $response->json('total_searches_left');
        $plan = $response->json('plan_name');

        return ['ok' => true, 'message' => 'Works'.(is_numeric($left) ? ': '.number_format((int) $left).((int) $left === 1 ? ' search' : ' searches').' left'.(is_string($plan) && $plan !== '' ? " ({$plan})" : '') : '').'.'];
    }

    /**
     * A password typed is tried by signing in with it, and nothing is kept;
     * without one, the sign-in it holds. Either way PromptX is asked for the
     * way into its flows, which every step needs.
     *
     * @return array{ok: bool, message: string}
     */
    private function promptx(Connection $connection): array
    {
        $password = $connection->secret('password');

        if ($password !== null) {
            $signedIn = PromptxSignIn::signIn((string) $connection->setting('login'), $password);

            if (is_string($signedIn)) {
                return ['ok' => false, 'message' => $signedIn];
            }

            $token = $signedIn['access_token'];
        } else {
            $token = PromptxSignIn::accountToken($connection);

            if ($token === null) {
                return ['ok' => false, 'message' => $connection->secret('refresh_token') === null ? 'Type the password to sign in.' : 'PromptX no longer takes its sign-in: type the password to sign in again.'];
            }
        }

        try {
            $response = Http::timeout(25)->acceptJson()->withToken($token)->get(PromptxSignIn::ACCOUNT.'/workflow-api/v1/get-user');
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach PromptX.'];
        }

        if (! $response->successful() || blank($response->json('wfAuth.accessToken'))) {
            return ['ok' => false, 'message' => "PromptX signed in, but gave no way into its flows: it answered with {$response->status()}."];
        }

        $claims = PromptxSignIn::claims($token);
        $name = trim(($claims['firstname'] ?? '').' '.($claims['lastname'] ?? '')) ?: ($claims['email'] ?? '');

        return ['ok' => true, 'message' => $name !== '' ? "Works: signed in as {$name}." : 'Works.'];
    }

    /**
     * Asking what it has used is free, and says what is left of its credits.
     *
     * @return array{ok: bool, message: string}
     */
    private function tavily(Connection $connection): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->withToken($connection->secret('api_key') ?? '')->get('https://api.tavily.com/usage');
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach Tavily.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => $response->status() === 401 ? 'Tavily does not know this key.' : "Tavily answered with {$response->status()}."];
        }

        $used = $response->json('account.plan_usage');
        $limit = $response->json('account.plan_limit');
        $plan = $response->json('account.current_plan');

        return ['ok' => true, 'message' => 'Works'.(is_numeric($used) && is_numeric($limit) ? ': '.number_format((int) $used).' of '.number_format((int) $limit).' credits used'.(is_string($plan) && $plan !== '' ? " ({$plan})" : '') : '').'.'];
    }

    /**
     * An MCP server: reached, and how many tools it offers.
     *
     * @return array{ok: bool, message: string}
     */
    private function mcp(Connection $connection): array
    {
        try {
            $client = Toolbox::client($connection);
            $count = $client->tools()->count();
            $client->disconnect();

            return ['ok' => true, 'message' => "Works: it offers {$count} ".($count === 1 ? 'tool' : 'tools').'.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'It could not be asked: '.mb_strimwidth($e->getMessage(), 0, 200, '…')];
        }
    }
}
