<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Private bounded IPC. Never relay a child URL, stderr or exception to the operator. */
final class WebhookDeliverySupervisor implements WebhookDeliveryRuntime
{
    /** @var resource */
    private $process;
    /** @var array{resource,resource,resource} */
    private array $pipes;
    private string $pending = '';
    private string $destination;
    private bool $stopped = false;
    private int $readBytes = 0;
    private const string BINARY = '2136431d5eb467bf447d2ee514b713e192719b143aec1d9e09bf7dc290d5bb12';

    public static function verifyBinary(string $path): void
    {
        $size = @filesize($path);
        if (
            realpath($path) !== $path || is_link($path) || !is_file($path) || !is_executable($path)
            || $size === false || $size > 100000000 || @hash_file('sha256', $path) !== self::BINARY
        ) {
            throw new RuntimeException('The pinned temporary tunnel runtime is unavailable.');
        }
    }

    /** @param array{folder:string,path:string,context:string,source:string,duration_ms:int,live:bool,binary:string} $config */
    public function __construct(string $node, #[SensitiveParameter] array $config)
    {
        self::verifyBinary($config['binary']);
        if (realpath($node) !== $node || is_link($node) || !is_file($node) || !is_executable($node)) {
            throw new RuntimeException('The explicit local Node runtime is unavailable.');
        }
        WebhookDeliveryStore::directory($config['folder']);
        $process = @proc_open(
            [$node, dirname(__DIR__, 4) . '/tools/webhooks/webhook-supervisor.cjs'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $config['folder'],
            ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'TMPDIR' => $config['folder']]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The private receiver supervisor could not start.');
        }
        $this->process = $process;
        $this->pipes = self::pipes($pipes);
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        try {
            $response = $this->request($config, 65);
            $destination = $response['destination'] ?? null;
            if (
                ($response['type'] ?? null) !== 'ready' || !is_string($destination)
                || !WebhookDeliveryApi::destination($destination)
            ) {
                throw new RuntimeException('The private receiver did not become ready.');
            }
            $this->destination = $destination;
        } catch (Throwable) {
            $this->terminate();
            throw new RuntimeException('The private receiver supervisor did not become ready.');
        }
    }

    public function destination(): string
    {
        return $this->destination;
    }

    public function arm(#[SensitiveParameter] string $registrationFingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $registrationFingerprint) !== 1) {
            throw new RuntimeException('Private registration binding is unavailable.');
        }
        $response = $this->request(['type' => 'arm', 'registration' => $registrationFingerprint]);
        if (($response['type'] ?? null) !== 'armed') {
            throw new RuntimeException('The private receiver did not bind its registration.');
        }
    }

    /** @return array<string,int|bool> */
    public function poll(): array
    {
        $response = $this->request(['type' => 'poll']);
        if (($response['type'] ?? null) !== 'observed') {
            throw new RuntimeException('The private receiver observation is unavailable.');
        }
        $safe = [];
        foreach (['seen', 'stored', 'refused', 'invalid'] as $field) {
            $value = $response[$field] ?? null;
            if (!is_int($value) || $value < 0 || $value > 1000000) {
                throw new RuntimeException('Private receiver counts do not match their bounded contract.');
            }
            $safe[$field] = $value;
        }
        foreach (
            ['stream_failure', 'persistence_failure', 'limit_failure', 'closed',
            'supervisor_failed', 'log_limit_failure', 'tunnel_exited'] as $field
        ) {
            $value = $response[$field] ?? null;
            if (!is_bool($value)) {
                throw new RuntimeException('Private receiver state does not match its bounded contract.');
            }
            $safe[$field] = $value;
        }
        return $safe;
    }

