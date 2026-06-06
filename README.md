# Switon Cache Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/cache/ci.yml?branch=main&label=CI)](https://github.com/switon-php/cache/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's PSR-16 Redis cache for batch reads and writes, prefix-scoped clearing, and cache lifecycle events.

## Highlights

- **PSR-16 cache:** `CacheInterface` is the main cache contract.
- **Redis-backed storage:** `SimpleCache` stores values in Redis with prefix-scoped access.
- **Batch reads and writes:** read or write multiple keys in one call.
- **Safe clearing:** `clear()` requires a prefix so it does not wipe unrelated keys.
- **Lifecycle visibility:** `CacheHit`, `CacheMiss`, and related lifecycle events can be observed.

## Installation

```bash
composer require switon/cache
```

## Quick Start

```php
use Psr\SimpleCache\CacheInterface;
use Switon\Core\Attribute\Autowired;

class ProductService
{
    #[Autowired] protected CacheInterface $cache;

    public function getFeatured(): array
    {
        $miss = new \stdClass();
        $products = $this->cache->get('featured_products', $miss);

        if ($products === $miss) {
            $products = $this->loadFeaturedProducts();
            $this->cache->set('featured_products', $products, 1800);
        }

        return $products;
    }
}
```

Docs: https://docs.switon.dev/latest/cache

## License

MIT.
