<?php

declare(strict_types=1);

namespace Switon\Caching\Tests\Unit;

use DateInterval;
use Switon\Caching\SimpleCache;
use Switon\Caching\Tests\Fixtures\MockRedisClient;
use Switon\Caching\Tests\Fixtures\MockRedisPipeline;
use Switon\Caching\Tests\TestCase;
use Switon\Core\Json;
use TypeError;

/**
 * Edge cases and vulnerability tests for SimpleCache.
 *
 * Tests boundary conditions, error handling, and edge cases that could cause failures.
 */
class SimpleCacheEdgeCaseTest extends TestCase
{
    protected SimpleCache $cache; // @phpstan-ignore property.uninitialized
    protected MockRedisClient $mockRedis; // @phpstan-ignore property.uninitialized

    protected function setUp(): void
    {
        parent::setUp();


        $this->mockRedis = new MockRedisClient();
        $this->container->set(\Switon\Redis\ClientInterface::class, $this->mockRedis);

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
     * Test that get() handles empty string key.
     *
     * Verifies that empty string keys are handled correctly.
     */
    public function testGetWithEmptyStringKey(): void
    {
        // Arrange
        $key = '';
        $value = 'test-value';
        $this->mockRedis->storage['test:'] = Json::stringify($value);

        // Act
        $result = $this->cache->get($key);

        // Assert
        $this->assertSame($value, $result, 'get() should handle empty string key');
    }

    /**
     * Test that set() handles empty string key.
     *
     * Verifies that empty string keys can be stored.
     */
    public function testSetWithEmptyStringKey(): void
    {
        // Arrange
        $key = '';
        $value = 'test-value';

        // Act
        $result = $this->cache->set($key, $value);

        // Assert
        $this->assertTrue($result, 'set() should handle empty string key');
        $this->assertArrayHasKey('test:', $this->mockRedis->storage, 'set() should store value with empty key');
    }

    /**
     * Test that get() handles null default value.
     *
     * Verifies that null can be used as default value.
     */
    public function testGetWithNullDefault(): void
    {
        // Arrange
        $key = 'non-existent';

        // Act
        $result = $this->cache->get($key, null);

        // Assert
        $this->assertNull($result, 'get() should return null when default is null');
    }

    /**
     * Test that set() handles null value.
     *
     * Verifies that null values can be stored and retrieved.
     */
    public function testSetAndGetNullValue(): void
    {
        // Arrange
        $key = 'null-key';

        // Act
        $this->cache->set($key, null);
        $result = $this->cache->get($key);

        // Assert
        $this->assertNull($result, 'set() and get() should handle null values');
    }

    /**
     * Test that set() handles false value.
     *
     * Verifies that false values can be stored and retrieved correctly.
     */
    public function testSetAndGetFalseValue(): void
    {
        // Arrange
        $key = 'false-key';

        // Act
        $this->cache->set($key, false);
        $result = $this->cache->get($key);

        // Assert
        $this->assertFalse($result, 'set() and get() should handle false values');
        $this->assertSame(false, $result, 'get() should return exact false value');
    }

    /**
     * Test that set() and get() preserve true boolean values.
     */
    public function testSetAndGetTrueValue(): void
    {
        $key = 'true-key';

        $this->cache->set($key, true);
        $result = $this->cache->get($key);

        $this->assertTrue($result);
        $this->assertSame(true, $result);
    }

    /**
     * Test that set() handles zero value.
     *
     * Verifies that zero values can be stored and retrieved correctly.
     */
    public function testSetAndGetZeroValue(): void
    {
        // Arrange
        $key = 'zero-key';

        // Act
        $this->cache->set($key, 0);
        $result = $this->cache->get($key);

        // Assert
        $this->assertSame(0, $result, 'set() and get() should handle zero values');
    }

    /**
     * Test that set() handles empty string value.
     *
     * Verifies that empty string values can be stored and retrieved.
     */
    public function testSetAndGetEmptyStringValue(): void
    {
        // Arrange
        $key = 'empty-string-key';

        // Act
        $this->cache->set($key, '');
        $result = $this->cache->get($key);

        // Assert
        $this->assertSame('', $result, 'set() and get() should handle empty string values');
    }

    /**
     * Test that get() handles invalid JSON in storage.
     *
     * Verifies that corrupted JSON data is handled gracefully.
     */
    public function testGetWithInvalidJson(): void
    {
        // Arrange
        $key = 'invalid-json-key';
        $this->mockRedis->storage['test:' . $key] = 'invalid json {';

        // Act & Assert
        $this->expectException(\Switon\Core\Exception\JsonException::class);

        $this->cache->get($key);
    }

    /**
     * Test that set() handles very long key names.
     *
     * Verifies that long key names are handled correctly.
     */
    public function testSetWithVeryLongKey(): void
    {
        // Arrange
        $key = str_repeat('a', 10000);
        $value = 'test-value';

        // Act
        $result = $this->cache->set($key, $value);

        // Assert
        $this->assertTrue($result, 'set() should handle very long keys');
        $this->assertArrayHasKey('test:' . $key, $this->mockRedis->storage, 'set() should store value with long key');
    }

    /**
     * Test that set() handles very large values.
     *
     * Verifies that large values can be stored and retrieved.
     */
    public function testSetAndGetLargeValue(): void
    {
        // Arrange
        $key = 'large-value-key';
        $value = str_repeat('x', 100000); // 100KB

        // Act
        $this->cache->set($key, $value);
        $result = $this->cache->get($key);

        // Assert
        $this->assertSame($value, $result, 'set() and get() should handle large values');
        $this->assertSame(100000, strlen($result), 'get() should return complete large value');
    }

    /**
     * Test that set() handles negative TTL.
     *
     * Verifies that negative TTL values are handled (should expire immediately or be rejected).
     */
    public function testSetWithNegativeTtl(): void
    {
        // Arrange
        $key = 'negative-ttl-key';
        $value = 'test-value';
        $ttl = -1;

        // Act
        $result = $this->cache->set($key, $value, $ttl);

        // Assert
        $this->assertTrue($result, 'set() should accept negative TTL (Redis may handle it)');
    }

    /**
     * Test that set() handles zero TTL.
     *
     * Verifies that zero TTL is handled correctly.
     */
    public function testSetWithZeroTtl(): void
    {
        // Arrange
        $key = 'zero-ttl-key';
        $value = 'test-value';
        $ttl = 0;

        // Act
        $result = $this->cache->set($key, $value, $ttl);

        // Assert
        $this->assertTrue($result, 'set() should accept zero TTL');
    }

    /**
     * Test that set() handles very large TTL.
     *
     * Verifies that large TTL values are handled correctly.
     */
    public function testSetWithLargeTtl(): void
    {
        // Arrange
        $key = 'large-ttl-key';
        $value = 'test-value';
        $ttl = PHP_INT_MAX;

        // Act
        $result = $this->cache->set($key, $value, $ttl);

        // Assert
        $this->assertTrue($result, 'set() should handle very large TTL values');
    }

    /**
     * Test that getMultiple() handles empty iterable.
     *
     * Verifies that empty key arrays are handled correctly.
     */
    public function testGetMultipleWithEmptyIterable(): void
    {
        // Arrange
        $keys = [];

        // Act
        $result = $this->cache->getMultiple($keys);

        // Assert
        $this->assertIsIterable($result, 'getMultiple() should return iterable for empty keys');
        $resultArray = iterator_to_array($result);
        $this->assertEmpty($resultArray, 'getMultiple() should return empty array for empty keys');
    }

    /**
     * Test that setMultiple() handles empty iterable.
     *
     * Verifies that empty value arrays are handled correctly.
     */
    public function testSetMultipleWithEmptyIterable(): void
    {
        // Arrange
        $values = [];

        // Act
        $result = $this->cache->setMultiple($values);

        // Assert
        $this->assertTrue($result, 'setMultiple() should handle empty iterable');
    }

    /**
     * Test that setMultiple() accepts Traversable input directly.
     */
    public function testSetMultipleWithDirectTraversableInput(): void
    {
        // Arrange
        $values = (function () {
            yield 'key1' => 'value1';
            yield 'key2' => ['nested' => 'value2'];
        })();

        // Act
        $result = $this->cache->setMultiple($values, 120);

        // Assert
        $this->assertTrue($result);
        $this->assertSame(Json::stringify('value1'), $this->mockRedis->storage['test:key1']);
        $this->assertSame(Json::stringify(['nested' => 'value2']), $this->mockRedis->storage['test:key2']);
    }

    /**
     * Test that deleteMultiple() handles empty iterable.
     *
     * Verifies that empty key arrays are handled correctly.
     */
    public function testDeleteMultipleWithEmptyIterable(): void
    {
        // Arrange
        $keys = [];

        // Act
        $result = $this->cache->deleteMultiple($keys);

        // Assert
        $this->assertTrue($result, 'deleteMultiple() should handle empty iterable');
    }

    /**
     * Test that getMultiple() handles keys with special characters.
     *
     * Verifies that keys with special characters are handled correctly.
     */
    public function testGetMultipleWithSpecialCharacters(): void
    {
        // Arrange
        $keys = ['key:with:colons', 'key with spaces', 'key-with-dashes', 'key_with_underscores'];
        $this->mockRedis->storage['test:key:with:colons'] = Json::stringify('value1');
        $this->mockRedis->storage['test:key with spaces'] = Json::stringify('value2');

        // Act
        $result = $this->cache->getMultiple($keys, 'default');

        // Assert
        $resultArray = iterator_to_array($result);
        $this->assertSame('value1', $resultArray['key:with:colons'], 'getMultiple() should handle colons in keys');
        $this->assertSame('value2', $resultArray['key with spaces'], 'getMultiple() should handle spaces in keys');
        $this->assertSame('default', $resultArray['key-with-dashes'], 'getMultiple() should handle dashes in keys');
    }

    /**
     * Test that has() handles non-existent key.
     *
     * Verifies that has() returns false for non-existent keys.
     */
    public function testHasWithNonExistentKey(): void
    {
        // Arrange
        $key = 'non-existent-key';

        // Act
        $result = $this->cache->has($key);

        // Assert
        $this->assertFalse($result, 'has() should return false for non-existent keys');
    }

    /**
     * Test that delete() handles non-existent key.
     *
     * Verifies that delete() returns true even for non-existent keys.
     */
    public function testDeleteWithNonExistentKey(): void
    {
        // Arrange
        $key = 'non-existent-key';

        // Act
        $result = $this->cache->delete($key);

        // Assert
        $this->assertTrue($result, 'delete() should return true even for non-existent keys');
    }

    /**
     * Test that getMultiple() handles duplicate keys.
     *
     * Verifies that duplicate keys in the input are handled correctly.
     */
    public function testGetMultipleWithDuplicateKeys(): void
    {
        // Arrange
        $keys = ['key1', 'key2', 'key1']; // Duplicate key1
        $this->mockRedis->storage['test:key1'] = Json::stringify('value1');
        $this->mockRedis->storage['test:key2'] = Json::stringify('value2');

        // Act
        $result = $this->cache->getMultiple($keys);

        // Assert
        $resultArray = iterator_to_array($result);
        // Note: getMultiple() processes keys in order, so duplicate keys may overwrite
        $this->assertArrayHasKey('key1', $resultArray, 'getMultiple() should handle duplicate keys');
        $this->assertArrayHasKey('key2', $resultArray, 'getMultiple() should handle all unique keys');
    }

    /**
     * Test that setMultiple() handles duplicate keys.
     *
     * Verifies that duplicate keys in the input are handled correctly (last value wins).
     */
    public function testSetMultipleWithDuplicateKeys(): void
    {
        // Arrange
        $values = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];
        $values['key1'] = 'value1-overwritten'; // Duplicate key, last value wins

        // Act
        $result = $this->cache->setMultiple($values);

        // Assert
        $this->assertTrue($result, 'setMultiple() should handle duplicate keys');
        $this->assertSame(
            Json::stringify('value1-overwritten'),
            $this->mockRedis->storage['test:key1'],
            'setMultiple() should use last value for duplicate keys'
        );
    }

