<?php

declare(strict_types=1);

namespace Switon\Caching\Exception;

use Switon\Caching\Exception;

/**
 * Use when prefix-scoped clear is requested without a configured prefix.
 *
 * @see \Switon\Caching\SimpleCache
 */
class CachePrefixRequiredException extends Exception
{
}
