<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/** Fixture-only model; all identities and secrets are synthetic. */
final class CheckoutServerFixture
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var array<string,int> */
    public array $cancels = [];
    /** @var list<string> */
    public array $archives = [];
    /** @var array<string,array<string,mixed>> */
    private array $checkouts = [];
    private int $priceCount = 0;

    public function __construct(private readonly string $fault)
    {
    }

    public function respond(RequestInterface $request): Response
    {
        $this->requests[] = $request;
        $path = $request->getUri()->getPath();
        $raw = (string) $request->getBody();
        /** @var array<string,mixed> $body */
        $body = $raw === '' ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if ($path === '/api/v1/authentication/login') {
            return $this->json(['token' => 'fixture_token', 'expires_at' => '2099-01-01T00:00:00Z']);
        }
        if (str_ends_with($path, '/billing_customers/create')) {
            return $this->json(['id' => 'fixture_customer']);
        }
        if (str_ends_with($path, '/products/create')) {
            return $this->json(['id' => 'fixture_product']);
        }
        if (str_ends_with($path, '/prices/create')) {
            return $this->json(['id' => 'fixture_price_' . ++$this->priceCount]);
        }
        if (str_ends_with($path, '/billing_checkouts/create')) {
            if ($this->fault === 'setup_rejected' && ($body['mode'] ?? null) === 'SETUP') {
                return new Response(400, [], '{"code":"invalid_argument"}');
            }
            $id = 'fixture_checkout_' . (count($this->checkouts) + 1);
            unset($body['request_id']);
            $body += ['id' => $id, 'currency' => 'EUR', 'status' => 'ACTIVE',
                'created_at' => '2026-11-08T12:00:00Z', 'expires_at' => '2026-11-08T13:00:00Z'];
            $body['url'] = 'https://checkout.example.test/fixture_secret';
            if (($body['ui_mode'] ?? null) !== 'HOSTED') {
                $body['client_secret'] = 'fixture_secret';
            }
            $this->checkouts[$id] = $body;
            return $this->json(['id' => $id]);
        }
        if (preg_match('~/billing_checkouts/(fixture_checkout_[0-9]+)(?:/(update|cancel))?$~D', $path, $matches)) {
            $id = $matches[1];
            $action = $matches[2] ?? '';
            if ($action === 'cancel') {
                $this->cancels[$id] = ($this->cancels[$id] ?? 0) + 1;
                if ($this->fault === 'ambiguous_cancel' && $id === 'fixture_checkout_1') {
                    throw new \RuntimeException('Synthetic connection loss after send.');
                }
                $this->checkouts[$id]['status'] = 'CANCELLED';
                unset($this->checkouts[$id]['url']);
            } elseif ($action === 'update') {
                $this->checkouts[$id]['metadata'] = $body['metadata'];
            }
            $data = $this->checkouts[$id];
            if ($action === '') {
                switch ($this->fault) {
                    case 'currency':
                        $data['currency'] = 'USD';
                        break;
                    case 'secret':
                        unset($data['client_secret']);
                        break;
                    case 'expiry':
                        $data['expires_at'] = '2026-11-08T14:00:00Z';
                        break;
                    case 'relative_time':
                        $data['created_at'] = 'now';
                        $data['expires_at'] = '+1 hour';
                        break;
                    case 'basic_offset':
                        $data['created_at'] = '2026-11-08T12:00:00+0000';
                        $data['expires_at'] = '2026-11-08T13:00:00+0000';
                        break;
                    case 'invalid_date':
                        $data['created_at'] = '2026-02-30T12:00:00Z';
                        $data['expires_at'] = '2026-02-30T13:00:00Z';
                        break;
                    case 'quantity':
                        $data['line_items'] = [['price_id' => 'fixture_price_1', 'quantity' => 2]];
                        break;
                    case 'metadata':
                        $data['metadata'] = [];
                        break;
                    case 'cancelled_url':
                        if (($data['status'] ?? null) === 'CANCELLED') {
                            $data['url'] = 'https://checkout.example.test/fixture_secret';
                        }
                        break;
                }
            }
            return $this->json($data);
        }
        if (preg_match('~/(prices|products)/([^/]+)/update$~D', $path, $matches)) {
            $this->archives[] = $matches[2];
            return $this->json(['id' => $matches[2], 'active' => false]);
        }
        throw new \RuntimeException('Unexpected synthetic request.');
    }

    /** @param array<string,mixed> $data */
    private function json(array $data): Response
    {
        return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
    }
}
