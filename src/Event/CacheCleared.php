<?php

declare(strict_types=1);

namespace Switon\Caching\Event;

use Psr\SimpleCache\CacheInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted when a cache prefix is cleared.
 *
 * Log category: <code>switon.caching.cache.cleared</code>
 * Payload: cache instance, deleted count, and prefix.
 *
 * @see \Psr\SimpleCache\CacheInterface
 * @see \Switon\Caching\Event\CacheDeleted
 */
#[EventLevel(Severity::INFO)]
class CacheCleared
{
    /**
     * @param CacheInterface $cache Cache instance that handled the clear
     * @param int $deletedCount Number of keys deleted under the prefix
     * @param string $prefix Cache prefix used
     */
    public function __construct(
        public CacheInterface $cache,
        public int            $deletedCount,
        public string         $prefix,
    ) {
    }
}
