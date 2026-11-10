<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support;

/** Keeps cleanup tests on the reviewed cleanup SDK without widening the executable source guard. */
abstract class HistoricalAlipayCleanupTestCase extends HistoricalAlipayTestCase
{
    protected const string SOURCE = '6076b0ff160c0943f0f4025cf0c5db97f02c0e29';
    protected const string SDK = '92d43dc9c4f5f651c7c9fe92207f317eec8303b7945af437f2dd9220aef06508';
}
