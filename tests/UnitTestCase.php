<?php

declare(strict_types=1);

namespace AndyDefer\PhpServices\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class UnitTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \Mockery::close();
    }
}
