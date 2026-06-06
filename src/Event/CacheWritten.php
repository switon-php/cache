<?php

declare(strict_types=1);

namespace Switon\Caching\Event;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted when a cache key is written with an optional TTL.
 *
 * Log category: <code>switon.caching.cache.written</code>
 * Payload: cache instance, raw key, pre-serialization value, TTL input, and prefix.
 *
 * @see \Psr\SimpleCache\CacheInterface
 * @see \Switon\Caching\Event\CacheMultipleWritten
 * @see \Switon\Caching\Event\CacheDeleted
 */
#[EventLevel(Severity::DEBUG)]
class CacheWritten
{
    /**
     * @param CacheInterface $cache Cache instance that handled the write
     * @param string $key Cache key (without prefix)
     * @param mixed $value Value written (before serialization)
     * @param null|int|DateInterval $ttl Time-to-live passed to set()
     * @param string $prefix Cache prefix used
     */
    public function __construct(
        public CacheInterface        $cache,
        public string                $key,
        public mixed                 $value,
        public null|int|DateInterval $ttl,
        public string                $prefix
    ) {
    }
}
