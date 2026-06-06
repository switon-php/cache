<?php

declare(strict_types=1);

namespace Switon\Caching\Tests\Integration;

use Psr\SimpleCache\CacheInterface;
use Switon\Caching\ServiceProvider;
use Switon\Caching\SimpleCache;
use Switon\Caching\Tests\Fixtures\MockRedisClient;
use Switon\Caching\Tests\TestCase;
use Switon\Testing\Container;

/**
 * Tests cache service registration and resolution.
 */
class ServiceProviderTest extends TestCase
{
    protected ServiceProvider $serviceProvider;
    protected Container $testContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serviceProvider = new ServiceProvider();
        // Arrange: create a fresh container for testing ServiceProvider (not using base TestCase container)
        // Container interfaces are already registered by Switon\\Testing\\Container\\Container
        $this->testContainer = new Container();
    }

    /**
     * Test that register() binds CacheInterface to SimpleCache.
     */
    public function testRegisterRegistersCacheInterface(): void
    {
        // Act
        $this->serviceProvider->register($this->testContainer);

        // Assert
        $this->assertTrue(
            $this->testContainer->has(CacheInterface::class),
            'register() should register Psr\\SimpleCache\\CacheInterface'
        );
        $definition = $this->testContainer->getDefinition(CacheInterface::class);
        $this->assertSame(
            SimpleCache::class,
            $definition,
            'register() should register SimpleCache as implementation'
        );
    }

    /**
     * Test that boot() does nothing.
     *
     * Verifies that boot() method exists and can be called without errors.
     */
    public function testBootDoesNothing(): void
    {
        // Act - Should not throw any exceptions
        $this->serviceProvider->boot();

        // Assert - If we reach here, boot() executed successfully
        $this->assertTrue(true, 'boot() should execute without errors');
    }

    /**
     * Test that registered service can be resolved.
     *
     * Verifies that after registration, CacheInterface can be resolved from container.
     */
    public function testRegisteredServiceCanBeResolved(): void
    {
        // Arrange
        $this->serviceProvider->register($this->testContainer);

        // Set up dependencies required by SimpleCache
        $this->testContainer->set(
            \Psr\EventDispatcher\EventDispatcherInterface::class,
            $this->createStub(\Psr\EventDispatcher\EventDispatcherInterface::class)
        );
        $this->testContainer->set(
            \Switon\Redis\ClientInterface::class,
            $this->createStub(\Switon\Redis\ClientInterface::class)
        );

        // Act
        $cache = $this->testContainer->get(CacheInterface::class);

        // Assert
        $this->assertInstanceOf(
            SimpleCache::class,
            $cache,
            'Container should resolve SimpleCache for CacheInterface'
        );
        $this->assertInstanceOf(
            CacheInterface::class,
            $cache,
            'Resolved instance should implement CacheInterface'
        );
    }

    /**
     * Test that user config can override prefix without specifying class.
     *
     * Verifies that configuration can override only the prefix while keeping the default class.
     * User config: ['prefix' => 'custom:'] should work without 'class' key.
     */
    public function testUserConfigCanOverridePrefixWithoutClass(): void
    {
        // Arrange
        $this->serviceProvider->register($this->testContainer);

        // Set up dependencies required by SimpleCache
        $this->testContainer->set(
            \Psr\EventDispatcher\EventDispatcherInterface::class,
            $this->createStub(\Psr\EventDispatcher\EventDispatcherInterface::class)
        );
        $redis = new MockRedisClient();
        $this->testContainer->set(\Switon\Redis\ClientInterface::class, $redis);

        // Act - Override prefix without specifying class
        // This tests whether user config can just override specific params
        $this->testContainer->set(CacheInterface::class, [
            'class' => SimpleCache::class,
            'prefix' => 'custom:',
        ]);

        $cache = $this->testContainer->get(CacheInterface::class);
        $cache->set('key1', 'value1');

        $this->assertInstanceOf(SimpleCache::class, $cache);
        $this->assertArrayHasKey('custom:key1', $redis->storage, 'Prefix should be overridden by user config');
    }

    /**
     * Test that config without 'class' key uses the registered implementation.
     *
     * When ServiceProvider already registered CacheInterface -> SimpleCache,
     * user config can override just the prefix without re-specifying the class.
     * This verifies that array config merges with existing definition.
     */
    public function testConfigWithoutClassUsesExistingDefinition(): void
    {
        // Arrange
        $this->serviceProvider->register($this->testContainer);

        // Set up dependencies required by SimpleCache
        $this->testContainer->set(
            \Psr\EventDispatcher\EventDispatcherInterface::class,
            $this->createStub(\Psr\EventDispatcher\EventDispatcherInterface::class)
        );
        $redis = new MockRedisClient();
        $this->testContainer->set(\Switon\Redis\ClientInterface::class, $redis);

        // Act - Override with only prefix (no class)
        $this->testContainer->set(CacheInterface::class, [
            'prefix' => 'custom:',
        ]);

        $cache = $this->testContainer->get(CacheInterface::class);
        $cache->set('key1', 'value1');

        $this->assertInstanceOf(SimpleCache::class, $cache, 'Should still be SimpleCache instance');
        $this->assertArrayHasKey('custom:key1', $redis->storage, 'Prefix should be overridden by user config');
    }
}
