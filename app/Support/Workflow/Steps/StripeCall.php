<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\Services;
use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Stripe's calls, each a kind of step of its own (ServiceKinds names them;
 * this runs them): payment links and checkout pages, payments and refunds,
 * customers, invoices, products and prices, subscriptions, the balance --
 * and any other call of its API, written out.
 *
 * Amounts are written as people write them -- 12.50 -- and sent in the
 * currency's smallest unit, as Stripe takes them (1250, or 12 for yen).
 * Everything that makes or changes something carries an idempotency key of
 * the step's own, the same however often the step is tried, so Stripe
 * makes it once: a send that broke off is simply made again.
 */
final class StripeCall implements Step
{
    public const API = 'https://api.stripe.com/v1';

    public const VERSION = '2024-06-20';

    /** Currencies with no smaller unit (stripe.com/docs/currencies). */
    private const WHOLE = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    /** Currencies with three decimals. */
    private const THREE = ['bhd', 'jod', 'kwd', 'omr', 'tnd'];

    public function run(StepContext $step): StepResult
    {
        $stripe = self::connection($step);

        return new StepResult(match ($step->node['type']) {
            'stripe.payment_link_create' => self::paymentLink($step, $stripe),
            'stripe.checkout_create' => self::checkoutCreate($step, $stripe),
            'stripe.checkout' => self::checkout(self::ask($stripe, 'GET', '/checkout/sessions/'.self::id($step, 'session', 'cs_'))),
            'stripe.payment' => self::payment(self::ask($stripe, 'GET', '/payment_intents/'.self::id($step, 'payment', 'pi_'))),
            'stripe.payments' => self::payments($step, $stripe),
            'stripe.refund_create' => self::refund($step, $stripe),
            'stripe.customer_create' => self::customer(self::send($step, $stripe, '/customers', self::person($step))),
            'stripe.customer_find' => self::customerFind($step, $stripe),
            'stripe.customer' => self::customer(self::ask($stripe, 'GET', '/customers/'.self::id($step, 'customer', 'cus_'))),
            'stripe.customer_update' => self::customerUpdate($step, $stripe),
            'stripe.invoice_create' => self::invoiceCreate($step, $stripe),
            'stripe.invoices' => self::invoices($step, $stripe),
            'stripe.product_create' => self::productCreate($step, $stripe),
            'stripe.prices' => self::prices($step, $stripe),
            'stripe.subscriptions' => self::subscriptions($step, $stripe),
            'stripe.subscription_cancel' => self::subscriptionCancel($step, $stripe),
            'stripe.balance' => self::balance($stripe),
            'stripe.request' => self::request($step, $stripe),

            default => throw new StepFailed("This app doesn't know the Stripe step \"{$step->node['type']}\"."),
        });
    }

    // --- Payments ---

    /**
     * A link anyone can pay by, for one thing at a price: a price already in
     * Stripe, or one made here for a name and an amount.
     *
     * @return array<string, mixed>
     */
    private static function paymentLink(StepContext $step, Connection $stripe): array
    {
        $price = trim($step->text('price'));

        if ($price === '') {
            [$amount, $currency] = self::money($step);
            $price = (string) (self::send($step, $stripe, '/prices', [
                'currency' => $currency,
                'unit_amount' => $amount,
                'product_data' => ['name' => SearchSteps::needs($step, 'name', 'no name for what is paid for')],
            ], 'price')['id'] ?? throw new StepFailed('Stripe made no price.'));
        }

        $after = trim($step->text('after'));
        $link = self::send($step, $stripe, '/payment_links', array_filter([
            'line_items' => [['price' => $price, 'quantity' => self::quantity($step)]],
            'after_completion' => $after === '' ? null : ['type' => 'redirect', 'redirect' => ['url' => $after]],
            'metadata' => self::metadata($step) ?: null,
        ], fn ($value) => $value !== null));

        return ['url' => $link['url'] ?? null, 'id' => $link['id'] ?? null, 'price' => $price, 'active' => (bool) ($link['active'] ?? true)];
    }