    /**
     * Test that getMultiple() handles non-array iterable (e.g., Generator).
     *
     * Verifies that getMultiple() works with different iterable types.
     * Note: getMultiple() uses array indexing which requires array keys, so we convert to array first.
     */
    public function testGetMultipleWithGenerator(): void
    {
        // Arrange
        $keysGenerator = function () {
            yield 'key1';
            yield 'key2';
        };
        // Convert generator to array since getMultiple() uses array indexing
        $keys = iterator_to_array($keysGenerator());
        $this->mockRedis->storage['test:key1'] = Json::stringify('value1');

        // Act
        $result = $this->cache->getMultiple($keys, 'default');

        // Assert
        $resultArray = iterator_to_array($result);
        $this->assertSame('value1', $resultArray['key1'], 'getMultiple() should work with generator keys converted to array');
        $this->assertSame('default', $resultArray['key2'], 'getMultiple() should work with generator keys converted to array');
    }

    /**
     * Test that getMultiple() accepts Traversable directly without pre-conversion.
     *
     * Verifies the Traversable normalization branch in SimpleCache::getMultiple().
     */
    public function testGetMultipleWithDirectTraversableInput(): void
    {
        // Arrange
        $keys = (function () {
            yield 'key1';
            yield 'key2';
        })();
        $this->mockRedis->storage['test:key1'] = Json::stringify('value1');

        // Act
        $result = $this->cache->getMultiple($keys, 'default');

        // Assert
        $resultArray = iterator_to_array($result);
        $this->assertSame('value1', $resultArray['key1'], 'getMultiple() should resolve first generator key');
        $this->assertSame('default', $resultArray['key2'], 'getMultiple() should apply default for missing key');
    }

