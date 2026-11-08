<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\SimpleCache\CacheInterface;

final class PackageTest extends TestCase
{
    public function testComposerRegistersSdkNamespaceAndRequiredInterfaces(): void
    {
        $loader = require dirname(__DIR__) . '/vendor/autoload.php';
        self::assertInstanceOf(ClassLoader::class, $loader);
        $paths = $loader->getPrefixesPsr4()['Jojeringnax\\Airwallex\\'] ?? [];
        self::assertContains(dirname(__DIR__) . '/src', array_map(realpath(...), $paths));
        self::assertTrue(interface_exists(ClientInterface::class));
        self::assertTrue(interface_exists(RequestFactoryInterface::class));
        self::assertTrue(interface_exists(RequestInterface::class));
        self::assertTrue(interface_exists(CacheInterface::class));
    }
}