    /**
     * A checkout page made for one payment -- or a subscription, at a price
     * that recurs -- with where Stripe sends the payer after.
     *
     * @return array<string, mixed>
     */
    private static function checkoutCreate(StepContext $step, Connection $stripe): array
    {
        $price = trim($step->text('price'));
        $item = ['quantity' => self::quantity($step)];

        if ($price !== '') {
            $item['price'] = $price;
        } else {
            [$amount, $currency] = self::money($step);
            $item['price_data'] = ['currency' => $currency, 'unit_amount' => $amount, 'product_data' => ['name' => SearchSteps::needs($step, 'name', 'no name for what is paid for')]];
        }

        $customer = trim($step->text('customer'));
        $email = trim($step->text('email'));
        $session = self::send($step, $stripe, '/checkout/sessions', array_filter([
            'mode' => $step->setting('mode') === 'subscription' ? 'subscription' : 'payment',
            'line_items' => [$item],
            'success_url' => SearchSteps::needs($step, 'success_url', 'no page to send the payer to once they have paid'),
            'cancel_url' => trim($step->text('cancel_url')) ?: null,
            'customer' => str_starts_with($customer, 'cus_') ? $customer : null,
            'customer_email' => $customer === '' && $email !== '' ? $email : null,
            'client_reference_id' => mb_substr(trim($step->text('reference')), 0, 200) ?: null,
            'metadata' => self::metadata($step) ?: null,
        ], fn ($value) => $value !== null));

        return self::checkout($session);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payments(StepContext $step, Connection $stripe): array
    {
        $since = trim($step->text('since'));
        $found = self::ask($stripe, 'GET', '/payment_intents', array_filter([
            'customer' => trim($step->text('customer')) ?: null,
            'created' => $since === '' ? null : ['gte' => self::when($since)],
            'limit' => SearchSteps::count($step, 'count', 20, 100),
        ], fn ($value) => $value !== null));
        $payments = array_map(self::payment(...), self::rows($found));

        return ['payments' => $payments, 'count' => count($payments), 'more' => (bool) ($found['has_more'] ?? false)];
    }

    /**
     * Money given back: all of a payment, or as much as is said.
     *
     * @return array<string, mixed>
     */
    private static function refund(StepContext $step, Connection $stripe): array
    {
        $of = SearchSteps::needs($step, 'payment', 'no payment to give back: its id, pi_… or ch_…');
        $how = trim($step->text('amount'));
        $amount = null;

        if ($how !== '') {
            $paid = self::ask($stripe, 'GET', str_starts_with($of, 'ch_') ? "/charges/{$of}" : "/payment_intents/{$of}");
            $amount = self::minor($how, (string) ($paid['currency'] ?? 'usd'));
        }

        $refund = self::send($step, $stripe, '/refunds', array_filter([
            str_starts_with($of, 'ch_') ? 'charge' : 'payment_intent' => $of,
            'amount' => $amount,
            'reason' => in_array($step->setting('reason'), ['duplicate', 'fraudulent', 'requested_by_customer'], true) ? $step->setting('reason') : null,
            'metadata' => self::metadata($step) ?: null,
        ], fn ($value) => $value !== null));

        return [
            'id' => $refund['id'] ?? null,
            'status' => $refund['status'] ?? null,
            ...self::amount($refund['amount'] ?? null, $refund['currency'] ?? null),
            'payment' => $refund['payment_intent'] ?? $refund['charge'] ?? $of,
        ];
    }

    // --- Customers ---

    /**
     * @return array<string, mixed>
     */
    private static function customerFind(StepContext $step, Connection $stripe): array
    {
        $found = self::ask($stripe, 'GET', '/customers', [
            'email' => SearchSteps::needs($step, 'email', 'no email to find them by'),
            'limit' => SearchSteps::count($step, 'count', 10, 100),
        ]);
        $customers = array_map(self::customer(...), self::rows($found));

        // The first, for a step after to read without a list
        return ['customer' => $customers[0] ?? null, 'customers' => $customers, 'count' => count($customers), 'found' => $customers !== []];
    }

    /**
     * Only what is given changes.
     *
     * @return array<string, mixed>
     */
    private static function customerUpdate(StepContext $step, Connection $stripe): array
    {
        $change = self::person($step);

        if ($change === []) {
            throw new StepFailed('There is nothing to change: give an email, a name, a phone, words or metadata.');
        }

        return self::customer(self::send($step, $stripe, '/customers/'.self::id($step, 'customer', 'cus_'), $change));
    }

    /**
     * Who someone is, as a customer is made or changed: only what is given.
     *
     * @return array<string, mixed>
     */
    private static function person(StepContext $step): array
    {
        return array_filter([
            'email' => trim($step->text('email')) ?: null,
            'name' => trim($step->text('name')) ?: null,
            'phone' => trim($step->text('phone')) ?: null,
            'description' => trim($step->text('description')) ?: null,
            'metadata' => self::metadata($step) ?: null,
        ], fn ($value) => $value !== null);
    }

    // --- Invoices ---

    /**
     * An invoice for a customer, its lines each a thing and its amount;
     * finalised and emailed by Stripe at once, or left a draft.
     *
     * @return array<string, mixed>
     */
    private static function invoiceCreate(StepContext $step, Connection $stripe): array
    {
        $customer = self::id($step, 'customer', 'cus_');
        $currency = self::currency($step);
        $lines = $step->fields('items');

        if ($lines === []) {
            throw new StepFailed('There is nothing on it: give each line a name and an amount.');
        }

        $invoice = self::send($step, $stripe, '/invoices', array_filter([
            'customer' => $customer,
            'currency' => $currency,
            'collection_method' => 'send_invoice',
            'days_until_due' => max(0, min(365, (int) ($step->setting('days') ?: 30))),
            'description' => trim($step->text('memo')) ?: null,
            'pending_invoice_items_behavior' => 'exclude',
            'metadata' => self::metadata($step) ?: null,
        ], fn ($value) => $value !== null), 'invoice');
        $id = (string) ($invoice['id'] ?? throw new StepFailed('Stripe made no invoice.'));

        $at = 0;

        foreach ($lines as $name => $amount) {
            self::send($step, $stripe, '/invoiceitems', [
                'customer' => $customer,
                'invoice' => $id,
                'currency' => $currency,
                'amount' => self::minor(is_scalar($amount) ? (string) $amount : '', $currency),
                'description' => mb_substr((string) $name, 0, 500),
            ], 'line-'.$at++);
        }

        $invoice = $step->setting('send') === 'yes'
            ? self::send($step, $stripe, "/invoices/{$id}/send", [], 'send')
            : self::ask($stripe, 'GET', "/invoices/{$id}");

        return self::invoice($invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoices(StepContext $step, Connection $stripe): array
    {
        $found = self::ask($stripe, 'GET', '/invoices', array_filter([
            'customer' => trim($step->text('customer')) ?: null,
            'status' => in_array($step->setting('status'), ['draft', 'open', 'paid', 'uncollectible', 'void'], true) ? $step->setting('status') : null,
            'limit' => SearchSteps::count($step, 'count', 20, 100),
        ], fn ($value) => $value !== null));
        $invoices = array_map(self::invoice(...), self::rows($found));

        return ['invoices' => $invoices, 'count' => count($invoices)];
    }

    // --- Products and subscriptions ---

    /**
     * A product with its price: once, or every day, week, month or year.
     *
     * @return array<string, mixed>
     */
    private static function productCreate(StepContext $step, Connection $stripe): array
    {
        [$amount, $currency] = self::money($step);
        $every = in_array($step->setting('every'), ['day', 'week', 'month', 'year'], true) ? $step->setting('every') : null;
        $product = self::send($step, $stripe, '/products', array_filter([
            'name' => SearchSteps::needs($step, 'name', 'no name for the product'),
            'description' => trim($step->text('description')) ?: null,
            'default_price_data' => array_filter(['currency' => $currency, 'unit_amount' => $amount, 'recurring' => $every === null ? null : ['interval' => $every]]),
            'metadata' => self::metadata($step) ?: null,
        ], fn ($value) => $value !== null));

        return ['id' => $product['id'] ?? null, 'name' => $product['name'] ?? null, 'price' => is_array($product['default_price'] ?? null) ? ($product['default_price']['id'] ?? null) : ($product['default_price'] ?? null), 'every' => $every, ...self::amount($amount, $currency)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function prices(StepContext $step, Connection $stripe): array
    {
        $found = self::ask($stripe, 'GET', '/prices', array_filter([
            'product' => trim($step->text('product')) ?: null,
            'active' => 'true',
            'expand' => ['data.product'],
            'limit' => SearchSteps::count($step, 'count', 20, 100),
        ], fn ($value) => $value !== null));
        $prices = array_map(fn (array $one) => [
            'id' => $one['id'] ?? null,
            'product' => is_array($one['product'] ?? null) ? ($one['product']['id'] ?? null) : ($one['product'] ?? null),
            'name' => is_array($one['product'] ?? null) ? ($one['product']['name'] ?? null) : ($one['nickname'] ?? null),
            ...self::amount($one['unit_amount'] ?? null, $one['currency'] ?? null),
            'every' => $one['recurring']['interval'] ?? null,
        ], self::rows($found));

        return ['prices' => $prices, 'count' => count($prices)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function subscriptions(StepContext $step, Connection $stripe): array
    {
        $found = self::ask($stripe, 'GET', '/subscriptions', array_filter([
            'customer' => trim($step->text('customer')) ?: null,
            'status' => in_array($step->setting('status'), ['active', 'past_due', 'canceled', 'trialing', 'unpaid', 'all'], true) ? $step->setting('status') : null,
            'limit' => SearchSteps::count($step, 'count', 20, 100),
        ], fn ($value) => $value !== null));
        $subscriptions = array_map(self::subscription(...), self::rows($found));

        return ['subscriptions' => $subscriptions, 'count' => count($subscriptions)];
    }

    /**
     * Ended now, or at the end of what is paid for.
     *
     * @return array<string, mixed>
     */
    private static function subscriptionCancel(StepContext $step, Connection $stripe): array
    {
        $id = self::id($step, 'subscription', 'sub_');

        return self::subscription($step->setting('when') === 'now'
            ? self::send($step, $stripe, "/subscriptions/{$id}", [], method: 'DELETE')
            : self::send($step, $stripe, "/subscriptions/{$id}", ['cancel_at_period_end' => true]));
    }

    // --- The account ---

    /**
     * @return array<string, mixed>
     */
    private static function balance(Connection $stripe): array
    {
        $balance = self::ask($stripe, 'GET', '/balance');
        $sum = fn (string $kind) => array_map(fn ($one) => self::amount($one['amount'] ?? null, $one['currency'] ?? null), self::rows(['data' => $balance[$kind] ?? []]));

        return ['available' => $sum('available'), 'pending' => $sum('pending'), 'live' => (bool) ($balance['livemode'] ?? false)];
    }

    /**
     * Any other call of Stripe's API, as docs.stripe.com writes it: a path
     * after /v1, with its parameters as named values -- written with
     * brackets for what is inside something, metadata[order] for one.
     *
     * @return array<string, mixed>
     */
    private static function request(StepContext $step, Connection $stripe): array
    {
        $method = in_array($step->setting('method'), ['GET', 'POST', 'DELETE'], true) ? $step->setting('method') : 'GET';
        $path = '/'.ltrim((string) preg_replace('#^(https?://api\.stripe\.com)?/?v1/#i', '', SearchSteps::needs($step, 'path', 'no path to call, like /customers')), '/');

        if (preg_match('#^/[A-Za-z0-9_\-/]*$#', $path) !== 1) {
            throw new StepFailed("\"{$path}\" is not a path of Stripe's API.");
        }

        // Named with brackets, sent as Stripe reads them
        parse_str(http_build_query(array_map(fn ($value) => is_scalar($value) ? (string) $value : json_encode($value), $step->fields('params'))), $parsed);
        $params = [];

        foreach ($parsed as $name => $value) {
            $params[(string) $name] = $value;
        }

        return ['answer' => $method === 'GET' ? self::ask($stripe, 'GET', $path, $params) : self::send($step, $stripe, $path, $params, method: $method)];
    }

    // --- Shapes ---

    /**
     * @param  array<mixed>  $one
     * @return array<string, mixed>
     */
    private static function checkout(array $one): array
    {
        return [
            'id' => $one['id'] ?? null,
            'url' => $one['url'] ?? null,
            'status' => $one['status'] ?? null,
            'paid' => ($one['payment_status'] ?? null) === 'paid',
            'payment_status' => $one['payment_status'] ?? null,
            ...self::amount($one['amount_total'] ?? null, $one['currency'] ?? null),
            'customer' => $one['customer'] ?? null,
            'email' => $one['customer_details']['email'] ?? $one['customer_email'] ?? null,
            'name' => $one['customer_details']['name'] ?? null,
            'payment' => $one['payment_intent'] ?? null,
            'subscription' => $one['subscription'] ?? null,
            'reference' => $one['client_reference_id'] ?? null,
            'metadata' => (object) ($one['metadata'] ?? []),
            'expires_at' => isset($one['expires_at']) ? date('c', (int) $one['expires_at']) : null,
        ];
    }

    /**
     * @param  array<mixed>  $one
     * @return array<string, mixed>
     */
    private static function payment(array $one): array
    {
        return [
            'id' => $one['id'] ?? null,
            'status' => $one['status'] ?? null,
            'paid' => ($one['status'] ?? null) === 'succeeded',
            ...self::amount($one['amount'] ?? null, $one['currency'] ?? null),
            'received' => self::amount($one['amount_received'] ?? null, $one['currency'] ?? null)['amount'],
            'customer' => $one['customer'] ?? null,
            'email' => $one['receipt_email'] ?? null,
            'description' => $one['description'] ?? null,
            'charge' => $one['latest_charge'] ?? null,
            'metadata' => (object) ($one['metadata'] ?? []),
            'at' => isset($one['created']) ? date('c', (int) $one['created']) : null,
        ];
    }

    /**
     * @param  array<mixed>  $one
     * @return array<string, mixed>
     */
    private static function customer(array $one): array
    {
        return [
            'id' => $one['id'] ?? null,
            'email' => $one['email'] ?? null,
            'name' => $one['name'] ?? null,
            'phone' => $one['phone'] ?? null,
            'description' => $one['description'] ?? null,
            'metadata' => (object) ($one['metadata'] ?? []),
            'at' => isset($one['created']) ? date('c', (int) $one['created']) : null,
        ];
    }

    /**
     * @param  array<mixed>  $one
     * @return array<string, mixed>
     */
    private static function invoice(array $one): array
    {
        return [
            'id' => $one['id'] ?? null,
            'number' => $one['number'] ?? null,
            'status' => $one['status'] ?? null,
            'paid' => (bool) ($one['paid'] ?? (($one['status'] ?? null) === 'paid')),
            ...self::amount($one['amount_due'] ?? $one['total'] ?? null, $one['currency'] ?? null),
            'customer' => $one['customer'] ?? null,
            'email' => $one['customer_email'] ?? null,
            'url' => $one['hosted_invoice_url'] ?? null,
            'pdf' => $one['invoice_pdf'] ?? null,
            'due' => isset($one['due_date']) ? date('c', (int) $one['due_date']) : null,
        ];
    }

    /**
     * @param  array<mixed>  $one
     * @return array<string, mixed>
     */
    private static function subscription(array $one): array
    {
        $item = $one['items']['data'][0] ?? [];

        return [
            'id' => $one['id'] ?? null,
            'status' => $one['status'] ?? null,
            'customer' => $one['customer'] ?? null,
            'price' => $item['price']['id'] ?? null,
            ...self::amount($item['price']['unit_amount'] ?? null, $item['price']['currency'] ?? null),
            'every' => $item['price']['recurring']['interval'] ?? null,
            'ends_at_period_end' => (bool) ($one['cancel_at_period_end'] ?? false),
            'period_end' => isset($one['current_period_end']) ? date('c', (int) $one['current_period_end']) : (isset($item['current_period_end']) ? date('c', (int) $item['current_period_end']) : null),
        ];
    }

    /**
     * An amount as people read it, beside the currency and Stripe's own.
     *
     * @return array{amount: float|int|null, currency: string|null, amount_minor: int|null}
     */
    public static function amount(mixed $minor, mixed $currency): array
    {
        $currency = is_string($currency) ? strtolower($currency) : null;

        if (! is_numeric($minor)) {
            return ['amount' => null, 'currency' => $currency, 'amount_minor' => null];
        }

        $places = self::places((string) $currency);
        $major = $places === 0 ? (int) $minor : round((int) $minor / 10 ** $places, $places);

        return ['amount' => $major, 'currency' => $currency, 'amount_minor' => (int) $minor];
    }

    // --- Asking ---

    /**
     * How much, in the currency's smallest unit, and in which.
     *
     * @return array{0: int, 1: string}
     */
    private static function money(StepContext $step): array
    {
        $currency = self::currency($step);

        return [self::minor(SearchSteps::needs($step, 'amount', 'no amount: how much, like 12.50'), $currency), $currency];
    }

    private static function currency(StepContext $step): string
    {
        $currency = strtolower(trim($step->text('currency')) ?: 'thb');

        return preg_match('/^[a-z]{3}$/', $currency) === 1 ? $currency : throw new StepFailed("\"{$currency}\" is not a currency: three letters, like thb or usd.");
    }

    /**
     * 12.50 as Stripe takes it: 1250 -- or 12 for yen, 12500 for dinars.
     */
    private static function minor(string $amount, string $currency): int
    {
        $plain = str_replace([',', ' '], '', trim($amount));

        if (! is_numeric($plain) || (float) $plain < 0) {
            throw new StepFailed("\"{$amount}\" is not an amount: write it like 12.50.");
        }

        return (int) round((float) $plain * 10 ** self::places($currency));
    }

    private static function places(string $currency): int
    {
        return in_array($currency, self::WHOLE, true) ? 0 : (in_array($currency, self::THREE, true) ? 3 : 2);
    }

    private static function quantity(StepContext $step): int
    {
        return max(1, min(999, (int) ($step->setting('quantity') ?: 1)));
    }

    /**
     * @return array<string, string>
     */
    private static function metadata(StepContext $step): array
    {
        return array_slice(array_map(fn ($value) => mb_substr(is_scalar($value) ? (string) $value : (string) json_encode($value), 0, 500), $step->fields('metadata')), 0, 50, true);
    }

    /**
     * An id a step names, of the kind it should be.
     */
    private static function id(StepContext $step, string $setting, string $prefix): string
    {
        $id = trim($step->text($setting));

        if (preg_match('/^[A-Za-z0-9_]{3,255}$/', $id) !== 1) {
            throw new StepFailed($id === '' ? "There is no {$setting}: its id, {$prefix}…" : "\"{$id}\" is not a Stripe id, like {$prefix}…");
        }

        return $id;
    }

    /**
     * A time as Stripe reads one: seconds since 1970.
     */
    private static function when(string $since): int
    {
        try {
            return now()->parse($since)->getTimestamp();
        } catch (\Throwable) {
            throw new StepFailed("\"{$since}\" is not a time it can read, like 2026-10-01 or \"7 days ago\".");
        }
    }

    /**
     * The rows of a list Stripe answered with.
     *
     * @param  array<mixed>  $found
     * @return list<array<mixed>>
     */
    private static function rows(array $found): array
    {
        return array_values(array_filter((array) ($found['data'] ?? []), is_array(...)));
    }

    /**
     * The connection the step names.
     */
    private static function connection(StepContext $step): Connection
    {
        $stripe = References::connection($step->owner(), (string) $step->setting('connection'), [Services::STRIPE])
            ?? throw new StepFailed('Its connection is gone, or is not this workflow\'s.');

        return filled($stripe->secret('secret_key')) ? $stripe : throw new StepFailed("\"{$stripe->name}\" has no key: give it one on the connection.");
    }

    public static function http(Connection $stripe): PendingRequest
    {
        return Http::timeout(30)
            ->withToken((string) $stripe->secret('secret_key'))
            ->withHeaders(['Stripe-Version' => self::VERSION])
            ->acceptJson()
            ->asForm();
    }

    /**
     * A call that only asks.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    private static function ask(Connection $stripe, string $method, string $path, array $query = []): array
    {
        try {
            $response = self::http($stripe)->send($method, self::API.$path, ['query' => self::form($query)]);
        } catch (ConnectionException $e) {
            throw new StepFailed('Could not reach Stripe, or it did not answer in time.', retryable: true);
        }

        return self::answered($response);
    }

    /**
     * A call that makes or changes something, with the step's own
     * idempotency key -- $part told apart when a step makes several -- so
     * Stripe makes it once however often it is sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    private static function send(StepContext $step, Connection $stripe, string $path, array $data, string $part = '', string $method = 'POST'): array
    {
        $key = $step->sendKey().($part === '' ? '' : "-{$part}");
        $response = $step->deliver(Services::STRIPE, "{$method} {$path}", $key, function () use ($stripe, $method, $path, $data, $key) {
            try {
                return self::http($stripe)->withHeaders(['Idempotency-Key' => $key])
                    ->send($method, self::API.$path, $method === 'DELETE' ? ['query' => self::form($data)] : ['form_params' => self::form($data)]);
            } catch (ConnectionException) {
                // Sent again with the same key, Stripe makes it once
                throw new StepFailed('Could not reach Stripe, or it did not answer in time; tried again with the same key, it is made once.', retryable: true);
            }
        });

        return self::answered($response);
    }

    /**
     * Yes and no as Stripe reads them.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function form(array $data): array
    {
        return array_map(fn ($value) => match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => self::form($value),
            default => $value,
        }, $data);
    }

    /**
     * Stripe's answer, or why there is none worth having.
     *
     * @return array<mixed>
     *
     * @throws StepFailed
     */
    private static function answered(Response $response): array
    {
        $json = $response->json();

        if ($response->successful() && is_array($json)) {
            return $json;
        }

        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $said = mb_strimwidth((string) ($error['message'] ?? "nothing it could be read by ({$response->status()})"), 0, 300, '…')
            .(isset($error['param']) ? " ({$error['param']})" : '');
        $after = $response->header('Retry-After');

        throw match (true) {
            $response->status() === 401 => new StepFailed('Stripe refused the key: check it on the connection.'),
            $response->status() === 402 => new StepFailed('Stripe declined it: '.$said.(isset($error['decline_code']) ? " -- {$error['decline_code']}" : '')),
            $response->status() === 403 => new StepFailed("The key may not do that: {$said} A restricted key needs the permission for it."),
            $response->status() === 404 => new StepFailed("Stripe has nothing there: {$said}"),
            $response->status() === 429 => new StepFailed('Stripe asked to slow down.', retryable: true, retryAfter: is_numeric($after) ? (int) $after : 5),
            ($error['type'] ?? null) === 'idempotency_error' => new StepFailed("Stripe has this step's send already, made differently: {$said}"),
            default => new StepFailed("Stripe said: {$said}", retryable: $response->serverError()),
        };
    }
}