    /**
     * Test that clear() handles empty cache.
     *
     * Verifies that clear() works correctly when cache is empty.
     */
    public function testClearWithEmptyCache(): void
    {
        // Arrange - No keys in storage

        // Act
        $result = $this->cache->clear();

        // Assert
        $this->assertTrue($result, 'clear() should return true even when cache is empty');
    }

    /**
     * Test that clear() continues scanning after an intermediate empty batch.
     *
     * Verifies compatibility with scan implementations that may return empty
     * results before iteration is complete.
     */
    public function testClearContinuesWhenScanReturnsIntermediateEmptyBatch(): void
    {
        $client = new class () extends MockRedisClient {
            public int $scanCount = 0;

            public function getTransient(): static
            {
                return $this;
            }

            public function scan(mixed &$iterator, string $pattern = '', int $count = 10): array
            {
                $this->scanCount++;
                if ($this->scanCount === 1) {
                    $iterator = 1;
                    return [];
                }
                if ($this->scanCount === 2) {
                    $iterator = 0;
                    return ['test:key1', 'test:key2'];
                }

                $iterator = 0;
                return [];
            }
        };

        $client->storage['test:key1'] = Json::stringify('v1');
        $client->storage['test:key2'] = Json::stringify('v2');
        $this->cache = $this->make(SimpleCache::class, [
            'redisCache' => $client,
            'eventDispatcher' => $this->eventDispatcher,
            'prefix' => 'test:',
        ]);

        $result = $this->cache->clear();

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('test:key1', $client->storage);
        $this->assertArrayNotHasKey('test:key2', $client->storage);
        $this->assertGreaterThanOrEqual(2, $client->scanCount);
    }

