<?php

declare(strict_types=1);

namespace Switon\Caching\Event;

use Psr\SimpleCache\CacheInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted when a cache key is missing and the default value is used.
 *
 * Log category: <code>switon.caching.cache.miss</code>
 * Payload: cache instance, raw key, caller default, and prefix.
 *
 * @see \Psr\SimpleCache\CacheInterface
 * @see \Switon\Caching\Event\CacheHit
 */
#[EventLevel(Severity::DEBUG)]
class CacheMiss
{
    /**
     * @param CacheInterface $cache Cache instance that handled the lookup
     * @param string $key Cache key (without prefix)
     * @param mixed $default Default value returned to the caller
     * @param string $prefix Cache prefix used
     */
    public function __construct(
        public CacheInterface $cache,
        public string         $key,
        public mixed          $default,
        public string         $prefix
    ) {
    }
}
