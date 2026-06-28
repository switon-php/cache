<?php

declare(strict_types=1);

namespace Switon\Caching\Event;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use Switon\Core\SenderAwareInterface;
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
class CacheWritten implements SenderAwareInterface
{
    public function __construct(
        public CacheInterface $sender,
        public string                $key,
        public mixed                 $value,
        public null|int|DateInterval $ttl,
        public string                $prefix
    ) {
    }

    public function getSender(): CacheInterface
    {
        return $this->sender;
    }
}
