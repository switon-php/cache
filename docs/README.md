# Cache Package {#cache-package}

`switon/cache` provides Switon apps with **one Redis-backed** `Psr\SimpleCache\CacheInterface`: readable keys, JSON values, prefix-scoped `clear()`, and typed miss handling.

**Key features:**

- **Standard contract:** inject `Psr\SimpleCache\CacheInterface` and call normal PSR-16 methods.
- **Redis-friendly keys:** names such as `user:123` and `config:v2:app` stay readable.
- **Interoperable values:** writes are stored as JSON for inspection and sharing across processes.
- **Automatic encoding:** arrays and scalars serialize without manual `json_encode` / `decode` at call sites.
- **Safe cleanup:** `clear()` requires a **non-empty prefix** so shared Redis namespaces stay isolated.
- **Batch APIs:** `getMultiple()`, `setMultiple()`, and `deleteMultiple()` for multi-key workloads.
- **Events:** `CacheHit`, `CacheMiss`, `CacheWritten`, `CacheMultipleWritten`, `CacheDeleted`, and `CacheCleared` fire on matching operations.

## Installation {#installation}

**Requirements:** PHP 8.3+, `ext-redis`

```bash
composer require switon/cache
```

## Quick Start {#quick-start}

Inject `Psr\SimpleCache\CacheInterface` and follow a **cache-aside** flow.

```php
namespace App\Service;

use stdClass;
use Psr\SimpleCache\CacheInterface;
use Switon\Core\Attribute\Autowired;

class ProductService
{
    #[Autowired] protected CacheInterface $cache;

    public function getFeatured(): array
    {
        $miss = new stdClass();
        $products = $this->cache->get('featured_products', $miss);

        if ($products === $miss) {
            $products = $this->loadFeaturedProducts();
            $this->cache->set('featured_products', $products, 1800);
        }

        return $products;
    }
}
```

- Use a **custom miss marker** when a cached **`null`** is valid.
- If `null` is never cached on that path, a plain `get()` check is acceptable.

## Core Concepts {#core-concepts}

### Keys {#keys}

Keep **stable**, app-owned key strings and JSON-friendly payloads.

- Keys such as `user:123` and `config:v2:app` match typical Redis conventions.
- Reads deserialize to arrays, scalars, or `null`.
- Recreate rich domain objects in application code when you need PHP types.

### Values {#values}

Every stored value passes through JSON encoding before Redis.

- Other services can read entries without PHP-specific serialization.
- Application code passes native arrays and scalars only.

### Misses {#misses}

Choose a sentinel object whenever **`null`** is indistinguishable from an unset key.

```php
$miss = new \stdClass();
$value = $cache->get($key, $miss);

if ($value === $miss) {
    // load and set
}
```

### TTL {#size-and-ttl}

`set()` accepts **seconds**, a **`DateInterval`**, or **`null`** (no TTL).

### Prefixes {#prefixes}

- Default prefix: `cache:`.
- `clear()` deletes only keys matching the configured prefix, isolating unrelated Redis databases.

## Configuration {#configuration}

By default `Psr\SimpleCache\CacheInterface` binds to `Switon\Caching\SimpleCache`.

Add configuration only for a **custom prefix**, a **different Redis client**, or **multiple cache namespaces**.

### Basic Configuration {#basic-configuration}

```yaml
# switon.yml
Psr\SimpleCache\CacheInterface:
  prefix: 'myapp:'
```

### Multiple Instances {#multiple-instances}

Use `Switon\Di\Factory` when you need parallel namespaces:

```yaml
# switon.yml
Psr\SimpleCache\CacheInterface:
  class: Switon\Di\Factory
  default:
    class: Switon\Caching\SimpleCache
    prefix: 'cache:'
  users:
    class: Switon\Caching\SimpleCache
    prefix: 'users:'
```

Inject the alias that matches your property name, for example `#[Autowired] protected CacheInterface $users;`

If one cache instance should use a different Redis client, point its `redisCache` slot at that named client:

```yaml
# switon.yml
Psr\SimpleCache\CacheInterface:
  class: Switon\Di\Factory
  default:
    class: Switon\Caching\SimpleCache
    prefix: 'cache:'
  pageCache:
    class: Switon\Caching\SimpleCache
    prefix: 'page:'
    redisCache: '#pageCache'
```

```php
use Psr\SimpleCache\CacheInterface;
use Switon\Core\Attribute\Autowired;

class UserCacheWarmer
{
    #[Autowired] protected CacheInterface $users;
}
```

### Option Discovery {#option-discovery}

Run:

```bash
bash bin/console config:options 'Psr\SimpleCache\CacheInterface'
```

## Operations {#operations}

### Batch Operations {#batch-operations}

Use batch helpers when keys are already grouped:

```php
$users = $cache->getMultiple(['user:1', 'user:2']);

$cache->setMultiple([
    'user:1' => ['id' => 1],
    'user:2' => ['id' => 2],
], 3600);

$cache->deleteMultiple(['user:1', 'user:2']);
```

### CLI Commands {#cli-commands}

Inspect or prune by prefix:

```bash
bash bin/console cache:list
bash bin/console cache:list --showTtl
bash bin/console cache:clear
```

## Keep in Mind {#keep-in-mind}

- Never call `clear()` with an **empty prefix**; unrelated keys could be deleted.
- `cache:list` enforces the same non-empty-prefix rule.
- `get()` returns **decoded arrays or scalars**, not arbitrary PHP objects.
- Corrupted JSON raises a **parse error**, not a silent miss.
- `has()` followed by `get()` costs **two** Redis operations in the hot path.
- Treat `clear()` as **namespace cleanup**, not a global atomic purge.

## Next {#next}

- **Operational questions:** [FAQ](faq.md#cache-faq)
- **Trade-offs:** [Design Rationale](design.md#design-rationale)
