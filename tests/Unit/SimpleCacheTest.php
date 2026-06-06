<?php

declare(strict_types=1);

namespace Switon\Caching\Tests\Unit;

use DateInterval;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Caching\Event\CacheCleared;
use Switon\Caching\Event\CacheDeleted;
use Switon\Caching\Event\CacheHit;
use Switon\Caching\Event\CacheMiss;
use Switon\Caching\Event\CacheMultipleWritten;
use Switon\Caching\Event\CacheWritten;
use Switon\Caching\Exception\CachePrefixRequiredException;
use Switon\Caching\SimpleCache;
use Switon\Caching\Tests\Fixtures\MockRedisClient;
use Switon\Caching\Tests\TestCase;
use Switon\Core\Json;
use Switon\Redis\ClientInterface;

/**
 * Tests SimpleCache behavior with Redis-backed storage and cache events.
 */
class SimpleCacheTest extends TestCase
{
    protected SimpleCache $cache; // @phpstan-ignore property.uninitialized
    protected MockRedisClient $mockRedis; // @phpstan-ignore property.uninitialized

    protected function setUp(): void
    {
        parent::setUp();


        // Use MockRedisClient instead of mock object for better testability
        $this->mockRedis = new MockRedisClient();
        $this->container->set(ClientInterface::class, $this->mockRedis);

        // Keep parent's stub for most tests (no expectations needed)
        // Tests that need to verify event dispatch will create their own mock

        // Create cache instance with prefix
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'test:',
        ]);

        $this->cache = $this->container->get('Psr\SimpleCache\CacheInterface');
    }

    protected function tearDown(): void
    {
        if (isset($this->mockRedis)) {
            $this->mockRedis->clear();
        }
        parent::tearDown();
    }

    /**
     * Test that get() returns cached value when key exists.
     *
     * Verifies that get() retrieves and deserializes cached values correctly.
     */
    public function testGetReturnsCachedValue(): void
    {
        // Arrange
        $key = 'test-key';
        $value = ['data' => 'test-value', 'number' => 42];
        $serialized = Json::stringify($value);
        $this->mockRedis->storage['test:' . $key] = $serialized;

        // Act
        $result = $this->cache->get($key);

        // Assert
        $this->assertSame($value, $result, 'get() should return deserialized cached value');
        $this->assertTrue(isset($this->mockRedis->calls['get']), 'get() should call Redis get()');
    }

    /**
     * Test that get() returns default value when key does not exist.
     *
     * Verifies that get() returns the provided default value and dispatches CacheMiss event.
     */
    public function testGetReturnsDefaultWhenKeyNotFound(): void
    {
        // Arrange
        $key = 'non-existent-key';
        $default = 'default-value';

        // Create mock event dispatcher for event verification
        // Need to remove existing services and recreate cache with the new mock
        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        /** @var \PHPUnit\Framework\MockObject\MockObject&\Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->container->set(\Psr\EventDispatcher\EventDispatcherInterface::class, $eventDispatcher);
        $this->eventDispatcher = $eventDispatcher;
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'test:',
        ]);
        $this->cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        // Verify CacheMiss event will be dispatched
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($key, $default) {
                return $event instanceof CacheMiss
                    && $event->key === $key
                    && $event->default === $default
                    && $event->prefix === 'test:';
            }));

        // Act
        $result = $this->cache->get($key, $default);

        // Assert
        $this->assertSame($default, $result, 'get() should return default value when key not found');
    }

    /**
     * Test that get() dispatches CacheHit event when key exists.
     *
     * Verifies that successful cache lookups dispatch CacheHit events.
     */
    public function testGetDispatchesCacheHitEvent(): void
    {
        // Arrange
        $key = 'test-key';
        $value = 'test-value';
        $this->mockRedis->storage['test:' . $key] = Json::stringify($value);

        // Create mock event dispatcher for event verification
        // Need to remove existing services and recreate cache with the new mock
        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        /** @var \PHPUnit\Framework\MockObject\MockObject&\Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->container->set(\Psr\EventDispatcher\EventDispatcherInterface::class, $eventDispatcher);
        $this->eventDispatcher = $eventDispatcher;
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'test:',
        ]);
        $this->cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        // Act
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($key, $value) {
                return $event instanceof CacheHit
                    && $event->key === $key
                    && $event->value === $value
                    && $event->prefix === 'test:';
            }));

        $this->cache->get($key);
    }

    /**
     * Test that set() stores value with prefix.
     *
     * Verifies that set() serializes and stores values with the configured prefix.
     */
    public function testSetStoresValueWithPrefix(): void
    {
        // Arrange
        $key = 'test-key';
        $value = ['data' => 'test-value'];

        // Act
        $result = $this->cache->set($key, $value);

        // Assert
        $this->assertTrue($result, 'set() should return true on success');
        $this->assertArrayHasKey('test:' . $key, $this->mockRedis->storage, 'set() should store value with prefix');
        $this->assertSame(Json::stringify($value), $this->mockRedis->storage['test:' . $key], 'set() should serialize value');
    }

    /**
     * Test that set() stores value with TTL.
     *
     * Verifies that set() correctly handles TTL values.
     */
    public function testSetStoresValueWithTtl(): void
    {
        // Arrange
        $key = 'test-key';
        $value = 'test-value';
        $ttl = 3600;

        // Act
        $result = $this->cache->set($key, $value, $ttl);

        // Assert
        $this->assertTrue($result, 'set() should return true on success');
        $this->assertArrayHasKey('test:' . $key, $this->mockRedis->storage, 'set() should store value with TTL');

        // Verify TTL was set
        $setCalls = $this->mockRedis->calls['set'] ?? [];
        $this->assertNotEmpty($setCalls, 'set() should be called');
        $this->assertSame($ttl, $setCalls[0]['ttl'], 'set() should pass TTL to Redis');
    }

    /**
     * Test that set() stores value with DateInterval TTL.
     *
     * Verifies that set() correctly handles DateInterval TTL values.
     */
    public function testSetStoresValueWithDateIntervalTtl(): void
    {
        // Arrange
        $key = 'test-key';
        $value = 'test-value';
        $ttl = new DateInterval('PT1H'); // 1 hour

        // Act
        $result = $this->cache->set($key, $value, $ttl);

        // Assert
        $this->assertTrue($result, 'set() should return true on success');

        // Verify DateInterval was passed to Redis
        $setCalls = $this->mockRedis->calls['set'] ?? [];
        $this->assertNotEmpty($setCalls, 'set() should be called');
        $this->assertSame(3600, $setCalls[0]['ttl'], 'set() should normalize DateInterval to seconds');
    }

    /**
     * Test that set() dispatches CacheWritten event.
     *
     * Verifies that cache writes dispatch CacheWritten events with correct data.
     */
    public function testSetDispatchesCacheWrittenEvent(): void
    {
        // Arrange
        $key = 'test-key';
        $value = ['data' => 'test-value'];
        $ttl = 3600;

        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        /** @var \PHPUnit\Framework\MockObject\MockObject&\Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->container->set(\Psr\EventDispatcher\EventDispatcherInterface::class, $eventDispatcher);
        $this->eventDispatcher = $eventDispatcher;
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'test:',
        ]);
        $this->cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        // Assert
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($key, $value, $ttl) {
                return $event instanceof CacheWritten
                    && $event->key === $key
                    && $event->value === $value
                    && $event->ttl === $ttl
                    && $event->prefix === 'test:';
            }));

        // Act
        $this->cache->set($key, $value, $ttl);
    }

    /**
     * Test that setMultiple() dispatches one CacheMultipleWritten event after pipeline exec.
     */
    public function testSetMultipleDispatchesCacheMultipleWrittenEvent(): void
    {
        // Arrange
        $values = ['key1' => 'value1', 'key2' => 'value2'];
        $ttl = 600;

        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        /** @var \PHPUnit\Framework\MockObject\MockObject&\Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->container->set(\Psr\EventDispatcher\EventDispatcherInterface::class, $eventDispatcher);
        $this->eventDispatcher = $eventDispatcher;
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'test:',
        ]);
        $this->cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        // Assert
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use ($ttl, $values) {
                $this->assertInstanceOf(CacheMultipleWritten::class, $event);
                $this->assertSame($ttl, $event->ttl);
                $this->assertSame('test:', $event->prefix);
                $this->assertSame($values, $event->values);
                return $event;
            });

        // Act
        $this->cache->setMultiple($values, $ttl);
    }

    /**
     * Test that delete() dispatches CacheDeleted event.
     *
     * Verifies that single key deletion dispatches CacheDeleted event.
     */
    public function testDeleteDispatchesCacheDeletedEvent(): void
    {
        // Arrange
        $key = 'test-key';
        $this->mockRedis->storage['test:' . $key] = 'test-value';

        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        /** @var \PHPUnit\Framework\MockObject\MockObject&\Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->container->set(\Psr\EventDispatcher\EventDispatcherInterface::class, $eventDispatcher);
        $this->eventDispatcher = $eventDispatcher;
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'test:',
        ]);
        $this->cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        // Assert
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($key) {
                return $event instanceof CacheDeleted
                    && $event->keys === [$key]
                    && $event->prefix === 'test:';
            }));

        // Act
        $this->cache->delete($key);
    }

    /**
     * Test that deleteMultiple() dispatches CacheDeleted event with all keys.
     *
     * Verifies that batch deletion dispatches a single CacheDeleted event.
     */
    public function testDeleteMultipleDispatchesCacheDeletedEvent(): void
    {
        // Arrange
        $keys = ['key1', 'key2', 'key3'];
        $this->mockRedis->storage['test:key1'] = 'value1';
        $this->mockRedis->storage['test:key2'] = 'value2';
        $this->mockRedis->storage['test:key3'] = 'value3';

        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        /** @var \PHPUnit\Framework\MockObject\MockObject&\Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->container->set(\Psr\EventDispatcher\EventDispatcherInterface::class, $eventDispatcher);
        $this->eventDispatcher = $eventDispatcher;
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'test:',
        ]);
        $this->cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        // Assert
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($keys) {
                return $event instanceof CacheDeleted
                    && $event->keys === $keys
                    && $event->prefix === 'test:';
            }));

        // Act
        $this->cache->deleteMultiple($keys);
    }

    /**
     * Test that delete() removes key from cache.
     *
     * Verifies that delete() removes keys with the configured prefix.
     */
    public function testDeleteRemovesKey(): void
    {
        // Arrange
        $key = 'test-key';
        $this->mockRedis->storage['test:' . $key] = 'test-value';

        // Act
        $result = $this->cache->delete($key);

        // Assert
        $this->assertTrue($result, 'delete() should return true on success');
        $this->assertArrayNotHasKey('test:' . $key, $this->mockRedis->storage, 'delete() should remove key from storage');
    }

    /**
     * Test that clear() removes all keys with prefix.
     *
     * Verifies that clear() removes all keys matching the prefix pattern.
     */
    public function testClearRemovesAllKeysWithPrefix(): void
    {
        // Arrange
        $this->mockRedis->storage['test:key1'] = 'value1';
        $this->mockRedis->storage['test:key2'] = 'value2';
        $this->mockRedis->storage['other:key3'] = 'value3'; // Should not be deleted

        // Act
        $result = $this->cache->clear();

        // Assert
        $this->assertTrue($result, 'clear() should return true on success');
        $this->assertArrayNotHasKey('test:key1', $this->mockRedis->storage, 'clear() should remove prefixed keys');
        $this->assertArrayNotHasKey('test:key2', $this->mockRedis->storage, 'clear() should remove prefixed keys');
        $this->assertArrayHasKey('other:key3', $this->mockRedis->storage, 'clear() should not remove keys with different prefix');
    }

    /**
     * Test that clear() dispatches CacheCleared event.
     *
     * Verifies that prefix clear dispatches one summary event with deleted key count.
     */
    public function testClearDispatchesCacheClearedEvent(): void
    {
        // Arrange
        $this->mockRedis->storage['test:key1'] = Json::stringify('value1');
        $this->mockRedis->storage['test:key2'] = Json::stringify('value2');
        $this->mockRedis->storage['other:key3'] = Json::stringify('value3');

        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        /** @var \PHPUnit\Framework\MockObject\MockObject&EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->container->set(\Psr\EventDispatcher\EventDispatcherInterface::class, $eventDispatcher);
        $this->eventDispatcher = $eventDispatcher;
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'test:',
        ]);
        $this->cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof CacheCleared
                    && $event->deletedCount === 2
                    && $event->prefix === 'test:';
            }));

        // Act
        $this->cache->clear();
    }

    /**
     * Test that clear() reports the actual deleted key count.
     *
     * Verifies that the summary event uses Redis delete results instead of scan batch size.
     */
    public function testClearReportsActualDeletedCount(): void
    {
        $client = new class () extends MockRedisClient {
            public function del(string|array $key): int
            {
                parent::del($key);
                return 1;
            }
        };

        $client->storage['test:key1'] = Json::stringify('value1');
        $client->storage['test:key2'] = Json::stringify('value2');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher = $eventDispatcher;
        $this->cache = $this->make(SimpleCache::class, [
            'redisCache' => $client,
            'eventDispatcher' => $eventDispatcher,
            'prefix' => 'test:',
        ]);

        $capturedCount = null;
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use (&$capturedCount) {
                $capturedCount = $event->deletedCount;
                return $event instanceof CacheCleared;
            }));

        $result = $this->cache->clear();

        $this->assertTrue($result);
        $this->assertSame(1, $capturedCount);
    }

    /**
     * Test that clear() throws exception when prefix is empty.
     *
     * Verifies that clear() throws CachePrefixRequiredException when prefix is empty
     * to prevent accidental Redis database flush.
     */
    public function testClearThrowsExceptionWhenPrefixIsEmpty(): void
    {
        // Arrange - Remove existing service and create cache without prefix
        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => '',
        ]);
        $cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        // Act & Assert
        $this->expectException(CachePrefixRequiredException::class);

        $cache->clear();
    }

    /**
     * Test that getMultiple() retrieves multiple values.
     *
     * Verifies that getMultiple() retrieves multiple keys using pipeline.
     */
    public function testGetMultipleRetrievesMultipleValues(): void
    {
        // Arrange
        $keys = ['key1', 'key2', 'key3'];
        $this->mockRedis->storage['test:key1'] = Json::stringify('value1');
        $this->mockRedis->storage['test:key2'] = Json::stringify('value2');
        // key3 does not exist

        // Act
        $result = $this->cache->getMultiple($keys, 'default');

        // Assert
        $this->assertIsIterable($result, 'getMultiple() should return iterable');
        $resultArray = iterator_to_array($result);
        $this->assertSame('value1', $resultArray['key1'], 'getMultiple() should return value for key1');
        $this->assertSame('value2', $resultArray['key2'], 'getMultiple() should return value for key2');
        $this->assertSame('default', $resultArray['key3'], 'getMultiple() should return default for missing key');
    }

    /**
     * Test that getMultiple() dispatches CacheHit and CacheMiss events.
     *
     * Verifies that getMultiple() dispatches appropriate events for each key.
     */
    public function testGetMultipleDispatchesEvents(): void
    {
        // Arrange
        $keys = ['key1', 'key2'];
        $this->mockRedis->storage['test:key1'] = Json::stringify('value1');
        // key2 does not exist

        // Create mock event dispatcher for event verification
        // Need to remove existing services and recreate cache with the new mock
        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        /** @var \PHPUnit\Framework\MockObject\MockObject&\Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->container->set(\Psr\EventDispatcher\EventDispatcherInterface::class, $eventDispatcher);
        $this->eventDispatcher = $eventDispatcher;
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'test:',
        ]);
        $this->cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        // Verify events will be dispatched
        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                static $callCount = 0;
                $callCount++;
                if ($callCount === 1) {
                    $this->assertInstanceOf(CacheHit::class, $event, 'First event should be CacheHit');
                    $this->assertSame('key1', $event->key, 'First event should be for key1');
                } else {
                    $this->assertInstanceOf(CacheMiss::class, $event, 'Second event should be CacheMiss');
                    $this->assertSame('key2', $event->key, 'Second event should be for key2');
                }
                return $event;
            });

        // Act
        $this->cache->getMultiple($keys, 'default');
    }

    /**
     * Test that setMultiple() stores multiple values.
     *
     * Verifies that setMultiple() stores multiple key-value pairs using pipeline.
     */
    public function testSetMultipleStoresMultipleValues(): void
    {
        // Arrange
        $values = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => ['nested' => 'data'],
        ];

        // Act
        $result = $this->cache->setMultiple($values);

        // Assert
        $this->assertTrue($result, 'setMultiple() should return true on success');
        $this->assertSame(Json::stringify('value1'), $this->mockRedis->storage['test:key1'], 'setMultiple() should store key1');
        $this->assertSame(Json::stringify('value2'), $this->mockRedis->storage['test:key2'], 'setMultiple() should store key2');
        $this->assertSame(Json::stringify(['nested' => 'data']), $this->mockRedis->storage['test:key3'], 'setMultiple() should store key3');
    }

    /**
     * Test that setMultiple() stores values with TTL.
     *
     * Verifies that setMultiple() correctly handles TTL for batch operations.
     */
    public function testSetMultipleStoresValuesWithTtl(): void
    {
        // Arrange
        $values = ['key1' => 'value1', 'key2' => 'value2'];
        $ttl = 3600;

        // Act
        $result = $this->cache->setMultiple($values, $ttl);

        // Assert
        $this->assertTrue($result, 'setMultiple() should return true on success');

        // Verify pipeline was used
        $this->assertTrue(isset($this->mockRedis->calls['pipeline']), 'setMultiple() should use pipeline');
    }

    /**
     * Test that deleteMultiple() removes multiple keys.
     *
     * Verifies that deleteMultiple() removes multiple keys with the configured prefix.
     */
    public function testDeleteMultipleRemovesMultipleKeys(): void
    {
        // Arrange
        $keys = ['key1', 'key2', 'key3'];
        $this->mockRedis->storage['test:key1'] = 'value1';
        $this->mockRedis->storage['test:key2'] = 'value2';
        $this->mockRedis->storage['test:key3'] = 'value3';

        // Act
        $result = $this->cache->deleteMultiple($keys);

        // Assert
        $this->assertTrue($result, 'deleteMultiple() should return true on success');
        $this->assertArrayNotHasKey('test:key1', $this->mockRedis->storage, 'deleteMultiple() should remove key1');
        $this->assertArrayNotHasKey('test:key2', $this->mockRedis->storage, 'deleteMultiple() should remove key2');
        $this->assertArrayNotHasKey('test:key3', $this->mockRedis->storage, 'deleteMultiple() should remove key3');
    }

    /**
     * Test that deleteMultiple() handles empty array.
     *
     * Verifies that deleteMultiple() handles empty key arrays gracefully.
     */
    public function testDeleteMultipleHandlesEmptyArray(): void
    {
        // Arrange
        $keys = [];

        // Act
        $result = $this->cache->deleteMultiple($keys);

        // Assert
        $this->assertTrue($result, 'deleteMultiple() should return true even for empty array');
    }

    /**
     * Test that deleteMultiple() accepts Traversable keys and normalizes event keys as strings.
     */
    public function testDeleteMultipleWithTraversableNormalizesEventKeysToStrings(): void
    {
        // Arrange
        $this->mockRedis->storage['test:100'] = 'v100';
        $this->mockRedis->storage['test:200'] = 'v200';

        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        /** @var \PHPUnit\Framework\MockObject\MockObject&\Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->container->set(\Psr\EventDispatcher\EventDispatcherInterface::class, $eventDispatcher);
        $this->eventDispatcher = $eventDispatcher;
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'test:',
        ]);
        $this->cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        $keys = (function () {
            yield 100;
            yield 200;
        })();

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof CacheDeleted
                    && $event->keys === ['100', '200']
                    && $event->prefix === 'test:';
            }));

        // Act
        $result = $this->cache->deleteMultiple($keys);

        // Assert
        $this->assertTrue($result);
        $this->assertArrayNotHasKey('test:100', $this->mockRedis->storage);
        $this->assertArrayNotHasKey('test:200', $this->mockRedis->storage);
    }

    /**
     * Test that has() checks if key exists.
     *
     * Verifies that has() correctly checks for key existence with prefix.
     */
    public function testHasChecksKeyExistence(): void
    {
        // Arrange
        $key = 'test-key';
        $this->mockRedis->storage['test:' . $key] = 'test-value';

        // Act
        $result = $this->cache->has($key);

        // Assert
        $this->assertTrue($result, 'has() should return true when key exists');

        // Test non-existent key
        $result2 = $this->cache->has('non-existent');
        $this->assertFalse($result2, 'has() should return false when key does not exist');
    }

    /**
     * Test that cache handles complex data types.
     *
     * Verifies that cache correctly serializes and deserializes complex data types.
     */
    public function testCacheHandlesComplexDataTypes(): void
    {
        // Arrange
        $key = 'complex-key';
        $value = [
            'string' => 'test',
            'number' => 42,
            'float' => 3.14,
            'boolean' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'object' => (object)['prop' => 'value'],
        ];

        // Act
        $this->cache->set($key, $value);
        $result = $this->cache->get($key);

        // Assert
        $this->assertSame($value['string'], $result['string'], 'Cache should preserve strings');
        $this->assertSame($value['number'], $result['number'], 'Cache should preserve integers');
        $this->assertSame($value['float'], $result['float'], 'Cache should preserve floats');
        $this->assertSame($value['boolean'], $result['boolean'], 'Cache should preserve booleans');
        $this->assertSame($value['null'], $result['null'], 'Cache should preserve null');
        $this->assertSame($value['array'], $result['array'], 'Cache should preserve arrays');
        // Objects are serialized as arrays in JSON
        $this->assertIsArray($result['object'], 'Cache should serialize objects as arrays');
    }

    /**
     * Test that cache uses custom prefix.
     *
     * Verifies that cache correctly uses the configured prefix for all operations.
     */
    public function testCacheUsesCustomPrefix(): void
    {
        // Arrange - Remove existing service and create new one with custom prefix
        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'custom:',
        ]);
        $cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        // Act
        $cache->set('key1', 'value1');

        // Assert
        $this->assertArrayHasKey('custom:key1', $this->mockRedis->storage, 'Cache should use custom prefix');
        $this->assertArrayNotHasKey('test:key1', $this->mockRedis->storage, 'Cache should not use default prefix');
    }

    /**
     * Test that cache handles default prefix.
     *
     * Verifies that cache uses default prefix when none is configured.
     */
    public function testCacheUsesDefaultPrefix(): void
    {
        // Arrange - Remove existing service and create new one without prefix configuration
        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->set('Psr\SimpleCache\CacheInterface', SimpleCache::class);
        $cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        // Act
        $cache->set('key1', 'value1');

        // Assert
        $this->assertArrayHasKey('cache:key1', $this->mockRedis->storage, 'Cache should use default prefix "cache:"');
    }

    /**
     * Test that clear() uses transient redis client for scan operation.
     */
    public function testClearUsesTransientClientForScanning(): void
    {
        // Arrange
        $this->mockRedis->storage['test:key1'] = Json::stringify('value1');
        $this->mockRedis->storage['test:key2'] = Json::stringify('value2');

        // Act
        $result = $this->cache->clear();

        // Assert
        $this->assertTrue($result);
        $this->assertArrayNotHasKey('test:key1', $this->mockRedis->storage);
        $this->assertArrayNotHasKey('test:key2', $this->mockRedis->storage);
        $this->assertArrayHasKey('getTransient', $this->mockRedis->calls);
        $this->assertNotEmpty($this->mockRedis->calls['getTransient']);
    }

    /**
     * Test that getMultiple() uses transient redis client for pipeline operation.
     */
    public function testGetMultipleUsesTransientClientForPipeline(): void
    {
        // Arrange
        $this->mockRedis->storage['test:key1'] = Json::stringify('value1');

        // Act
        $result = $this->cache->getMultiple(['key1', 'missing'], 'default');
        $resultArray = iterator_to_array($result);

        // Assert
        $this->assertSame('value1', $resultArray['key1']);
        $this->assertSame('default', $resultArray['missing']);
        $this->assertArrayHasKey('getTransient', $this->mockRedis->calls);
        $this->assertNotEmpty($this->mockRedis->calls['getTransient']);
    }

    /**
     * Test that setMultiple() uses transient redis client for pipeline operation.
     */
    public function testSetMultipleUsesTransientClientForPipeline(): void
    {
        // Act
        $result = $this->cache->setMultiple(['a' => 'va', 'b' => 'vb'], 60);

        // Assert
        $this->assertTrue($result);
        $this->assertArrayHasKey('test:a', $this->mockRedis->storage);
        $this->assertArrayHasKey('test:b', $this->mockRedis->storage);
        $this->assertArrayHasKey('getTransient', $this->mockRedis->calls);
        $this->assertNotEmpty($this->mockRedis->calls['getTransient']);
    }

    /**
     * Test that getMultiple() normalizes numeric keys to strings.
     */
    public function testGetMultipleNormalizesNumericKeysToStrings(): void
    {
        // Arrange
        $this->mockRedis->storage['test:100'] = Json::stringify('v100');
        $this->mockRedis->storage['test:200'] = Json::stringify('v200');

        // Act
        $result = $this->cache->getMultiple([100, 200], 'default');
        $resultArray = iterator_to_array($result);

        // Assert
        $this->assertSame('v100', $resultArray[100]);
        $this->assertSame('v200', $resultArray[200]);
        $getCalls = $this->mockRedis->calls['get'] ?? [];
        $this->assertSame('test:100', $getCalls[0]['key'] ?? null);
        $this->assertSame('test:200', $getCalls[1]['key'] ?? null);
    }

    /**
     * Test that getMultiple() uses traversable yielded values (not yielded keys).
     */
    public function testGetMultipleWithTraversableAssociativeKeysUsesYieldedValues(): void
    {
        // Arrange
        $this->mockRedis->storage['test:key-a'] = Json::stringify('va');
        $this->mockRedis->storage['test:key-b'] = Json::stringify('vb');
        $keys = (function () {
            yield 'ignored-index-1' => 'key-a';
            yield 'ignored-index-2' => 'key-b';
        })();

        // Act
        $result = $this->cache->getMultiple($keys, 'default');
        $resultArray = iterator_to_array($result);

        // Assert
        $this->assertSame(['key-a' => 'va', 'key-b' => 'vb'], $resultArray);
    }

    /**
     * Test that setMultiple() with traversable normalizes event keys to strings.
     */
    public function testSetMultipleWithTraversableNormalizesEventKeysToStrings(): void
    {
        // Arrange
        $this->container->remove('Psr\SimpleCache\CacheInterface');
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        /** @var \PHPUnit\Framework\MockObject\MockObject&\Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->container->set(\Psr\EventDispatcher\EventDispatcherInterface::class, $eventDispatcher);
        $this->eventDispatcher = $eventDispatcher;
        $this->container->set('Psr\SimpleCache\CacheInterface', [
            'class' => SimpleCache::class,
            'prefix' => 'test:',
        ]);
        $this->cache = $this->container->get('Psr\SimpleCache\CacheInterface');

        $values = (function () {
            yield 100 => 'v100';
            yield 200 => 'v200';
        })();

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                $this->assertInstanceOf(CacheMultipleWritten::class, $event);
                $this->assertSame(['100' => 'v100', '200' => 'v200'], $event->values);
                return $event;
            });

        // Act
        $result = $this->cache->setMultiple($values, 60);

        // Assert
        $this->assertTrue($result);
        $this->assertArrayHasKey('test:100', $this->mockRedis->storage);
        $this->assertArrayHasKey('test:200', $this->mockRedis->storage);
    }

    /**
     * Test that setMultiple() accepts numeric keys and stores prefixed string keys.
     */
    public function testSetMultipleWithNumericKeysStoresStringPrefixedKeys(): void
    {
        // Act
        $result = $this->cache->setMultiple([100 => 'v100', 200 => 'v200']);

        // Assert
        $this->assertTrue($result);
        $this->assertArrayHasKey('test:100', $this->mockRedis->storage);
        $this->assertArrayHasKey('test:200', $this->mockRedis->storage);
        $this->assertSame(Json::stringify('v100'), $this->mockRedis->storage['test:100']);
        $this->assertSame(Json::stringify('v200'), $this->mockRedis->storage['test:200']);
    }

    /**
     * Test that getMultiple() with empty traversable returns an empty array.
     */
    public function testGetMultipleWithEmptyTraversableReturnsEmptyArray(): void
    {
        $keys = (function () {
            if (false) {
                yield 'never';
            }
        })();

        $result = $this->cache->getMultiple($keys, 'default');

        $this->assertSame([], iterator_to_array($result));
    }

    /**
     * Test that deleteMultiple() with empty traversable does not call Redis del().
     */
    public function testDeleteMultipleWithEmptyTraversableSkipsRedisDelete(): void
    {
        $keys = (function () {
            if (false) {
                yield 'never';
            }
        })();

        $result = $this->cache->deleteMultiple($keys);

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('del', $this->mockRedis->calls);
    }

    /**
     * Test that clear() with only non-prefixed keys does not delete anything.
     */
    public function testClearWithOnlyNonPrefixedKeysLeavesStorageUntouched(): void
    {
        $this->mockRedis->storage['other:key1'] = Json::stringify('v1');
        $this->mockRedis->storage['other:key2'] = Json::stringify('v2');

        $result = $this->cache->clear();

        $this->assertTrue($result);
        $this->assertArrayHasKey('other:key1', $this->mockRedis->storage);
        $this->assertArrayHasKey('other:key2', $this->mockRedis->storage);
        $this->assertArrayNotHasKey('del', $this->mockRedis->calls);
    }

    /**
     * Test that getMultiple() with duplicated numeric keys keeps single final entry.
     */
    public function testGetMultipleWithDuplicatedNumericKeysKeepsLatestMapping(): void
    {
        $this->mockRedis->storage['test:100'] = Json::stringify('v100');

        $result = $this->cache->getMultiple([100, 100], 'default');
        $resultArray = iterator_to_array($result);

        $this->assertCount(1, $resultArray);
        $this->assertSame('v100', $resultArray[100]);
    }

    /**
     * Test that setMultiple() with empty traversable still executes pipeline path.
     */
    public function testSetMultipleWithEmptyTraversableStillUsesPipeline(): void
    {
        $values = (function () {
            if (false) {
                yield 'never' => 'value';
            }
        })();

        $result = $this->cache->setMultiple($values);

        $this->assertTrue($result);
        $this->assertArrayHasKey('pipeline', $this->mockRedis->calls);
    }

    /**
     * Test that setMultiple() passes DateInterval TTL to all set commands.
     */
    public function testSetMultipleWithDateIntervalTtlPassesDateIntervalToRedisSet(): void
    {
        $ttl = new DateInterval('PT2M');
        $this->cache->setMultiple(['a' => 'va', 'b' => 'vb'], $ttl);

        $setCalls = $this->mockRedis->calls['set'] ?? [];
        $this->assertCount(2, $setCalls);
        $this->assertSame(120, $setCalls[0]['ttl']);
        $this->assertSame(120, $setCalls[1]['ttl']);
    }

    /**
     * Test that getMultiple() keeps returned key order from normalized key list.
     */
    public function testGetMultipleKeepsNormalizedKeyOrder(): void
    {
        $this->mockRedis->storage['test:b'] = Json::stringify('vb');
        $this->mockRedis->storage['test:a'] = Json::stringify('va');

        $result = $this->cache->getMultiple(['b', 'a'], 'default');
        $resultArray = iterator_to_array($result);

        $this->assertSame(['b', 'a'], array_keys($resultArray));
    }

    /**
     * Test that getMultiple() returns defaults for every missing key.
     */
    public function testGetMultipleReturnsDefaultForAllMissingKeys(): void
    {
        $result = $this->cache->getMultiple(['m1', 'm2', 'm3'], 'd');
        $resultArray = iterator_to_array($result);

        $this->assertSame(['m1' => 'd', 'm2' => 'd', 'm3' => 'd'], $resultArray);
    }

    /**
     * Test that deleteMultiple() with duplicate keys remains successful.
     */
    public function testDeleteMultipleWithDuplicateKeysReturnsTrue(): void
    {
        $this->mockRedis->storage['test:k1'] = 'v1';
        $result = $this->cache->deleteMultiple(['k1', 'k1']);

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('test:k1', $this->mockRedis->storage);
    }

    /**
     * Test that has() returns false after deleting an existing key.
     */
    public function testHasReturnsFalseAfterDelete(): void
    {
        $this->cache->set('hx', 'vx');
        $this->assertTrue($this->cache->has('hx'));

        $this->cache->delete('hx');

        $this->assertFalse($this->cache->has('hx'));
    }

    /**
     * Test that clear() increments transient-client calls across invocations.
     */
    public function testClearCallsGetTransientOnEveryInvocation(): void
    {
        $this->cache->clear();
        $this->cache->clear();

        $calls = $this->mockRedis->calls['getTransient'] ?? [];
        $this->assertCount(2, $calls);
    }

    /**
     * Test that set() with null TTL leaves key without TTL metadata.
     */
    public function testSetWithNullTtlClearsAnyExistingTtlMetadata(): void
    {
        $this->cache->set('ttl-key', 'v1', 10);
        $this->assertArrayHasKey('test:ttl-key', $this->mockRedis->ttl);

        $this->cache->set('ttl-key', 'v2', null);

        $this->assertArrayNotHasKey('test:ttl-key', $this->mockRedis->ttl);
    }

    /**
     * Test that getMultiple() with traversable numeric values preserves numeric keys in output.
     */
    public function testGetMultipleWithTraversableNumericValuesPreservesNumericResultKeys(): void
    {
        $this->mockRedis->storage['test:10'] = Json::stringify('v10');
        $keys = (function () {
            yield 'ignored' => 10;
        })();

        $result = $this->cache->getMultiple($keys, 'default');
        $resultArray = iterator_to_array($result);

        $this->assertSame('v10', $resultArray[10]);
    }

    /**
     * Test that deleteMultiple() with numeric traversable values removes numeric-prefixed keys.
     */
    public function testDeleteMultipleWithNumericTraversableValuesDeletesPrefixedKeys(): void
    {
        $this->mockRedis->storage['test:10'] = 'v10';
        $this->mockRedis->storage['test:20'] = 'v20';
        $keys = (function () {
            yield 'a' => 10;
            yield 'b' => 20;
        })();

        $result = $this->cache->deleteMultiple($keys);

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('test:10', $this->mockRedis->storage);
        $this->assertArrayNotHasKey('test:20', $this->mockRedis->storage);
    }
}
