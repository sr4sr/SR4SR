<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Roblox;

use ManorLedger\Roblox\AnalyticsClient;
use ManorLedger\Roblox\MetricCatalog;
use ManorLedger\Roblox\RateBudget;
use ManorLedger\Roblox\Refresher;
use ManorLedger\Storage\History;
use ManorLedger\Storage\JsonStore;
use ManorLedger\Storage\Snapshots;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RefresherTest extends TestCase
{
    /** 2026-09-16 05:02:11 UTC */
    private const NOW = 1789534931;

    private string $dir;
    private int $now = self::NOW;
    /** @var list<array<string, mixed>> */
    private array $sent = [];
    /** @var array<string, array<string, mixed>> fixture body (or response) per metric id */
    private array $responsesByMetric = [];
    /** @var list<string> */
    private array $log = [];
    private ?string $keySeenByFactory = null;
    /** @var list<array<string, mixed>> catalog entries added to A, B, W */
    private array $extraMetrics = [];
    /** @var ?array<string, mixed> response to every dimension-values request (default: the fixture) */
    private ?array $listing = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/manor-refresh-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/cache', 0700, true);
        file_put_contents($this->dir . '/api-key', "test-key\n");
        chmod($this->dir . '/api-key', 0600);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $path) {
            $path->isDir() ? rmdir((string)$path) : unlink((string)$path);
        }
        rmdir($this->dir);
    }

    private static function fixture(string $name): string
    {
        return (string)file_get_contents(dirname(__DIR__) . '/fixtures/roblox/' . $name);
    }

    private function catalog(): MetricCatalog
    {
        $base = ['granularity' => 'OneDay', 'days' => 30, 'format' => 'int', 'dimensions' => ['Platform', 'Country']];
        return new MetricCatalog(
            ['universeId' => '42', 'metrics' => [
                ['id' => 'A', 'breakdown' => ['Platform']] + $base,
                ['id' => 'B'] + $base,
                ['id' => 'W', 'granularity' => 'OneWeek'] + $base,
                ...$this->extraMetrics,
            ]],
            ['pairs' => [['metric' => 'A', 'dimension' => 'Platform', 'name' => 'A per Platform']]],
        );
    }

    private function refresher(): Refresher
    {
        $transport = function (array $requests): array {
            $out = [];
            foreach ($requests as $request) {
                $this->sent[] = $request;
                $body = json_decode((string)$request['body'], true) ?? [];
                if (str_ends_with((string)$request['url'], '/dimension-values')) {
                    $out[] = $this->listing
                        ?? ['status' => 200, 'body' => self::fixture('dimension-values.json'), 'headers' => [], 'error' => ''];
                    continue;
                }
                $metric = $body['metric'] ?? '';
                // A part of a split metric answers to "Metric#Value" first.
                $value = $body['filter'][0]['values'][0] ?? null;
                $out[] = ($value !== null ? ($this->responsesByMetric[$metric . '#' . $value] ?? null) : null)
                    ?? $this->responsesByMetric[$metric]
                    ?? ['status' => 200, 'body' => self::fixture('completed-aggregate.json'), 'headers' => [], 'error' => ''];
            }
            return $out;
        };
        $now   = fn (): float => (float)$this->now;
        $sleep = function (float $secs): void { $this->now += (int)ceil($secs); };
        $budget = new RateBudget($this->dir . '/cache/.budget.json', true, 18, 60, 6, $now, $sleep);
        $factory = function (string $apiKey) use ($budget, $transport, $now, $sleep): AnalyticsClient {
            $this->keySeenByFactory = $apiKey;
            return new AnalyticsClient('https://api.test/', 42, $apiKey, $budget, ['concurrency' => 2], $transport, $now, $sleep);
        };
        return new Refresher(
            ['paths' => ['apiKey' => $this->dir . '/api-key']],
            $this->catalog(),
            $factory,
            new JsonStore($this->dir . '/cache/metrics.json'),
            new JsonStore($this->dir . '/cache/dimensions.json'),
            new History($this->dir . '/history.json', $this->catalog()->unitOf(...)),
            new Snapshots($this->dir . '/snapshots'),
            function (string $line): void { $this->log[] = $line; },
            fn (): int => $this->now,
        );
    }

    /** @return array<string, mixed> */
    private function readJson(string $relative): array
    {
        return json_decode((string)file_get_contents($this->dir . '/' . $relative), true);
    }

    public function testFullRunWritesCachesHistoryAndSnapshot(): void
    {
        $summary = $this->refresher()->run();

        self::assertFalse($summary['skipped']);
        self::assertSame(['ok' => 2], $summary['metrics'], 'the weekly metric is not fetched');
        self::assertSame(['ok' => 1], $summary['dimensions']);
        self::assertSame(3, $summary['requests']);
        self::assertSame(0, $summary['failed']);
        self::assertSame(6, $summary['pointsMerged']);
        self::assertSame('2026-09-16', $summary['snapshot']);
        self::assertSame('test-key', $this->keySeenByFactory, 'key is trimmed');

        $metrics = $this->readJson('cache/metrics.json');
        self::assertSame(self::NOW, $metrics['fetchedAt']);
        self::assertSame(['A', 'B'], array_keys($metrics['results']));
        self::assertNull($metrics['results']['A']['breakdown'], 'Platform is a user dimension: aggregate only');
        self::assertArrayNotHasKey('breakdown', json_decode($this->sent[0]['body'], true));

        $dims = $this->readJson('cache/dimensions.json');
        self::assertSame(['A|Platform'], array_keys($dims['results']));
        self::assertSame('Platform', $dims['results']['A|Platform']['breakdown']);

        self::assertFileExists($this->dir . '/history.json');
        $history = $this->readJson('history.json');
        self::assertSame('int', $history['metrics']['A|Platform']['unit'], 'unit resolved through the catalog');
        self::assertSame(120411.0, $history['metrics']['A']['series']['']['2026-09-13']);
        self::assertFileExists($this->dir . '/snapshots/2026-09-16.json.gz');
        $snapshot = json_decode((string)gzdecode((string)file_get_contents($this->dir . '/snapshots/2026-09-16.json.gz')), true);
        self::assertSame(['A', 'B'], array_keys($snapshot['results']));

        self::assertNotEmpty($this->log);
        foreach ($this->log as $line) {
            self::assertMatchesRegularExpression('/^\[2026-09-16 \d\d:\d\d:\d\dZ\] /', $line);
        }
        self::assertStringContainsString('Done in', end($this->log));
    }

    public function testExistingRowsSurviveAMerge(): void
    {
        (new JsonStore($this->dir . '/cache/metrics.json'))->write([
            'fetchedAt' => self::NOW - 90000,
            'results'   => ['Legacy' => ['status' => 'ok', 'series' => []]],
        ]);
        $this->refresher()->run(null, ['A']);
        $metrics = $this->readJson('cache/metrics.json');
        self::assertSame(['Legacy', 'A'], array_keys($metrics['results']));
        self::assertSame(self::NOW, $metrics['fetchedAt']);
    }

    public function testIfOlderThanSkipsAFreshCache(): void
    {
        (new JsonStore($this->dir . '/cache/metrics.json'))->write(['fetchedAt' => self::NOW - 3 * 3600, 'results' => []]);
        $summary = $this->refresher()->run(8.0);
        self::assertTrue($summary['skipped']);
        self::assertStringContainsString('3.0 h old', (string)$summary['reason']);
        self::assertSame([], $this->sent);
        self::assertNull($this->keySeenByFactory, 'the key is not even read');
    }

    public function testIfOlderThanRunsWhenCacheIsStaleOrMissing(): void
    {
        (new JsonStore($this->dir . '/cache/metrics.json'))->write(['fetchedAt' => self::NOW - 9 * 3600, 'results' => []]);
        self::assertFalse($this->refresher()->run(8.0)['skipped']);
        self::assertCount(3, $this->sent);

        unlink($this->dir . '/cache/metrics.json');
        $this->sent = [];
        self::assertFalse($this->refresher()->run(8.0)['skipped'], 'no cache at all: always refresh');
        self::assertCount(3, $this->sent);
    }

    public function testOnlyFiltersMetricsAndPairKeys(): void
    {
        $summary = $this->refresher()->run(null, ['B', 'A|Platform']);
        self::assertSame(['ok' => 1], $summary['metrics']);
        self::assertSame(['ok' => 1], $summary['dimensions']);
        self::assertSame(['B', 'A'], array_map(static fn (array $r): string => json_decode($r['body'], true)['metric'], $this->sent));
        self::assertSame(['B'], array_keys($this->readJson('cache/metrics.json')['results']));
    }

    public function testOnlyMatchingNothingIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nothing to fetch');
        $this->refresher()->run(null, ['NoSuchMetric']);
    }

    public function testDryRunSendsNothingAndWritesNothing(): void
    {
        $summary = $this->refresher()->run(null, [], true);
        self::assertSame('dry run', $summary['reason']);
        self::assertSame([], $this->sent);
        self::assertFileDoesNotExist($this->dir . '/cache/metrics.json');
        self::assertFileDoesNotExist($this->dir . '/history.json');
        self::assertStringContainsString('Plan: 2 daily metrics + 1 dimension pairs', implode("\n", $this->log));
    }

    public function testWorldReadableKeyIsRefused(): void
    {
        chmod($this->dir . '/api-key', 0644);
        try {
            $this->refresher()->run();
            self::fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('world-accessible', $e->getMessage());
            self::assertStringContainsString('644', $e->getMessage());
        }
        self::assertSame([], $this->sent);
        self::assertFileDoesNotExist($this->dir . '/cache/metrics.json');
    }

    public function testMissingOrEmptyKeyIsRefused(): void
    {
        file_put_contents($this->dir . '/api-key', "  \n");
        try {
            $this->refresher()->run();
            self::fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('empty', $e->getMessage());
        }
        unlink($this->dir . '/api-key');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->refresher()->run();
    }

    public function testFailedRowsCountAndSuppressTheSnapshot(): void
    {
        $this->responsesByMetric['B'] = ['status' => 403, 'body' => self::fixture('generic-error.json'), 'headers' => [], 'error' => ''];
        $summary = $this->refresher()->run();
        self::assertSame(['error' => 1, 'ok' => 1], $summary['metrics']);
        self::assertSame(1, $summary['failed']);
        self::assertNull($summary['snapshot']);
        self::assertDirectoryDoesNotExist($this->dir . '/snapshots');
        self::assertSame('error', $this->readJson('cache/metrics.json')['results']['B']['status']);
        self::assertFileExists($this->dir . '/history.json', 'history is still saved for the rows that succeeded');
        self::assertStringContainsString('Snapshot skipped: 1 row(s) failed', implode("\n", $this->log));
    }

    private function withFunnelMetrics(): void
    {
        $funnel = ['granularity' => 'OneDay', 'days' => 30, 'dimensions' => ['FunnelName', 'FunnelStep', 'Platform'], 'breakdown' => ['FunnelName']];
        $this->extraMetrics = [
            ['id' => 'F', 'format' => 'pct'] + $funnel,
            ['id' => 'G', 'format' => 'int'] + $funnel,
        ];
    }

    /** @return list<array<string, mixed>> the decoded bodies of the requests sent to $suffix */
    private function bodiesSentTo(string $suffix): array
    {
        $out = [];
        foreach ($this->sent as $request) {
            if (str_ends_with((string)$request['url'], $suffix)) {
                $out[] = json_decode((string)$request['body'], true);
            }
        }
        return $out;
    }

    public function testFunnelMetricsAreFetchedOncePerFunnelAndLabelledWithIt(): void
    {
        $this->withFunnelMetrics();
        $summary = $this->refresher()->run();

        self::assertSame(['ok' => 4], $summary['metrics']);
        self::assertSame(0, $summary['failed']);
        self::assertSame('2026-09-16', $summary['snapshot']);
        self::assertCount(1, $this->bodiesSentTo('/dimension-values'), 'one listing serves every funnel metric');
        self::assertSame(2 + 1 + 2 * 3 + 1, $summary['requests'], 'A, B, the listing, F and G per funnel, the pair');

        $parts = array_values(array_filter($this->bodiesSentTo('/metrics'), static fn (array $b): bool => $b['metric'] === 'F'));
        self::assertSame(['FUNNEL_TYPE_ONBOARDING', 'Partita', 'Shop'], array_map(static fn (array $b) => $b['filter'][0]['values'][0], $parts));
        foreach ($parts as $body) {
            self::assertSame('FunnelName', $body['filter'][0]['dimension']);
            self::assertSame('IN', $body['filter'][0]['operation']);
            self::assertArrayNotHasKey('breakdown', $body, 'FunnelName is never sent as a breakdown');
        }

        $row = $this->readJson('cache/metrics.json')['results']['F'];
        self::assertSame('ok', $row['status']);
        self::assertSame('F', $row['key']);
        self::assertSame('FunnelName', $row['splitBy']);
        self::assertSame(['FUNNEL_TYPE_ONBOARDING', 'Partita', 'Shop'], array_column($row['series'], 'label'));
        self::assertArrayNotHasKey('F#Partita', $this->readJson('cache/metrics.json')['results'], 'parts never reach the cache');

        $history = $this->readJson('history.json')['metrics']['G'];
        self::assertSame(['FUNNEL_TYPE_ONBOARDING', 'Partita', 'Shop'], array_keys($history['series']));
        self::assertSame(120411.0, $history['series']['Shop']['2026-09-13']);
    }

    public function testNoFunnelLoggedLeavesTheRowsEmptyAndTheDayArchived(): void
    {
        $this->withFunnelMetrics();
        $this->listing = ['status' => 200, 'body' => json_encode(['done' => true, 'response' => ['values' => []]]), 'headers' => [], 'error' => ''];
        $summary = $this->refresher()->run();

        self::assertSame(['empty' => 2, 'ok' => 2], $summary['metrics']);
        self::assertSame('2026-09-16', $summary['snapshot']);
        self::assertSame(['A', 'B', 'A'], array_column($this->bodiesSentTo('/metrics'), 'metric'), 'A, B and the pair: no funnel part');
        self::assertStringContainsString('No FunnelName', $this->readJson('cache/metrics.json')['results']['F']['message']);
    }

    public function testAFailedFunnelFailsItsRowAndSkipsTheSnapshot(): void
    {
        $this->withFunnelMetrics();
        $this->responsesByMetric['F#Shop'] = ['status' => 403, 'body' => self::fixture('generic-error.json'), 'headers' => [], 'error' => ''];
        $summary = $this->refresher()->run();

        self::assertSame(['error' => 1, 'ok' => 3], $summary['metrics']);
        self::assertNull($summary['snapshot']);
        $row = $this->readJson('cache/metrics.json')['results']['F'];
        self::assertSame('error', $row['status']);
        self::assertStringStartsWith('Shop: Insufficient permissions', $row['message']);
        self::assertSame([], $row['series'], 'a half-fetched funnel metric keeps nothing');
        self::assertArrayNotHasKey('F', $this->readJson('history.json')['metrics']);
    }

    public function testAFailedListingFailsEveryFunnelMetric(): void
    {
        $this->withFunnelMetrics();
        $this->listing = ['status' => 403, 'body' => self::fixture('generic-error.json'), 'headers' => [], 'error' => ''];
        $summary = $this->refresher()->run();

        self::assertSame(['error' => 2, 'ok' => 2], $summary['metrics']);
        self::assertSame(2, $summary['failed']);
        self::assertStringContainsString('Listing the FunnelName values failed', $this->readJson('cache/metrics.json')['results']['G']['message']);
    }
}
