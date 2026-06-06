<?php

declare(strict_types=1);

namespace Switon\Caching\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\SimpleCache\CacheInterface;
use Switon\Caching\Command\CacheCommand;
use Switon\Caching\Tests\TestCase;
use Switon\Core\ConsoleInterface;
use Switon\Redis\ClientInterface;
use Switon\Redis\Exception\CallInPoolException;
use RuntimeException;
use Throwable;

#[AllowMockObjectsWithoutExpectations]
class CacheCommandTest extends TestCase
{
    protected CacheCommand $command;
    protected ConsoleInterface&MockObject $console;
    protected CacheInterface&MockObject $cache;
    protected FakeRedisClient $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->console = $this->createMock(ConsoleInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->redis = new FakeRedisClient();
        $this->command = $this->makeCommand(['prefix' => 'cache:']);
    }

    public function testListActionReturnsErrorWhenPrefixIsEmpty(): void
    {
        $command = $this->makeCommand(['prefix' => '']);
        $this->console->expects($this->once())
            ->method('error')
            ->with('Cache prefix must be configured to list cache keys.')
            ->willReturn(1);

        $code = $command->listAction();

        $this->assertSame(1, $code);
    }

    public function testListActionPrintsNoKeysWhenScanHasNoMatches(): void
    {
        $this->redis->batches = [
            [0, []],
        ];

        $this->console->expects($this->exactly(3))
            ->method('writeLn')
            ->with(
                $this->logicalOr(
                    $this->equalTo('Cache prefix: cache:'),
                    $this->equalTo(''),
                    $this->equalTo('No cache keys found.')
                )
            );

        $code = $this->command->listAction();

        $this->assertSame(0, $code);
    }

    public function testListActionShowsSortedKeysWithoutTtl(): void
    {
        $this->redis->batches = [
            [1, ['cache:z-key', 'cache:a-key', 'other:x']],
            [0, []],
        ];

        $lines = [];
        $this->console->expects($this->atLeast(1))
            ->method('writeLn')
            ->willReturnCallback(static function (mixed $line = '') use (&$lines): void {
                $lines[] = $line;
            });

        $code = $this->command->listAction(false);

        $this->assertSame(0, $code);
        $this->assertContains('  a-key', $lines);
        $this->assertContains('  z-key', $lines);
        $this->assertNotContains('  other:x', $lines);
        $idxA = array_search('  a-key', $lines, true);
        $idxZ = array_search('  z-key', $lines, true);
        $this->assertNotFalse($idxA);
        $this->assertNotFalse($idxZ);
        $this->assertLessThan($idxZ, $idxA);
    }

    public function testListActionShowsSortedKeysAndTtlTable(): void
    {
        $this->redis->batches = [
            [1, ['cache:z-key', 'cache:a-key', 'other:x']],
            [0, ['cache:b-key']],
        ];
        $this->redis->ttlMap = [
            'cache:a-key' => -1,
            'cache:b-key' => -2,
            'cache:z-key' => 42,
        ];

        $capturedRows = null;
        $this->console->expects($this->once())
            ->method('table')
            ->with(
                ['KEY', 'TTL'],
                $this->callback(static function (array $rows) use (&$capturedRows): bool {
                    $capturedRows = $rows;
                    return true;
                }),
                8
            );
        $this->console->expects($this->atLeast(1))->method('writeLn');

        $code = $this->command->listAction(true);

        $this->assertSame(0, $code);
        $this->assertSame(
            [
                ['a-key', 'permanent'],
                ['b-key', 'expired'],
                ['z-key', '42s'],
            ],
            $capturedRows
        );
    }

    public function testListActionReturnsFriendlyErrorWhenPoolIsNotConfigured(): void
    {
        $this->redis = new class () extends FakeRedisClient {
            public function getTransient(): static
            {
                throw CallInPoolException::of('Method "scan" cannot be called in connection pool');
            }
        };
        $command = $this->makeCommand();

        $this->console->expects($this->once())
            ->method('error')
            ->with('Redis connection not configured for cache. Set Redis client URI to use cache:list.')
            ->willReturn(1);

        $code = $command->listAction();

        $this->assertSame(1, $code);
    }

    public function testListActionReturnsFriendlyErrorWhenCallAddFirst(): void
    {
        $this->redis = new class () extends FakeRedisClient {
            public function getTransient(): static
            {
                throw CallInPoolException::of('Method "pipeline" cannot be called in connection pool');
            }
        };
        $command = $this->makeCommand();

        $this->console->expects($this->once())
            ->method('error')
            ->with('Redis connection not configured for cache. Set Redis client URI to use cache:list.')
            ->willReturn(1);

        $code = $command->listAction();

        $this->assertSame(1, $code);
    }

    public function testListActionContinuesAfterIntermediateEmptyBatch(): void
    {
        $this->redis->batches = [
            [1, []],
            [0, ['cache:late-key']],
        ];

        $this->console->expects($this->atLeast(1))->method('writeLn');
        $this->console->expects($this->once())
            ->method('table')
            ->with(
                ['KEY', 'TTL'],
                [['late-key', 'expired']],
                8
            );

        $code = $this->command->listAction(true);

        $this->assertSame(0, $code);
    }

    public function testListActionReturnsGenericErrorForUnexpectedFailure(): void
    {
        $this->redis->throwOnTransient = new RuntimeException('network timeout');

        $this->console->expects($this->once())
            ->method('error')
            ->with('Failed to list cache keys: {message}', ['message' => 'network timeout'])
            ->willReturn(1);

        $code = $this->command->listAction();

        $this->assertSame(1, $code);
    }

    public function testClearActionSuccessAndFailurePaths(): void
    {
        $this->cache->expects($this->exactly(2))
            ->method('clear')
            ->willReturnCallback(static function (): bool {
                static $count = 0;
                $count++;
                if ($count === 2) {
                    throw new RuntimeException('boom');
                }
                return true;
            });

        $this->console->expects($this->once())
            ->method('writeLn')
            ->with('Cache cleared successfully.');
        $this->console->expects($this->once())
            ->method('error')
            ->with('Failed to clear cache: {message}', ['message' => 'boom'])
            ->willReturn(1);

        $ok = $this->command->clearAction();
        $fail = $this->command->clearAction();

        $this->assertSame(0, $ok);
        $this->assertSame(1, $fail);
    }

    private function makeCommand(array $overrides = []): CacheCommand
    {
        $this->container->replace(ConsoleInterface::class, $this->console);
        $this->container->replace(CacheInterface::class, $this->cache);
        $this->container->replace(ClientInterface::class, $this->redis);

        return $this->container->make(CacheCommand::class, $overrides);
    }
}

class FakeRedisClient implements ClientInterface
{
    /** @var array<string, int> */
    public array $ttlMap = [];

    /** @var list<array{0: int|null, 1: array<int, string>}> */
    public array $batches = [];
    public ?Throwable $throwOnTransient = null;

    public function getUri(): ?string
    {
        return null;
    }

    public function getTransient(): static
    {
        if ($this->throwOnTransient !== null) {
            throw $this->throwOnTransient;
        }
        return $this;
    }

    public function ttl(string $key): int
    {
        return $this->ttlMap[$key] ?? -2;
    }
    protected int $cursor = 0;

    /**
     * @return array<int, string>
     */
    public function scan(mixed &$iterator, string $pattern, int $count): array
    {
        if (!isset($this->batches[$this->cursor])) {
            $iterator = 0;
            return [];
        }

        [$nextIterator, $batch] = $this->batches[$this->cursor];
        $this->cursor++;
        $iterator = $nextIterator;

        return $batch;
    }
}