    /**
     * Test that clear() exits when scan returns empty batch and iterator is finished.
     */
    public function testClearStopsWhenScanReturnsEmptyBatchAtEnd(): void
    {
        $client = new class () extends MockRedisClient {
            public function getTransient(): static
            {
                return $this;
            }

            public function scan(mixed &$iterator, string $pattern = '', int $count = 10): array
            {
                $iterator = null;
                return [];
            }
        };

        $client->storage['test:key1'] = Json::stringify('v1');
        $this->cache = $this->make(SimpleCache::class, [
            'redisCache' => $client,
            'eventDispatcher' => $this->eventDispatcher,
            'prefix' => 'test:',
        ]);

        $result = $this->cache->clear();

        $this->assertTrue($result);
        $this->assertArrayHasKey('test:key1', $client->storage);
        $this->assertArrayNotHasKey('del', $client->calls);
    }

    /**
     * Test that getMultiple() ignores unmatched extra pipeline results safely.
     */
    public function testGetMultipleIgnoresExtraPipelineResults(): void
    {
        $client = new class () extends MockRedisClient {
            public function getTransient(): static
            {
                return $this;
            }

            public function pipeline(): MockRedisPipeline
            {
                return new class ($this) extends MockRedisPipeline {
                    public function exec(): array
                    {
                        return ['"v1"', '"v2"', '"extra-ignored"'];
                    }
                };
            }
        };

        $this->cache = $this->make(SimpleCache::class, [
            'redisCache' => $client,
            'eventDispatcher' => $this->eventDispatcher,
            'prefix' => 'test:',
        ]);

        $result = $this->cache->getMultiple(['k1', 'k2'], 'default');
        $resultArray = iterator_to_array($result);

        $this->assertSame(['k1' => 'v1', 'k2' => 'v2'], $resultArray);
    }

    /**
     * Test that getMultiple() tolerates missing pipeline results.
     */
    public function testGetMultipleHandlesFewerPipelineResultsThanRequestedKeys(): void
    {
        $client = new class () extends MockRedisClient {
            public function getTransient(): static
            {
                return $this;
            }

            public function pipeline(): MockRedisPipeline
            {
                return new class ($this) extends MockRedisPipeline {
                    public function exec(): array
                    {
                        return ['"v1"'];
                    }
                };
            }
        };

        $this->cache = $this->make(SimpleCache::class, [
            'redisCache' => $client,
            'eventDispatcher' => $this->eventDispatcher,
            'prefix' => 'test:',
        ]);

        $result = $this->cache->getMultiple(['k1', 'k2'], 'default');
        $resultArray = iterator_to_array($result);

        $this->assertSame(['k1' => 'v1'], $resultArray);
    }

