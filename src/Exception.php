<?php

declare(strict_types=1);

namespace Switon\Caching;

use Psr\SimpleCache\CacheException as PsrCacheException;

/**
 * Base cache exception implementing \Psr\SimpleCache\CacheException for PSR-16 compatible error signaling.
 *
 * Use this as the parent for cache-specific guard and backend integration failures.
 *
 * @see \Switon\Caching\Exception\CachePrefixRequiredException
 */
class Exception extends \Switon\Core\Exception implements PsrCacheException
{
}
