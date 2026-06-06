<?php

declare(strict_types=1);

namespace Switon\Caching\Event;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted when multiple cache keys are written in one batch (e.g. <code>setMultiple()</code>).
 *
 * Log category: <code>switon.caching.cache.multiple_written</code>
 * Payload: cache instance, written key-value pairs, TTL input, and prefix.
 *
 * @see \Psr\SimpleCache\CacheInterface
 * @see \Switon\Caching\Event\CacheWritten Single-key write with one value.
 * @see \Switon\Caching\Event\CacheDeleted Batch delete exposes keys only; batch write exposes key-value pairs in <code>values</code>.
 */
#[EventLevel(Severity::DEBUG)]
class CacheMultipleWritten
{
    /**
     * @param CacheInterface $cache Cache instance that handled the write
     * @param array<string, mixed> $values Keys and values written (without prefix; keys are normalized to strings)
     * @param null|int|DateInterval $ttl Time-to-live passed to setMultiple()
     * @param string $prefix Cache prefix used
     */
    public function __construct(
        public CacheInterface        $cache,
        public array                 $values,
        public null|int|DateInterval $ttl,
        public string                $prefix,
    ) {
    }
}