    /**
     * Test that set() handles DateInterval with zero duration.
     *
     * Verifies that DateInterval with zero seconds is handled correctly.
     */
    public function testSetWithZeroDurationDateInterval(): void
    {
        // Arrange
        $key = 'zero-interval-key';
        $value = 'test-value';
        $ttl = new DateInterval('PT0S'); // Zero seconds

        // Act
        $result = $this->cache->set($key, $value, $ttl);

        // Assert
        $this->assertTrue($result, 'set() should handle DateInterval with zero duration');
    }

    /**
     * Test that get() handles key with unicode characters.
     *
     * Verifies that unicode key names are handled correctly.
     */
    public function testGetWithUnicodeKey(): void
    {
        // Arrange
        $key = '测试-ключ-テスト';
        $value = 'test-value';
        $this->mockRedis->storage['test:' . $key] = Json::stringify($value);

        // Act
        $result = $this->cache->get($key);

        // Assert
        $this->assertSame($value, $result, 'get() should handle unicode keys');
    }

    /**
     * Test that set() handles nested arrays with circular references (should fail gracefully).
     *
     * Verifies that circular references in values are handled.
     */
    public function testSetWithCircularReference(): void
    {
        // Arrange
        $key = 'circular-key';
        $value = [];
        $value['self'] = &$value; // Circular reference

        // Act & Assert
        // JSON encoding should fail for circular references
        $this->expectException(\Switon\Core\Exception\JsonException::class);

        $this->cache->set($key, $value);
    }

    /**
     * Test that getMultiple() handles keys array with non-string keys (should fail).
     *
     * Verifies that type errors are caught for invalid key types.
     */
    public function testGetMultipleWithNonStringKeys(): void
    {
        // Arrange
        $keys = ['key1', 123, 'key2']; // Mixed types

        // Act & Assert
        // This should work in PHP 8+ as foreach handles mixed types
        // But Redis get() expects string, so this may cause issues
        // Let's see what happens
        try {
            $result = $this->cache->getMultiple($keys, 'default');
            $resultArray = iterator_to_array($result);
            // If it doesn't throw, verify behavior
            $this->assertArrayHasKey('key1', $resultArray, 'getMultiple() should handle mixed key types');
        } catch (TypeError $e) {
            // Type error is acceptable for invalid key types
            $this->assertStringContainsString(
                'string',
                $e->getMessage(),
                'Type error should mention string requirement'
            );
        }
    }

    /**
     * Test that getMultiple() applies null default for missing keys.
     */
    public function testGetMultipleUsesNullDefaultForMissingKeys(): void
    {
        $result = $this->cache->getMultiple(['missing-1', 'missing-2'], null);
        $resultArray = iterator_to_array($result);

        $this->assertArrayHasKey('missing-1', $resultArray);
        $this->assertArrayHasKey('missing-2', $resultArray);
        $this->assertNull($resultArray['missing-1']);
        $this->assertNull($resultArray['missing-2']);
    }

    /**
     * Test that setMultiple() applies DateInterval TTL to each queued set.
     */
    public function testSetMultipleWithDateIntervalAppliesTtlToEachSetCall(): void
    {
        $ttl = new DateInterval('PT5M');
        $result = $this->cache->setMultiple(['k1' => 'v1', 'k2' => 'v2'], $ttl);

        $this->assertTrue($result);
        $setCalls = $this->mockRedis->calls['set'] ?? [];
        $this->assertCount(2, $setCalls);
        $this->assertSame(300, $setCalls[0]['ttl']);
        $this->assertSame(300, $setCalls[1]['ttl']);
    }

    /**
     * Test that deleteMultiple() accepts generator keys directly.
     */
    public function testDeleteMultipleWithGeneratorInputDeletesAllKeys(): void
    {
        $this->mockRedis->storage['test:k1'] = 'v1';
        $this->mockRedis->storage['test:k2'] = 'v2';

        $keys = (function () {
            yield 'k1';
            yield 'k2';
        })();

        $result = $this->cache->deleteMultiple($keys);

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('test:k1', $this->mockRedis->storage);
        $this->assertArrayNotHasKey('test:k2', $this->mockRedis->storage);
    }

