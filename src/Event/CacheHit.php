<?php

declare(strict_types=1);

namespace Switon\Caching\Event;

use Psr\SimpleCache\CacheInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted when a cache key is resolved from storage.
 *
 * Log category: <code>switon.caching.cache.hit</code>
 * Payload: cache instance, raw key, deserialized value, and prefix.
 *
 * @see \Psr\SimpleCache\CacheInterface
 * @see \Switon\Caching\Event\CacheMiss
 */
#[EventLevel(Severity::DEBUG)]
class CacheHit implements SenderAwareInterface
{
    public function __construct(
        public CacheInterface $sender,
        public string         $key,
        public mixed          $value,
        public string         $prefix
    ) {
    }

    public function getSender(): CacheInterface
    {
        return $this->sender;
    }
}
