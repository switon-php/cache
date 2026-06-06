<?php

declare(strict_types=1);

namespace Switon\Caching\Command;

use Psr\SimpleCache\CacheInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Redis\ClientInterface;
use Switon\Redis\Exception\CallInPoolException;
use Throwable;

use function count;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Manage cache namespace keys and maintenance tasks.
 *
 * Guidance: Use for prefix-scoped cache inspection and clear operations from CLI.
 *
 * Road-signs:
 * - prefix scopes listAction() and clearAction()
 * - listAction() scans Redis keys directly
 * - clearAction() delegates to CacheInterface::clear()
 *
 * @see \Psr\SimpleCache\CacheInterface
 * @see \Switon\Caching\SimpleCache
 * @see \Switon\Redis\ClientInterface
 */
class CacheCommand
{
    #[Autowired] protected ConsoleInterface $console;

    #[Autowired] protected CacheInterface $cache;

    #[Autowired] protected ClientInterface $redisCache;

    /** Cache key prefix that scopes list and clear operations. */
    #[Autowired] protected string $prefix = 'cache:';

    /**
     * List keys under the configured cache prefix
     *
     * @param bool $showTtl Show TTL column (seconds, permanent, or expired)
     */
    public function listAction(bool $showTtl = false): int
    {
        try {
            if ($this->prefix === '') {
                return $this->console->error('Cache prefix must be configured to list cache keys.');
            }

            $this->console->writeLn("Cache prefix: $this->prefix");
            $this->console->writeLn();

            // Use transient client for scan operation (required for connection pool compatibility)
            $transient = $this->redisCache->getTransient();
            $iterator = null;
            $keys = [];
            $pattern = $this->prefix . '*';

            // Collect all keys matching prefix
            // Use same pattern as SimpleCache::clear() - check for non-empty array
            while (true) {
                $batch = $transient->scan($iterator, $pattern, 100);
                if (!is_array($batch) || empty($batch)) {
                    // No more keys or error - check if iterator is done
                    if ($iterator === 0 || $iterator === null) {
                        break;
                    }
                    // Iterator not done but batch is empty - continue
                    continue;
                }

                foreach ($batch as $fullKey) {
                    // Remove prefix to show clean key name
                    if (str_starts_with($fullKey, $this->prefix)) {
                        $key = substr($fullKey, strlen($this->prefix));
                        $keys[] = [
                            'key' => $key,
                            'fullKey' => $fullKey,
                        ];
                    }
                }

                // If iterator is 0 or null, we're done
                if ($iterator === 0 || $iterator === null) {
                    break;
                }
            }

            if (empty($keys)) {
                $this->console->writeLn('No cache keys found.');
                return 0;
            }

            // Sort keys alphabetically
            usort($keys, static fn ($a, $b) => strcmp($a['key'], $b['key']));

            // Display keys
            $this->console->writeLn(sprintf('Found %d cache key(s):', count($keys)));
            $this->console->writeLn();

            if ($showTtl) {
                $tableRows = [];
                foreach ($keys as $keyInfo) {
                    $ttlValue = $this->redisCache->ttl($keyInfo['fullKey']);
                    $ttlDisplay = $ttlValue === -1 ? 'permanent' : ($ttlValue === -2 ? 'expired' : $ttlValue . 's');
                    $tableRows[] = [$keyInfo['key'], $ttlDisplay];
                }
                $this->console->table(['KEY', 'TTL'], $tableRows);
            } else {
                // Simple list without TTL
                foreach ($keys as $keyInfo) {
                    $this->console->writeLn('  ' . $keyInfo['key']);
                }
            }

            return 0;
        } catch (CallInPoolException $e) {
            return $this->console->error('Redis connection not configured for cache. Set Redis client URI to use cache:list.');
        } catch (Throwable $e) {
            return $this->console->error('Failed to list cache keys: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete all keys under the configured cache prefix
     */
    public function clearAction(): int
    {
        try {
            $this->cache->clear();
            $this->console->writeLn('Cache cleared successfully.');
            return 0;
        } catch (Throwable $e) {
            return $this->console->error('Failed to clear cache: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
