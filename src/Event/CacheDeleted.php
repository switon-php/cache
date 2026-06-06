<?php

declare(strict_types=1);

namespace Switon\Caching\Event;

use Psr\SimpleCache\CacheInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted when one or more cache keys are deleted.
 *
 * Log category: <code>switon.caching.cache.deleted</code>
 * Payload: cache instance, deleted raw keys, and prefix.
 *
 * @see \Psr\SimpleCache\CacheInterface
 * @see \Switon\Caching\Event\CacheWritten
 * @see \Switon\Caching\Event\CacheCleared
 */
#[EventLevel(Severity::DEBUG)]
class CacheDeleted
{
    /**
     * @param CacheInterface $cache Cache instance that handled the deletion
     * @param list<string> $keys Cache keys deleted (without prefix)
     * @param string $prefix Cache prefix used
     */
    public function __construct(
        public CacheInterface $cache,
        public array          $keys,
        public string         $prefix
    ) {
    }
}
