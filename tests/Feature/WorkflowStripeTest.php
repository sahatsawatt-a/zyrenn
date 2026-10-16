<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Engine;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Stripe in workflows: each of its calls a step that asks as Stripe reads it
 * -- amounts in the currency's smallest unit, what it makes under the step's
 * own idempotency key -- and a trigger that starts on what happens on the
 * account, signed with the endpoint's secret. Through the real queue;
 * Stripe is faked.
 */
class WorkflowStripeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'services.workflows.public_url' => 'https://hooks.example.test']);
        Http::preventStrayRequests();
    }

    private function node(string $key, string $type, array $config = []): array
    {
        return ['id' => $key, 'key' => $key, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    private function edge(string $from, string $to): array
    {
        return ['id' => "{$from}.out.{$to}", 'source' => $from, 'sourceHandle' => 'out', 'target' => $to];
    }

    private function runChain(User $user, array $steps, array $payload = ['n' => 1]): WorkflowRun
    {
        $nodes = [$this->node('start', 'trigger.manual', ['payload' => json_encode($payload)]), ...$steps];
        $edges = [];

        foreach (array_slice($nodes, 1) as $at => $node) {
            $edges[] = $this->edge($nodes[$at]['id'], $node['id']);
        }

        $workflow = Workflow::factory()->for($user)->steps($nodes, $edges)->create();
        $this->assertSame([], References::allProblems($workflow));
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        return $run->fresh();
    }

    private function step(WorkflowRun $run, string $key): WorkflowNodeRun
    {
        return $run->steps()->where('node_key', $key)->sole();
    }

    private function fakeStripe(): void
    {
        Http::fake(function (Request $request) {
            $path = str_replace('https://api.stripe.com/v1', '', (string) strtok($request->url(), '?'));

            return Http::response(match ($request->method().' '.$path) {
                'POST /prices' => ['id' => 'price_1', 'object' => 'price'],
                'POST /payment_links' => ['id' => 'plink_1', 'url' => 'https://buy.stripe.com/test_1', 'active' => true],
                'POST /checkout/sessions' => ['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1', 'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 2400, 'currency' => 'jpy', 'customer_email' => 'ada@example.com', 'client_reference_id' => 'chat-9', 'metadata' => [], 'expires_at' => 1_800_000_000],
                'GET /payment_intents/pi_1' => ['id' => 'pi_1', 'currency' => 'thb', 'amount' => 125000],
                'POST /refunds' => ['id' => 're_1', 'status' => 'succeeded', 'amount' => 50000, 'currency' => 'thb', 'payment_intent' => 'pi_1'],
                'GET /customers' => ['data' => [['id' => 'cus_1', 'email' => 'ada@example.com', 'name' => 'Ada', 'metadata' => []]], 'has_more' => false],
                'POST /invoices' => ['id' => 'in_1', 'object' => 'invoice'],
                'POST /invoiceitems' => ['id' => 'ii_'.random_int(1, 999)],
                'POST /invoices/in_1/send' => ['id' => 'in_1', 'number' => 'ZY-0001', 'status' => 'open', 'amount_due' => 650000, 'currency' => 'thb', 'customer' => 'cus_1', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/1'],
                'GET /balance' => ['livemode' => false, 'available' => [['amount' => 123456, 'currency' => 'thb']], 'pending' => [['amount' => 500, 'currency' => 'usd']]],
                default => ['error' => ['type' => 'invalid_request_error', 'message' => 'No such thing']],
            }, str_starts_with($request->method().' '.$path, 'GET /balance') || ! str_contains($path, 'nothing') ? 200 : 404);
        });
    }

    public function test_each_stripe_call_is_a_step_that_asks_as_stripe_reads_it_and_makes_things_once()
    {
        $this->fakeStripe();
        $user = User::factory()->create();
        $on = fn (array $settings = []) => ['connection' => Connection::factory()->stripe()->for($user)->create()->ref_id, ...$settings];

        $run = $this->runChain($user, [
            $this->node('link', 'stripe.payment_link_create', $on(['amount' => '{{ trigger.price }}', 'currency' => 'THB', 'name' => 'Khao soi for two', 'quantity' => 2, 'metadata' => [['name' => 'order', 'value' => '1234']]])),
            $this->node('pay', 'stripe.checkout_create', $on(['mode' => 'payment', 'amount' => '1,200', 'currency' => 'jpy', 'name' => 'Ramen', 'success_url' => 'https://shop.example/thanks', 'email' => 'ada@example.com', 'reference' => 'chat-9'])),
            $this->node('back', 'stripe.refund_create', $on(['payment' => 'pi_1', 'amount' => '500', 'reason' => 'duplicate'])),
            $this->node('who', 'stripe.customer_find', $on(['email' => 'ada@example.com'])),
            $this->node('bill', 'stripe.invoice_create', $on(['customer' => '{{ node("who").customer.id }}', 'items' => [['name' => 'Design work', 'value' => '4500'], ['name' => 'Printing', 'value' => '2,000']], 'currency' => 'thb', 'days' => 14, 'send' => 'yes'])),
            $this->node('money', 'stripe.balance', $on()),
        ], ['price' => '149.50']);

        $failed = $run->steps()->where('status', '!=', NodeStatus::Succeeded->value)->first();
        $this->assertNull($failed, $failed ? "{$failed->node_key}: ".json_encode($failed->error) : '');
        $this->assertSame(RunStatus::Succeeded, $run->status);

        // Each as Stripe reads it: a form, every value text
        $asked = Http::recorded()->map(function (array $pair) {
            parse_str($pair[0]->body(), $form);

            return [$pair[0]->method().' '.str_replace('https://api.stripe.com/v1', '', (string) strtok($pair[0]->url(), '?')), $form, $pair[0]->header('Idempotency-Key')[0] ?? null];
        })->all();
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer sk_test_key') && $request->hasHeader('Stripe-Version', '2024-06-20'));

        // A link: a price made for the amount -- 149.50 baht as 14950 satang -- then the link at it
        $this->assertSame(['POST /prices', ['currency' => 'thb', 'unit_amount' => '14950', 'product_data' => ['name' => 'Khao soi for two']]], array_slice($asked[0], 0, 2));
        $this->assertSame(['line_items' => [['price' => 'price_1', 'quantity' => '2']], 'metadata' => ['order' => '1234']], $asked[1][1]);
        // Each thing a step makes has a key of its own, and the step's keys are its own
        $this->assertNotSame($asked[0][2], $asked[1][2]);
        $this->assertStringEndsWith('-price', (string) $asked[0][2]);
        // Yen has no smaller unit
        $this->assertSame(['currency' => 'jpy', 'unit_amount' => '1200', 'product_data' => ['name' => 'Ramen']], $asked[2][1]['line_items'][0]['price_data']);
        $this->assertSame(['payment', 'https://shop.example/thanks', 'ada@example.com', 'chat-9'], [$asked[2][1]['mode'], $asked[2][1]['success_url'], $asked[2][1]['customer_email'], $asked[2][1]['client_reference_id']]);
        // A refund of part: the payment is asked for its currency first
        $this->assertSame(['GET /payment_intents/pi_1', 'POST /refunds'], [$asked[3][0], $asked[4][0]]);
        $this->assertSame(['payment_intent' => 'pi_1', 'amount' => '50000', 'reason' => 'duplicate'], $asked[4][1]);
        // An invoice: made, a line each, then sent
        $this->assertSame(['POST /invoices', 'POST /invoiceitems', 'POST /invoiceitems', 'POST /invoices/in_1/send'], array_column(array_slice($asked, 6, 4), 0));
        $this->assertSame(['cus_1', 'send_invoice', '14'], [$asked[6][1]['customer'], $asked[6][1]['collection_method'], $asked[6][1]['days_until_due']]);
        $this->assertSame([['450000', 'Design work'], ['200000', 'Printing']], [[$asked[7][1]['amount'], $asked[7][1]['description']], [$asked[8][1]['amount'], $asked[8][1]['description']]]);

        $this->assertSame('https://buy.stripe.com/test_1', $this->step($run, 'link')->output['url']);
        $this->assertSame(['https://checkout.stripe.com/c/pay/cs_1', 2400, 'jpy', false], [$this->step($run, 'pay')->output['url'], $this->step($run, 'pay')->output['amount'], $this->step($run, 'pay')->output['currency'], $this->step($run, 'pay')->output['paid']]);
        $this->assertSame([500, 'succeeded'], [$this->step($run, 'back')->output['amount'], $this->step($run, 'back')->output['status']]);
        $this->assertSame([true, 'cus_1'], [$this->step($run, 'who')->output['found'], $this->step($run, 'who')->output['customer']['id']]);
        $this->assertSame(['ZY-0001', 6500, 'https://invoice.stripe.com/i/1'], [$this->step($run, 'bill')->output['number'], $this->step($run, 'bill')->output['amount'], $this->step($run, 'bill')->output['url']]);
        $this->assertSame([1234.56, false], [$this->step($run, 'money')->output['available'][0]['amount'], $this->step($run, 'money')->output['live']]);
        $this->assertStringNotContainsString('sk_test_key', (string) json_encode($run->steps()->get()->toArray()));
    }

    public function test_stripe_says_why_when_it_will_not()
    {
        Http::fake([
            'api.stripe.com/v1/refunds' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Charge ch_1 has already been refunded.', 'param' => 'charge']], 400),
            'api.stripe.com/v1/balance' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401),
            'api.stripe.com/v1/customers' => Http::response(['error' => ['type' => 'card_error', 'message' => 'Your card was declined.', 'decline_code' => 'insufficient_funds']], 402),
        ]);
        $user = User::factory()->create();
        $stripe = Connection::factory()->stripe()->for($user)->create()->ref_id;
        $error = fn (string $type, array $settings) => (string) ($this->step($this->runChain($user, [$this->node('one', $type, ['connection' => $stripe, ...$settings])]), 'one')->error['message'] ?? '');

        $this->assertSame('Stripe said: Charge ch_1 has already been refunded. (charge)', $error('stripe.refund_create', ['payment' => 'ch_1']));
        $this->assertSame('Stripe refused the key: check it on the connection.', $error('stripe.balance', []));
        $this->assertStringContainsString('declined it: Your card was declined. -- insufficient_funds', $error('stripe.customer_create', ['email' => 'a@b.c']));
        $this->assertStringContainsString('is not an amount', $error('stripe.payment_link_create', ['amount' => 'twelve', 'name' => 'x']));
        $this->assertStringContainsString('is not a currency', $error('stripe.payment_link_create', ['amount' => '1', 'currency' => 'baht', 'name' => 'x']));
    }

    public function test_something_happening_on_stripe_starts_a_run_only_when_signed_and_of_the_kind_it_waits_for()
    {
        $user = User::factory()->create();
        $stripe = Connection::factory()->stripe()->for($user)->create();
        $nodes = [
            $this->node('paid', 'trigger.stripe', ['connection' => $stripe->ref_id, 'on' => 'checkout']),
            $this->node('said', 'data.set', ['fields' => [['name' => 'line', 'value' => '{{ trigger.text }}'], ['name' => 'order', 'value' => '{{ trigger.metadata.order }}'], ['name' => 'amount', 'value' => '{{ trigger.amount }}']]]),
        ];
        $workflow = Workflow::factory()->for($user)->steps($nodes, [$this->edge('paid', 'said')])->create();
        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        $send = function (array $event, string $secret = 'whsec_secret', ?int $at = null) use ($stripe) {
            $body = json_encode($event);
            $at ??= time();

            return $this->call('POST', route('hooks.stripe', $stripe->ref_id), [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$at},v1=".hash_hmac('sha256', "{$at}.{$body}", $secret),
            ], $body);
        };
        $event = fn (string $id, string $type) => ['id' => $id, 'type' => $type, 'livemode' => false, 'data' => ['object' => [
            'object' => 'checkout.session', 'id' => 'cs_1', 'amount_total' => 14950, 'currency' => 'thb', 'payment_status' => 'paid',
            'customer_details' => ['email' => 'ada@example.com', 'name' => 'Ada'], 'payment_intent' => 'pi_1', 'metadata' => ['order' => '1234'],
        ]]];

        // Not signed with its secret, or signed too long ago: turned away
        $send($event('evt_0', 'checkout.session.completed'), 'whsec_wrong')->assertForbidden();
        $send($event('evt_0', 'checkout.session.completed'), at: time() - 600)->assertForbidden();
        // Another kind of event starts nothing
        $send($event('evt_1', 'payment_intent.succeeded'))->assertOk();
        $this->assertSame(0, WorkflowRun::count());

        $send($event('evt_2', 'checkout.session.completed'))->assertOk();
        // The same event sent again starts nothing more
        $send($event('evt_2', 'checkout.session.completed'))->assertOk();
        $this->work();

        $run = WorkflowRun::sole();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(['line' => 'A checkout of 149.50 THB was paid from ada@example.com (test)', 'order' => '1234', 'amount' => 149.5], $this->step($run, 'said')->output);
    }
}
