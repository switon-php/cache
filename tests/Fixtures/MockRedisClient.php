<?php

declare(strict_types=1);

namespace Switon\Caching\Tests\Fixtures;

use DateInterval;
use DateTime;

class MockRedisClient implements \Switon\Redis\ClientInterface
{
    public array $storage = [];
    public array $ttl = [];
    public array $calls = [];
    public ?MockRedisPipeline $pipeline = null;

    public function getUri(): ?string
    {
        return null;
    }

    public function get(string $key): string|false
    {
        $this->calls['get'][] = ['key' => $key];

        if (!isset($this->storage[$key])) {
            return false;
        }

        if (isset($this->ttl[$key]) && $this->ttl[$key] < time()) {
            unset($this->storage[$key], $this->ttl[$key]);
            return false;
        }

        return $this->storage[$key];
    }

    public function set(string $key, string $value, null|int|DateInterval $ttl = null): bool
    {
        $this->calls['set'][] = ['key' => $key, 'value' => $value, 'ttl' => $ttl];
        $this->storage[$key] = $value;

        if ($ttl !== null) {
            if ($ttl instanceof DateInterval) {
                $seconds = (new DateTime())->add($ttl)->getTimestamp() - time();
                $this->ttl[$key] = time() + $seconds;
            } else {
                $this->ttl[$key] = time() + $ttl;
            }
        } else {
            unset($this->ttl[$key]);
        }

        return true;
    }

    public function del(string|array $key): int
    {
        $keys = is_array($key) ? $key : [$key];
        $this->calls['del'][] = ['keys' => $keys];

        $deleted = 0;
        foreach ($keys as $item) {
            if (isset($this->storage[$item])) {
                unset($this->storage[$item], $this->ttl[$item]);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function exists(string $key): bool
    {
        $this->calls['exists'][] = ['key' => $key];

        if (!isset($this->storage[$key])) {
            return false;
        }

        if (isset($this->ttl[$key]) && $this->ttl[$key] < time()) {
            unset($this->storage[$key], $this->ttl[$key]);
            return false;
        }

        return true;
    }

    public function scan(mixed &$iterator, string $pattern = '', int $count = 10): array
    {
        $this->calls['scan'][] = ['iterator' => $iterator, 'pattern' => $pattern, 'count' => $count];

        $escaped = preg_quote($pattern, '/');
        $patternRegex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], $escaped) . '$/';

        $matchingKeys = [];
        foreach (array_keys($this->storage) as $key) {
            if (preg_match($patternRegex, $key)) {
                $matchingKeys[] = $key;
            }
        }

        if ($iterator === null) {
            $iterator = 0;
        }

        $offset = (int)$iterator;
        $result = array_slice($matchingKeys, $offset, $count);

        if ($offset + $count >= count($matchingKeys)) {
            $iterator = null;
        } else {
            $iterator = $offset + $count;
        }

        return $result;
    }

    public function pipeline(): MockRedisPipeline
    {
        $this->calls['pipeline'][] = [];
        $this->pipeline = new MockRedisPipeline($this);

        return $this->pipeline;
    }

    public function getTransient(): static
    {
        $this->calls['getTransient'][] = [];
        return $this;
    }

    public function clear(): void
    {
        $this->storage = [];
        $this->ttl = [];
        $this->calls = [];
        $this->pipeline = null;
    }
}
