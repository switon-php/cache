<?php

declare(strict_types=1);

namespace Switon\Caching\Event;

use Psr\SimpleCache\CacheInterface;
use Switon\Core\SenderAwareInterface;
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
class CacheCleared implements SenderAwareInterface
{
    public function __construct(
        public CacheInterface $sender,
        public int            $deletedCount,
        public string         $prefix,
    ) {
    }

    public function getSender(): CacheInterface
    {
        return $this->sender;
    }
}
