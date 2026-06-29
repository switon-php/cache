<?php

declare(strict_types=1);

namespace Switon\Caching\Event;

use Psr\SimpleCache\CacheInterface;
use Switon\Core\SenderAwareInterface;
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
class CacheDeleted implements SenderAwareInterface
{
    /**
     * @param CacheInterface $sender Cache instance that handled the delete
     * @param array<int, string> $keys Deleted raw cache keys
     * @param string $prefix Cache key prefix
     */
    public function __construct(
        public CacheInterface $sender,
        public array          $keys,
        public string         $prefix
    ) {
    }

    public function getSender(): CacheInterface
    {
        return $this->sender;
    }
}
