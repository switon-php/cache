<?php

declare(strict_types=1);

namespace Switon\Caching;

use DateInterval;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;
use Switon\Caching\Event\CacheCleared;
use Switon\Caching\Event\CacheDeleted;
use Switon\Caching\Event\CacheHit;
use Switon\Caching\Event\CacheMiss;
use Switon\Caching\Event\CacheMultipleWritten;
use Switon\Caching\Event\CacheWritten;
use Switon\Caching\Exception\CachePrefixRequiredException;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;
use Switon\Redis\ClientInterface;
use Traversable;

use function count;
use function is_array;

/**
 * Redis-backed cache using the <code>CacheInterface</code> API with Switon key and value behavior.
 *
 * Guidance: Use when you want familiar <code>CacheInterface</code> methods with Redis storage and prefix-scoped clear.
 *
 * Road-signs:
 * - values are stored as JSON
 * - prefix scopes reads, writes, and clear()
 * - getMultiple() / setMultiple() use transient pipeline
 * - CacheHit / CacheMiss / CacheWritten / CacheMultipleWritten / CacheDeleted / CacheCleared
 *
 * @see \Psr\SimpleCache\CacheInterface
 * @see \Switon\Caching\Exception\CachePrefixRequiredException
 * @see \Switon\Redis\ClientInterface
 * @see \Switon\Caching\Event\CacheCleared
 * @see \Switon\Caching\Event\CacheHit
 * @see \Switon\Caching\Event\CacheMiss
 * @see \Switon\Caching\Event\CacheWritten
 * @see \Switon\Caching\Event\CacheMultipleWritten
 * @see \Switon\Caching\Event\CacheDeleted
 */
class SimpleCache implements CacheInterface
{
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    #[Autowired] protected ClientInterface $redisCache;

    /** Cache key prefix for namespace isolation; required for <code>clear()</code>. */
    #[Autowired] protected string $prefix = 'cache:';

    /**
     * Normalize PSR-16 TTL to Redis seconds.
     *
     * @return int|null Null means no expiration; <code>DateInterval</code> values clamp non-positive durations to one second, int TTL values pass through unchanged
     */
    protected function normalizeTtl(null|int|DateInterval $ttl): ?int
    {
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();
            $target = $now->add($ttl);
            $seconds = $target->getTimestamp() - $now->getTimestamp();

            return $seconds > 0 ? $seconds : 1;
        }

        return $ttl;
    }

    /**
     * {@inheritDoc}
     *
     * Misses dispatch <code>CacheMiss</code> with the caller default; hits dispatch <code>CacheHit</code> with the deserialized value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (($value = $this->redisCache->get($this->prefix . $key)) === false) {
            $this->eventDispatcher->dispatch(new CacheMiss($this, $key, $default, $this->prefix));
            return $default;
        } else {
            $parsedValue = Json::parse($value);
            $this->eventDispatcher->dispatch(new CacheHit($this, $key, $parsedValue, $this->prefix));
            return $parsedValue;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Values are JSON-serialized before being written to Redis.
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $result = $this->redisCache->set($this->prefix . $key, Json::stringify($value), $this->normalizeTtl($ttl));
        $this->eventDispatcher->dispatch(new CacheWritten($this, $key, $value, $ttl, $this->prefix));
        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * Per PSR-16, returns true even if the key didn't exist (desired state achieved).
     */
    public function delete(string $key): bool
    {
        $this->redisCache->del($this->prefix . $key);
        $this->eventDispatcher->dispatch(new CacheDeleted($this, [$key], $this->prefix));
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @throws CachePrefixRequiredException When prefix is empty (prevents flushing entire Redis DB)
     */
    public function clear(): bool
    {
        if ($this->prefix === '') {
            CachePrefixRequiredException::raise('Cache prefix is required for clear() to avoid clearing unrelated keys');
        }

        // Use transient client for scan operation (required for connection pool compatibility)
        $transient = $this->redisCache->getTransient();
        $iterator = null;
        $pattern = $this->prefix . '*';
        $deletedCount = 0;

        // SCAN semantics (Redis): returns array of keys while iterating, and sets
        // iterator to 0 when complete. Some implementations may return false or
        // an empty array when there are no more results.
        //
        // This loop is written to work correctly with both the real Redis client
        // and the MockRedisClient used in tests.
        while (true) {
            $batch = $transient->scan($iterator, $pattern, 100);

            if (!is_array($batch) || $batch === []) {
                // If iterator is finished (0 or null), we are done. Otherwise,
                // continue scanning in case the implementation signals "no
                // results for this iteration" but has not yet finished.
                if ($iterator === 0 || $iterator === null) {
                    break;
                }
                continue;
            }

            $deletedCount += $this->redisCache->del($batch);

            // Stop if the iterator indicates completion after this batch.
            if ($iterator === 0 || $iterator === null) {
                break;
            }
        }

        $this->eventDispatcher->dispatch(new CacheCleared($this, $deletedCount, $this->prefix));
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Returned values are keyed by the original requested keys.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        // Normalize iterable of keys to a numerically indexed array of key strings.
        // This ensures consistent behavior for arrays, generators, and other Traversables.
        if ($keys instanceof Traversable) {
            $keyArray = array_map('strval', array_values(iterator_to_array($keys, false)));
        } else {
            $keyArray = array_map('strval', array_values((array)$keys));
        }

        if ($keyArray === []) {
            return [];
        }

        // Pipeline is connection-stateful; always use transient client for pooled safety.
        $pipeline = $this->redisCache->getTransient()->pipeline();

        foreach ($keyArray as $key) {
            $pipeline->get($this->prefix . $key);
        }

        $values = [];
        foreach ($pipeline->exec() as $i => $value) {
            $key = $keyArray[$i] ?? null;
            if ($key === null) {
                continue;
            }

            if ($value === false) {
                $values[$key] = $default;
                $this->eventDispatcher->dispatch(new CacheMiss($this, $key, $default, $this->prefix));
            } else {
                $parsedValue = Json::parse($value);
                $values[$key] = $parsedValue;
                $this->eventDispatcher->dispatch(new CacheHit($this, $key, $parsedValue, $this->prefix));
            }
        }

        return $values;
    }

    /**
     * {@inheritDoc}
     *
     * Note: Always returns true; pipeline errors are not reflected in the return value.
     *
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        // Pipeline is connection-stateful; always use transient client for pooled safety.
        $pipeline = $this->redisCache->getTransient()->pipeline();

        $writtenValues = [];
        foreach ($values as $key => $value) {
            $pipeline->set($this->prefix . $key, Json::stringify($value), $this->normalizeTtl($ttl));
            $writtenValues[$key] = $value;
        }

        $pipeline->exec();

        if ($writtenValues !== []) {
            $this->eventDispatcher->dispatch(new CacheMultipleWritten($this, $writtenValues, $ttl, $this->prefix));
        }

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Note: Always returns true; individual key deletion failures are not tracked.
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $prefixedKeys = [];
        $rawKeys = [];
        foreach ($keys as $key) {
            $prefixedKeys[] = $this->prefix . $key;
            $rawKeys[] = (string)$key;
        }

        if (count($prefixedKeys) === 0) {
            return true;
        }

        $this->redisCache->del($prefixedKeys);
        $this->eventDispatcher->dispatch(new CacheDeleted($this, $rawKeys, $this->prefix));
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return $this->redisCache->exists($this->prefix . $key);
    }
}
