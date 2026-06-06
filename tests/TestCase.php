<?php

declare(strict_types=1);

namespace Switon\Caching\Tests;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Testing\TestCase as BaseTestCase;

/**
 * Base test case for Cache tests.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Event dispatcher (optional, some tests set their own mock).
     */
    protected ?EventDispatcherInterface $eventDispatcher = null;
}
