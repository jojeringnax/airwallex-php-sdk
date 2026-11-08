<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliverySupervisor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

/** PHP IPC is exercised with an owned PHP fixture process; cloudflared is never launched. */
final class WebhookDeliverySupervisorTest extends TestCase
{
    /**
     * @return array{WebhookDeliverySupervisor,resource}
     */
    private function fixture(string $program): array
    {
        $process = proc_open(
            [PHP_BINARY, '-r', $program],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        [$input, $output, $error] = self::pipes($pipes);
        foreach ([$input, $output, $error] as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $reflection = new ReflectionClass(WebhookDeliverySupervisor::class);
        $supervisor = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('process')->setValue($supervisor, $process);
        $reflection->getProperty('pipes')->setValue($supervisor, [$input, $output, $error]);
        $reflection->getProperty('destination')->setValue($supervisor, 'https://SYNTHETIC_PRIVATE.test');
        return [$supervisor, $process];
    }

    /** @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes)) {
            throw new RuntimeException('Synthetic process pipes are unavailable.');
        }
        $input = $pipes[0] ?? null;
        $output = $pipes[1] ?? null;
        $error = $pipes[2] ?? null;
        if (!is_resource($input) || !is_resource($output) || !is_resource($error)) {
            throw new RuntimeException('Synthetic process pipes are unavailable.');
        }
        return [$input, $output, $error];
    }

    public function testOwnedPrivateProtocolAndTeardownReapsOnlyItsFixtureProcess(): void
    {
        $program = <<<'CODE'
while (($line = fgets(STDIN)) !== false) {
    $value = json_decode($line, true);
    if ($value['type'] === 'arm') {
        echo "{\"type\":\"armed\"}\n";
    } elseif ($value['type'] === 'poll') {
        echo json_encode(['type' => 'observed', 'seen' => 2, 'stored' => 2, 'refused' => 0, 'invalid' => 0,
            'stream_failure' => false, 'persistence_failure' => false, 'limit_failure' => false, 'closed' => false,
            'supervisor_failed' => false, 'log_limit_failure' => false, 'tunnel_exited' => false]) . "\n";
    } else {
        echo json_encode(['type' => 'stopped', 'receiver_closed' => true, 'tunnel_stopped' => true,
            'logs_closed' => true, 'failed' => false]) . "\n";
        break;
    }
}
CODE;
        [$supervisor, $process] = $this->fixture($program);
        try {
            $supervisor->arm(str_repeat('a', 64));
            self::assertSame(2, $supervisor->poll()['stored']);
            ob_start();
            var_dump($supervisor);
            $debug = ob_get_clean();
            self::assertIsString($debug);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $debug);
            self::assertSame(['receiver_closed' => true, 'tunnel_stopped' => true,
                'logs_closed' => true, 'failed' => false], $supervisor->stop());
            self::assertFalse(is_resource($process));
        } finally {
            $supervisor->stop();
        }
    }

    /** @return iterable<string,array{string}> */
    public static function failures(): iterable
    {
        yield 'private stderr' => ['fgets(STDIN); fwrite(STDERR, "SYNTHETIC_PRIVATE_ERROR");'];
        yield 'private malformed output' => ['fgets(STDIN); echo "SYNTHETIC_PRIVATE_NOT_JSON\\n";'];
        yield 'private huge output' => ['fgets(STDIN); echo str_repeat("SYNTHETIC_PRIVATE", 2000);'];
        yield 'ambiguous messages' => ['fgets(STDIN); echo "{}\\n{}\\n";'];
        yield 'EOF' => ['fgets(STDIN);'];
        yield 'negative count' => ['fgets(STDIN); echo "{\"type\":\"observed\",\"seen\":-1}\\n";'];
        yield 'wrong response type' => ['fgets(STDIN); echo "{\"type\":\"SYNTHETIC_PRIVATE\"}\\n";'];
    }

    #[DataProvider('failures')]
    public function testFailedPrivateIpcNeverRelaysItsValuesAndStopsItsOwnedChild(string $program): void
    {
        [$supervisor, $process] = $this->fixture($program);
        try {
            $supervisor->poll();
            self::fail('A private protocol failure was accepted.');
        } catch (Throwable $error) {
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $error->getMessage());
            $result = $supervisor->stop();
            self::assertTrue($result['failed']);
            self::assertFalse(is_resource($process));
        }
    }

    public function testUnverifiedBinaryFailsBeforeAnyChildCanStart(): void
    {
        $this->expectException(RuntimeException::class);
        new WebhookDeliverySupervisor('/SYNTHETIC_PRIVATE_NODE', ['folder' => '/SYNTHETIC_PRIVATE',
            'path' => '/' . str_repeat('a', 64), 'context' => str_repeat('b', 64), 'source' => str_repeat('c', 64),
            'duration_ms' => 300000, 'live' => true, 'binary' => '/SYNTHETIC_PRIVATE_BINARY']);
    }
}