    /**
     * Test that has() returns false after immediate expiry.
     */
    public function testHasReturnsFalseForImmediatelyExpiredKey(): void
    {
        $this->cache->set('expiring', 'v', -1);

        $this->assertFalse($this->cache->has('expiring'));
    }

    /**
     * Test that clear() deletes final non-empty batch when iterator is 0.
     */
    public function testClearDeletesBatchWhenIteratorEndsAtZero(): void
    {
        $client = new class () extends MockRedisClient {
            public function getTransient(): static
            {
                return $this;
            }

            public function scan(mixed &$iterator, string $pattern = '', int $count = 10): array
            {
                $iterator = 0;
                return ['test:final1', 'test:final2'];
            }
        };

        $client->storage['test:final1'] = Json::stringify('v1');
        $client->storage['test:final2'] = Json::stringify('v2');
        $this->cache = $this->make(SimpleCache::class, [
            'redisCache' => $client,
            'eventDispatcher' => $this->eventDispatcher,
            'prefix' => 'test:',
        ]);

        $result = $this->cache->clear();

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('test:final1', $client->storage);
        $this->assertArrayNotHasKey('test:final2', $client->storage);
    }

    /**
     * Test that deleteMultiple() with numeric keys removes matching prefixed keys.
     */
    public function testDeleteMultipleWithNumericKeysDeletesPrefixedEntries(): void
    {
        $this->mockRedis->storage['test:1'] = 'v1';
        $this->mockRedis->storage['test:2'] = 'v2';

        $result = $this->cache->deleteMultiple([1, 2]);

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('test:1', $this->mockRedis->storage);
        $this->assertArrayNotHasKey('test:2', $this->mockRedis->storage);
    }

    /**
     * Test that getMultiple() accepts numeric default values.
     */
    public function testGetMultipleWithNumericDefaultValue(): void
    {
        $result = $this->cache->getMultiple(['m1', 'm2'], 123);
        $resultArray = iterator_to_array($result);

        $this->assertSame(123, $resultArray['m1']);
        $this->assertSame(123, $resultArray['m2']);
    }

    /**
     * Test that setMultiple() handles empty string keys.
     */
    public function testSetMultipleWithEmptyStringKey(): void
    {
        $result = $this->cache->setMultiple(['' => 'empty-key-value']);

        $this->assertTrue($result);
        $this->assertArrayHasKey('test:', $this->mockRedis->storage);
    }

    /**
     * Test that deleteMultiple() handles empty string key.
     */
    public function testDeleteMultipleWithEmptyStringKey(): void
    {
        $this->mockRedis->storage['test:'] = 'empty';

        $result = $this->cache->deleteMultiple(['']);

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('test:', $this->mockRedis->storage);
    }

    /**
     * Test that has() returns true for key with unicode characters.
     */
    public function testHasWithUnicodeKey(): void
    {
        $key = '键-ключ-キー';
        $this->cache->set($key, 'value');

        $this->assertTrue($this->cache->has($key));
    }

    /**
     * Test that set() overwrites existing key value.
     */
    public function testSetOverwritesExistingValue(): void
    {
        $this->cache->set('overwrite', 'v1');
        $this->cache->set('overwrite', 'v2');

        $this->assertSame('v2', $this->cache->get('overwrite'));
    }

    /**
     * Test that get() returns default for expired key metadata.
     */
    public function testGetReturnsDefaultWhenStoredKeyAlreadyExpired(): void
    {
        $this->cache->set('exp', 'value', -1);

        $this->assertSame('fallback', $this->cache->get('exp', 'fallback'));
    }

    /**
     * Test that setMultiple() handles unicode keys.
     */
    public function testSetMultipleWithUnicodeKeys(): void
    {
        $result = $this->cache->setMultiple(['测试' => 'v1', 'ключ' => 'v2']);

        $this->assertTrue($result);
        $this->assertArrayHasKey('test:测试', $this->mockRedis->storage);
        $this->assertArrayHasKey('test:ключ', $this->mockRedis->storage);
    }

    /**
     * Test that getMultiple() returns empty array for empty traversable input.
     */
    public function testGetMultipleWithEmptyTraversableInput(): void
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
     * Test that delete() returns true for empty string key.
     */
    public function testDeleteWithEmptyStringKeyReturnsTrue(): void
    {
        $this->mockRedis->storage['test:'] = 'v';

        $result = $this->cache->delete('');

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('test:', $this->mockRedis->storage);
    }
}
