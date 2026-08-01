# Cache FAQ {#cache-faq}

Short answers for setup, configuration, and operational issues.

> **Prerequisites:** [Cache Package](README.md)

## Getting Started {#getting-started}

### How do I add caching to my code? {#add-caching}

Inject `Psr\SimpleCache\CacheInterface` and implement cache-aside. See [Quick Start](README.md#quick-start).

### Why does `get()` return arrays instead of objects? {#json-values}

Values are JSON-encoded before storage; reads decode to **arrays**, **scalars**, or **`null`**. Hydrate PHP objects explicitly when required.

### Can the cache persist `null`? {#cache-null}

Yes. Use a **distinct miss marker** when `null` is also a legitimate cached outcome.

### What characters are acceptable in keys? {#key-characters}

Redis-style separators such as `user:123` or `config:v2:app` match Switon defaults. Narrow the alphabet when you share keys with backends that disallow certain bytes.

### How do I set TTL? {#size-and-ttl}

Pass an **`int`** (seconds), a **`DateInterval`**, or **`null`** for no expiry.

## Configuration {#configuration}

### Where do I configure the cache? {#configure}

Set bindings under `Psr\SimpleCache\CacheInterface` in `switon.yml`. The primary isolation knob is **`prefix`** (defaults to `cache:`). If needed, `redisCache` can point one cache instance at a named Redis client.

### Why does `clear()` throw when the prefix is empty? {#prefix-required}

Without a prefix constraint, `clear()` could wipe **unrelated Redis keys**. The package fails fast instead.

`cache:list` applies the identical rule.

### Can I expose multiple caches? {#multiple-instances}

Yes — use `Switon\Di\Factory` aliases or duplicate bindings with distinct prefixes. You can also combine that with `redisCache: '#name'` when one cache should use a non-default Redis client.

## Operations {#operations}

### How do I shrink Redis chatter? {#batch}

Prefer **`getMultiple` / `setMultiple` / `deleteMultiple`** when you already iterate multiple keys.

### Should I guard with `has()` before `get()`? {#has-before-get}

No. Combine **miss markers** or rely on **`get`** defaults to avoid doubling round trips.

### How do I inspect prefixes from CLI? {#cli}

Run `cache:list` and `cache:clear`. Prefixes must match runtime configuration (`cache:` vs custom).

## Troubleshooting {#troubleshooting}

### What should I do when Redis or decoding fails? {#error-handling}

Treat cache as an **optimization**. Catch `\Throwable` around cache touches and fall back to the authoritative datastore.

Broken JSON surfaces as parse errors rather than benign misses — handle explicitly.

Redis connectivity errors differ from malformed payloads.

Both `clear()` (`CachePrefixRequiredException`) and **`cache:list`** reject empty prefixes for safety.

### `get()` returns `null`, but data should exist {#cache-miss}

Use miss markers unless you deliberately accept `null` as “empty cache” signal.

### How do I unit test cache usage? {#testing}

Replace `CacheInterface` with test doubles locally, or run integration tests against a disposable Redis dataset.
