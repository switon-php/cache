# Design Rationale {#design-rationale}

`switon/cache` standardizes **one opinionated Redis-backed** cache boundary for Switon HTTP and worker apps.

## See also {#see-also}

- [Cache Package](README.md) — usage, configuration, and operations
- [FAQ](faq.md#cache-faq) — common questions and troubleshooting

---

## Core Problem {#core-problem}

Cache integrations usually drift toward:

- **Inconsistent keys** across modules
- **Opaque binary blobs**
- **`clear()`** that endangers unrelated tenants in shared Redis
- Sprinkle of custom helpers duplicating TTL logic

This package trims the defaults to predictable behavior.

## Primary Goal {#primary-goal}

Optimize for mainstream **application-layer** caching:

- **Familiar:** `Psr\SimpleCache` methods
- **Readable keys:** Redis-style naming
- **Inspectable:** JSON payloads
- **Scoped cleanup:** non-empty prefixes

- **Benefit:** most services share one canonical contract (`CacheInterface`).
- **Cost:** multi-backend neutrality and opaque PHP serialization are intentionally **not** first-class defaults.

## Core Choice {#core-choice}

Expose **`Psr\SimpleCache\CacheInterface`** wired to **`Switon\Caching\SimpleCache`**.

- **API surface:** `get`, `set`, `delete`, plus batch primitives
- **Keys:** ergonomic strings tuned for Redis
- **Payloads:** stored as JSON fragments
- **Observability:** hit/miss/write/delete/clear events for listeners

## Trade-Off {#trade-off}

Prefer **JSON persistence** instead of PHP’s native serialization.

- **Benefit:** human-readable payloads and simpler cross-runtime sharing.
- **Cost:** hydrate objects manually after reads.

## Safety Boundary {#safety-boundary}

`clear()` and CLI helpers require **`prefix !== ''`**.

- **Benefit:** prevents accidental nukes inside shared Redis clusters.
- **Cost:** callers must consciously configure prefixes; empty setups fail loudly.

## Why Redis {#why-redis}

Redis offers pooled connections, TTLs, batch primitives, and key scanning suitable for prefixes.

- **Benefit:** small codebase, recognizable ops playbook.
- **Cost:** portability beyond Redis-compatible backends is upstream’s concern.