    /** @return array{receiver_closed:bool,tunnel_stopped:bool,logs_closed:bool,failed:bool} */
    public function stop(): array
    {
        $safe = ['receiver_closed' => false, 'tunnel_stopped' => false, 'logs_closed' => false, 'failed' => true];
        if ($this->stopped) {
            return $safe;
        }
        try {
            $response = $this->request(['type' => 'stop'], 8);
            if (($response['type'] ?? null) === 'stopped') {
                foreach (array_keys($safe) as $field) {
                    if (!is_bool($response[$field] ?? null)) {
                        throw new RuntimeException('Private supervisor cleanup state is unavailable.');
                    }
                    $safe[$field] = $response[$field];
                }
            }
        } catch (Throwable) {
            // Unknown teardown remains a failed result, even if force termination succeeds below.
        }
        $this->terminate();
        return $safe;
    }

    /**
     * @param array<string,mixed> $command
     * @return array<array-key,mixed>
     */
    private function request(#[SensitiveParameter] array $command, int $seconds = 3): array
    {
        if ($this->stopped) {
            throw new RuntimeException('The private supervisor is stopped.');
        }
        $data = json_encode($command, JSON_THROW_ON_ERROR) . "\n";
        if (strlen($data) > 8192) {
            throw new RuntimeException('Private supervisor input exceeds its boundary.');
        }
        $until = hrtime(true) + $seconds * 1000000000;
        $sent = 0;
        while ($sent < strlen($data) && hrtime(true) < $until) {
            $written = @fwrite($this->pipes[0], substr($data, $sent));
            if ($written === false) {
                throw new RuntimeException('Private supervisor input is unavailable.');
            }
            $sent += $written;
            if ($written === 0) {
                usleep(10000);
            }
        }
        if ($sent !== strlen($data)) {
            throw new RuntimeException('Private supervisor input deadline exceeded.');
        }
        while (hrtime(true) < $until) {
            $error = @fread($this->pipes[2], 8192);
            if ($error !== false && $error !== '') {
                throw new RuntimeException('The receiver supervisor failed privately.');
            }
            $part = @fread($this->pipes[1], 8192);
            if ($part === false) {
                throw new RuntimeException('Private supervisor output is unavailable.');
            }
            $this->readBytes += strlen($part);
            $this->pending .= $part;
            if (strlen($this->pending) > 8192 || $this->readBytes > 131072) {
                throw new RuntimeException('Private supervisor output exceeds its boundary.');
            }
            $newline = strpos($this->pending, "\n");
            if ($newline !== false) {
                $line = substr($this->pending, 0, $newline);
                $this->pending = substr($this->pending, $newline + 1);
                if ($this->pending !== '') {
                    throw new RuntimeException('Private supervisor returned an ambiguous message.');
                }
                $response = json_decode($line, true, depth: 16, flags: JSON_THROW_ON_ERROR);
                if (!is_array($response) || array_is_list($response)) {
                    throw new RuntimeException('Private supervisor returned an invalid message.');
                }
                return $response;
            }
            if (feof($this->pipes[1])) {
                break;
            }
            usleep(10000);
        }
        throw new RuntimeException('Private supervisor output deadline exceeded.');
    }

    private function terminate(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        @fclose($this->pipes[0]); // EOF asks the Node supervisor to stop its own children, including during startup.
        $until = hrtime(true) + 6000000000;
        while (($status = proc_get_status($this->process))['running'] && hrtime(true) < $until) {
            foreach ([$this->pipes[1], $this->pipes[2]] as $pipe) {
                @fread($pipe, 8192); // Drain privately, never forward values.
            }
            usleep(10000);
        }
        if ($status['running']) {
            @proc_terminate($this->process);
            usleep(100000);
            if (proc_get_status($this->process)['running']) {
                @proc_terminate($this->process, 9);
            }
        }
        @fclose($this->pipes[1]);
        @fclose($this->pipes[2]);
        @proc_close($this->process);
    }

    /** @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes)) {
            throw new RuntimeException('Private supervisor pipes are unavailable.');
        }
        $input = $pipes[0] ?? null;
        $output = $pipes[1] ?? null;
        $error = $pipes[2] ?? null;
        if (!is_resource($input) || !is_resource($output) || !is_resource($error)) {
            throw new RuntimeException('Private supervisor pipes are unavailable.');
        }
        return [$input, $output, $error];
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['receiver' => '[private]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private receiver supervisors cannot be serialized.');
    }
}
