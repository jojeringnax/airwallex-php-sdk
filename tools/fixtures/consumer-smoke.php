<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\WebhookVerificationException;
use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\Airwallex\Webhook\WebhookVerifier;
use Psr\Http\Message\RequestInterface;

require __DIR__ . '/vendor/autoload.php';

$expectConsumer = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach (['phpunit/phpunit', 'phpstan/phpstan', 'squizlabs/php_codesniffer'] as $developmentPackage) {
    $expectConsumer(
        !InstalledVersions::isInstalled($developmentPackage),
        'SDK development dependency leaked into consumer.'
    );
}
$expectConsumer(interface_exists(\Psr\SimpleCache\CacheInterface::class), 'Optional cache interface is unavailable.');
/** @var list<array{request:\Psr\Http\Message\RequestInterface}> $history */
$history = [];
$mock = new MockHandler([
    new Response(201, [], '{"token":"synthetic-consumer-token","expires_at":"2100-01-01T00:00:00Z"}'),
    new Response(201, [], '{"id":"synthetic-billing-customer"}'),
    new Response(200, [], '{"items":[],"page_after":"next-bookmark","page_before":null}'),
    new Response(201, [], '{"id":"synthetic-intent","amount":19.90,"unknown_numeric":12345678901234567890.123400}'),
    new Response(200, [], '{"items":[],"has_more":false}'),
    new Response(200, [], '{"id":"synthetic-subscription","status":"ACTIVE"}'),
    new Response(201, [], '{"id":"synthetic-coupon","amount_off":10.000000000001}'),
    new Response(201, [], '{"id":"synthetic-invoice","status":"DRAFT"}'),
]);
$handler = HandlerStack::create($mock);
$handler->push(Middleware::history($history));
$factory = new HttpFactory();
$client = new Client(
    environment: Environment::Sandbox,
    credentials: new Credentials('synthetic-client-id', 'synthetic-api-key'),
    httpClient: new HttpClient(['handler' => $handler, 'verify' => true, 'allow_redirects' => false]),
    requestFactory: $factory,
    streamFactory: $factory,
);
$customer = $client->billing()->customers()->create([
    'request_id' => 'persisted-billing-action', 'email' => 'buyer@example.test',
]);
$expectConsumer($customer['id'] === 'synthetic-billing-customer', 'Installed Billing operation failed.');
$billingPage = $client->billing()->customers()->list(['page_size' => 20]);
$expectConsumer(
    $billingPage === ['items' => [],
     'page_after' => 'next-bookmark',
     'page_before' => null],
    'Billing envelope changed.'
);
$intent = $client->payments()->paymentIntents()->create([
    'request_id' => 'persisted-intent-action', 'merchant_order_id' => 'synthetic-order',
    'amount' => '19.90', 'currency' => 'EUR', 'metadata' => ['opaque_id' => '00123'],
]);
$expectConsumer($intent['amount'] === '19.90', 'Installed Payments amount lost precision.');
$expectConsumer($intent['unknown_numeric'] === '12345678901234567890.123400', 'Unknown numeric text was changed.');
$paymentPage = $client->payments()->paymentIntents()->list(['page_num' => 0, 'page_size' => 10]);
$expectConsumer($paymentPage === ['items' => [], 'has_more' => false], 'Payments envelope changed.');
$subscription = $client->billing()->subscriptions()->update('synthetic-subscription', [
    'request_id' => 'persisted-proration-action',
    'items' => [['id' => 'synthetic-item', 'quantity' => 3, 'proration_mode' => 'CUSTOM',
        'proration_value' => ['charge_amount' => '100.000000000000000001', 'credit_amount' => '20.00']]],
    'proration_date' => '2028-02-29T10:15:30.123456789+01:00',
]);
$expectConsumer($subscription['id'] === 'synthetic-subscription', 'Installed Subscription Update failed.');
$coupon = $client->billing()->coupons()->create([
    'request_id' => 'persisted-coupon-action', 'name' => 'Exact flat coupon', 'discount_model' => 'FLAT',
    'amount_off' => '10.000000000001', 'currency' => 'USD', 'duration_type' => 'ONCE',
]);
$expectConsumer($coupon['amount_off'] === '10.000000000001', 'Installed coupon schema lost exact money.');
$invoice = $client->billing()->invoices()->create([
    'request_id' => 'persisted-invoice-action', 'billing_customer_id' => 'synthetic-billing-customer',
    'currency' => 'USD', 'collection_method' => 'OUT_OF_BAND',
    'enable_automatic_tax' => false, 'default_tax_percent' => '7.125000000001',
    'discounts' => [['type' => 'COUPON', 'coupon' => ['id' => 'synthetic-coupon']]],
]);
$expectConsumer($invoice['id'] === 'synthetic-invoice', 'Installed Invoice creation failed.');
if (!is_array($history)) {
    throw new RuntimeException('Consumer request capture is unavailable.');
}
$expectConsumer(count($history) === 8, 'Installed SDK did not reuse its authentication token.');
$requests = [];
foreach ($history as $entry) {
    $request = is_array($entry) ? ($entry['request'] ?? null) : null;
    if (!$request instanceof RequestInterface) {
        throw new RuntimeException('Consumer request capture has an unexpected type.');
    }
    $requests[] = $request;
    $expectConsumer($request->getUri()->getHost() === 'api.sandbox.airwallex.com', 'Unexpected consumer request host.');
    $expectConsumer($request->getHeaderLine('x-api-version') === '2026-08-21', 'Installed API pin changed.');
}
$intentBody = (string) $requests[3]->getBody();
$expectConsumer(str_contains($intentBody, '"amount":19.90'), 'Exact financial wire number was changed.');
$expectConsumer(str_contains($intentBody, '"opaque_id":"00123"'), 'Opaque metadata was converted to a number.');
$expectConsumer(str_contains((string) $requests[4]->getUri(), 'page_num=0'), 'Zero-based paging was changed.');
$expectConsumer(
    (string) $requests[5]->getBody() === '{"request_id":"persisted-proration-action","items":[{'
        . '"id":"synthetic-item","quantity":3,"proration_mode":"CUSTOM","proration_value":{'
        . '"charge_amount":"100.000000000000000001","credit_amount":"20.00"}}],'
        . '"proration_date":"2028-02-29T10:15:30.123456789+01:00"}',
    'Installed custom amounts or calculation timestamp changed.'
);
$expectConsumer(
    str_contains((string) $requests[6]->getBody(), '"amount_off":10.000000000001'),
    'Installed coupon wire amount changed.'
);
$expectConsumer(
    str_contains((string) $requests[7]->getBody(), '"default_tax_percent":7.125000000001'),
    'Installed tax rate lost precision.'
);
$body = '{"name":"future.event","amount":19.9000,"nested":{},"list":[]}';
$timestamp = '1700000000000';
$signature = hash_hmac('sha256', $timestamp . $body, 'synthetic-webhook-secret');
$verifier = new WebhookVerifier('synthetic-webhook-secret', 0, static fn (): int => 1700000000000);
$verifier->verify($body, $timestamp, $signature);
$event = WebhookEvent::fromJson($body)->toArray();
$expectConsumer(
    $event['amount'] === '19.9000' && $event['nested'] instanceof stdClass,
    'Installed webhook decoding changed.'
);
try {
    $verifier->verify($body . ' ', $timestamp, $signature);
    throw new RuntimeException('Installed webhook verifier accepted tampered bytes.');
} catch (WebhookVerificationException) {
    // Expected failure after changing the exact signed body.
}
$directBody = '{"id":"event_example","name":"billing_checkout.created","version":"2026-08-21",'
    . '"org_id":"org_example","created_at":"2026-11-09T00:00:00Z","data":{'
    . '"id":"checkout_example","status":"ACTIVE","line_items":[{"quantity":2,"rate_card_version":7}],'
    . '"amount":19.900000000000000001,"extra":9223372036854775808}}';
$verifier->verify($directBody, $timestamp, hash_hmac('sha256', $timestamp . $directBody, 'synthetic-webhook-secret'));
$direct = WebhookEvent::fromJson($directBody)->toArray();
$expectConsumer(
    is_array($direct['data']) && !array_key_exists('object', $direct['data'])
        && $direct['data']['line_items'] === [['quantity' => 2, 'rate_card_version' => 7]]
        && $direct['data']['amount'] === '19.900000000000000001'
        && $direct['data']['extra'] === '9223372036854775808',
    'Installed direct Checkout shape or numeric representation changed.'
);
echo "Clean consumer Billing, Payments, precision, paging, token reuse and webhooks passed.\n";
