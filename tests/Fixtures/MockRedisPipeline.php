<?php

declare(strict_types=1);

namespace Switon\Caching\Tests\Fixtures;

use DateInterval;

class MockRedisPipeline
{
    public MockRedisClient $client;
    public array $commands = [];

    public function __construct(MockRedisClient $client)
    {
        $this->client = $client;
    }

    public function get(string $key): self
    {
        $this->commands[] = ['method' => 'get', 'args' => [$key]];

        return $this;
    }

    public function set(string $key, string $value, null|int|DateInterval $ttl = null): self
    {
        $this->commands[] = ['method' => 'set', 'args' => [$key, $value, $ttl]];

        return $this;
    }

    public function exec(): array
    {
        $results = [];
        foreach ($this->commands as $command) {
            $method = $command['method'];
            $args = $command['args'];
            $results[] = $this->client->$method(...$args);
        }

        $this->commands = [];

        return $results;
    }
}
