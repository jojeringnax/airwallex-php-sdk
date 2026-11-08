<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryRuntime;
use RuntimeException;

/** Files are synthetic; this fixture never starts Node, cloudflared or a remote receiver. */
final class WebhookDeliveryFixtureRuntime implements WebhookDeliveryRuntime
{
    public const int NOW = 1830297600123;
    public int $captures = 0;
    public bool $stopped = false;
    public bool $emit = true;
    public bool $failPoll = false;
    public bool $failStop = false;
    /** @var (Closure(string):string)|null */
    public ?Closure $bodyFilter = null;
    /** @var (Closure(array<string,mixed>):array<string,mixed>)|null */
    public ?Closure $metadataFilter = null;
    /** @var (Closure():void)|null */
    public ?Closure $onStop = null;
    private string $registration = '';
    private readonly string $folder;
    private readonly string $path;
    private readonly string $source;
    private readonly string $context;
    /** @var (Closure(array<array-key,mixed>):array<array-key,mixed>)|null */
    public ?Closure $eventFilter = null;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->folder = self::field($config, 'folder');
        $this->path = self::field($config, 'path');
        $this->source = self::field($config, 'source');
        $this->context = self::field($config, 'context');
    }

    /** @param array<string,mixed> $config */
    private static function field(array $config, string $name): string
    {
        $value = $config[$name] ?? null;
        return is_string($value) ? $value : throw new RuntimeException('Invalid synthetic config.');
    }

    public function destination(): string
    {
        return 'https://synthetic-private.trycloudflare.com' . $this->path;
    }

    public function arm(string $registrationFingerprint): void
    {
        $this->registration = $registrationFingerprint;
    }

    /** @param array<array-key,mixed> $object */
    public function capture(array $object, string $name): void
    {
        if (!$this->emit) {
            return;
        }
        if ($this->registration === '') {
            throw new RuntimeException('A synthetic event was triggered before private arming.');
        }
        $event = ['id' => 'SYNTHETIC_PRIVATE_EVENT_' . $this->captures, 'version' => '2026-08-21', 'name' => $name,
            'created_at' => '2028-01-01T00:00:00Z', 'account_id' => 'SYNTHETIC_PRIVATE_ACCOUNT',
            'data' => ['object' => $object]];
        if ($this->eventFilter !== null) {
            $event = ($this->eventFilter)($event);
        }
        $body = (new JsonCodec())->encode($event, ['/data/object/amount', '/data/object/captured_amount']);
        if ($this->bodyFilter !== null) {
            $body = ($this->bodyFilter)($body);
        }
        $timestamp = '000' . self::NOW;
        $stem = $this->folder . '/capture-' . sprintf('%06d', ++$this->captures);
        file_put_contents($stem . '.body', $body);
        chmod($stem . '.body', 0600);
        $metadata = ['format' => 1, 'timestamp' => $timestamp,
            'signature' => hash_hmac('sha256', $timestamp . $body, 'SYNTHETIC_PRIVATE_SECRET'),
            'received_at_ms' => self::NOW, 'length' => strlen($body), 'sha256' => hash('sha256', $body),
            'context' => $this->context, 'source' => $this->source, 'registration' => $this->registration];
        if ($this->metadataFilter !== null) {
            $metadata = ($this->metadataFilter)($metadata);
        }
        file_put_contents($stem . '.json', json_encode($metadata, JSON_THROW_ON_ERROR));
        chmod($stem . '.json', 0600);
    }

    /** @return array<string,int|bool> */
    public function poll(): array
    {
        if ($this->failPoll) {
            throw new RuntimeException('SYNTHETIC_PRIVATE_RUNTIME_ERROR');
        }
        return ['seen' => $this->captures, 'stored' => $this->captures, 'refused' => 0, 'invalid' => 0,
            'stream_failure' => false, 'persistence_failure' => false, 'limit_failure' => false,
            'closed' => false, 'supervisor_failed' => false, 'log_limit_failure' => false, 'tunnel_exited' => false];
    }

    /** @return array{receiver_closed:bool,tunnel_stopped:bool,logs_closed:bool,failed:bool} */
    public function stop(): array
    {
        $this->stopped = true;
        if ($this->onStop !== null) {
            ($this->onStop)();
        }
        return ['receiver_closed' => !$this->failStop, 'tunnel_stopped' => !$this->failStop,
            'logs_closed' => !$this->failStop, 'failed' => $this->failStop];
    }
}
