<?php

declare(strict_types=1);

namespace Switon\Caching;

use Psr\SimpleCache\CacheInterface;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Integrates the caching component during application startup.
 *
 * Guidance: Override the default binding or injected dependencies in app config when a different cache backend or prefix policy is required.
 *
 * Road-signs:
 * - CacheInterface binds to SimpleCache
 * - config/cache.php overrides prefix and redisCache
 * - SimpleCache emits cache lifecycle events
 *
 * @see \Psr\SimpleCache\CacheInterface
 * @see \Switon\Caching\SimpleCache
 * @see \Switon\Core\ServiceProviderInterface
 */
class ServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritDoc}
     */
    public function register(ContainerInterface $container): void
    {
        $container->set(CacheInterface::class, SimpleCache::class);
    }

    /**
     * {@inheritDoc}
     */
    public function boot(): void
    {
    }
}
