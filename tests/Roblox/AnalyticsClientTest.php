<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Roblox;

use ManorLedger\Roblox\AnalyticsClient;
use ManorLedger\Roblox\RateBudget;
use PHPUnit\Framework\TestCase;

final class AnalyticsClientTest extends TestCase
{
    private const BASE = 'https://apis.roblox.com/analytics-query-api/';
    private const KEY  = 'test-key-not-secret';
    /** 2026-09-15 12:34:56 UTC */
    private const T0 = 1789475696.0;

    private string $dir;
    private float $clock = self::T0;
    /** @var list<float> */
    private array $sleeps = [];
    /** @var list<array<string, mixed>> */
    private array $sent = [];
    /** @var list<array<string, mixed>> */
    private array $script = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/manor-client-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    private static function fixture(string $name): string
    {
        return (string)file_get_contents(dirname(__DIR__) . '/fixtures/roblox/' . $name);
    }

    /** @return array<string, mixed> */
    private static function response(int $status, string|false $body, array $headers = []): array
    {
        return ['status' => $status, 'body' => $body, 'headers' => $headers, 'error' => $body === false ? 'Connection timed out' : ''];
    }

    private function budget(bool $allowSleep, int $reserve = 6): RateBudget
    {
        return new RateBudget(
            $this->dir . '/.budget.json',
            $allowSleep,
            18,
            60,
            $reserve,
            fn (): float => $this->clock,
            function (float $secs): void { $this->sleeps[] = $secs; $this->clock += $secs; },
        );
    }

    private function client(RateBudget $budget, array $options = []): AnalyticsClient
    {
        $transport = function (array $requests): array {
            $responses = [];
            foreach ($requests as $request) {
                $this->sent[] = $request;
                $responses[] = array_shift($this->script) ?? self::response(0, false);
            }
            return $responses;
        };
        return new AnalyticsClient(
            self::BASE,
            10674300622,
            self::KEY,
            $budget,
            $options + ['concurrency' => 1, 'maxTries' => 3, 'maxPolls' => 8, 'pollWait' => 1.5, 'deadline' => 600],
            $transport,
            fn (): float => $this->clock,
            function (float $secs): void { $this->sleeps[] = $secs; $this->clock += $secs; },
        );
    }

    /** @return array<string, mixed> */
    private static function metric(string $id, ?array $breakdown = null, int $days = 30, string $granularity = 'OneDay'): array
    {
        return ['id' => $id, 'granularity' => $granularity, 'days' => $days, 'breakdown' => $breakdown];
    }

    public function testCompletedResponseBecomesSortedSeriesInCacheShape(): void
    {
        $this->script = [self::response(200, self::fixture('completed.json'))];
        $rows = $this->client($this->budget(false))->fetch([self::metric('DailyActiveUsers', ['Platform'])]);

        $row = $rows['DailyActiveUsers'];
        self::assertSame('ok', $row['status']);
        self::assertSame('Platform', $row['breakdown']);
        self::assertSame('OneDay', $row['granularity']);
        self::assertSame(30, $row['days']);
        self::assertSame('2026-08-16T00:00:00Z', $row['startTime'], 'window aligned to the UTC day');
        self::assertSame('2026-09-15T00:00:00Z', $row['endTime']);
        self::assertArrayNotHasKey('message', $row);

        self::assertSame(['Phone', 'Computer'], array_column($row['series'], 'label'), 'largest total first, all-null series dropped');
        self::assertSame(408.0, $row['series'][0]['total']);
        self::assertSame([['t' => '2026-09-13T00:00:00+00:00', 'v' => 37.0], ['t' => '2026-09-14T00:00:00+00:00', 'v' => 41.0]],
            $row['series'][1]['points'], 'null data points are skipped');

        $request = $this->sent[0];
        self::assertSame('POST', $request['method']);
        self::assertSame(self::BASE . 'v1/universes/10674300622/metrics', $request['url']);
        self::assertSame(self::KEY, $request['headers']['x-api-key']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertSame([
            'metric' => 'DailyActiveUsers', 'granularity' => 'OneDay',
            'startTime' => '2026-08-16T00:00:00Z', 'endTime' => '2026-09-15T00:00:00Z', 'breakdown' => ['Platform'],
        ], json_decode($request['body'], true));
        self::assertStringNotContainsString(self::KEY, json_encode($rows), 'the key never leaks into cache rows');
    }

    public function testAggregateMetricHasNoBreakdownAndEmptyLabel(): void
    {
        $this->script = [self::response(200, self::fixture('completed-aggregate.json'))];
        $rows = $this->client($this->budget(false))->fetch([self::metric('ItemMonetizationRevenue')]);
        self::assertArrayNotHasKey('breakdown', json_decode($this->sent[0]['body'], true));
        self::assertNull($rows['ItemMonetizationRevenue']['breakdown']);
        self::assertSame('', $rows['ItemMonetizationRevenue']['series'][0]['label']);
        self::assertSame(238413.0, $rows['ItemMonetizationRevenue']['series'][0]['total']);
    }

    public function testEmptyResponse(): void
    {
        $this->script = [self::response(200, self::fixture('empty.json'))];
        $rows = $this->client($this->budget(false))->fetch([self::metric('ForwardD30Retention')]);
        self::assertSame('empty', $rows['ForwardD30Retention']['status']);
        self::assertSame([], $rows['ForwardD30Retention']['series']);
        self::assertNotEmpty($rows['ForwardD30Retention']['message']);
    }

    public function testDimensionPairKeyIsUsedForTheRow(): void
    {
        $this->script = [self::response(200, self::fixture('completed.json'))];
        $pair = self::metric('DailyActiveUsers', ['Platform']) + ['key' => 'DailyActiveUsers|Platform'];
        $rows = $this->client($this->budget(false))->fetch([$pair]);
        self::assertSame(['DailyActiveUsers|Platform'], array_keys($rows));
        self::assertSame('DailyActiveUsers', $rows['DailyActiveUsers|Platform']['id']);
    }

    public function testLongRunningOperationIsPolledOnValidatedPath(): void
    {
        $this->script = [
            self::response(202, self::fixture('lro-pending.json')),
            self::response(200, self::fixture('completed.json')),
        ];
        $client = $this->client($this->budget(false));
        $rows = $client->fetch([self::metric('DailyActiveUsers', ['Platform'])]);

        self::assertSame('ok', $rows['DailyActiveUsers']['status']);
        self::assertSame(2, $client->requestsSent());
        self::assertSame('GET', $this->sent[1]['method']);
        self::assertSame(self::BASE . 'v1/universes/10674300622/operations/9f2b1c4e-7a3d-4b5f-8c1e-lro_pending', $this->sent[1]['url']);
        self::assertSame(self::KEY, $this->sent[1]['headers']['x-api-key']);
        self::assertNull($this->sent[1]['body']);
        self::assertGreaterThanOrEqual(1.5, array_sum($this->sleeps), 'waits pollWait before polling');
    }

    public function testUnexpectedOperationPathIsRefused(): void
    {
        $this->script = [self::response(202, self::fixture('lro-bad-path.json'))];
        $client = $this->client($this->budget(false));
        $rows = $client->fetch([self::metric('DailyActiveUsers')]);
        self::assertSame('error', $rows['DailyActiveUsers']['status']);
        self::assertStringContainsString('polling path', $rows['DailyActiveUsers']['message']);
        self::assertSame(1, $client->requestsSent(), 'the hostile path is never requested');
    }

    public function testTooManyPollsEndsInError(): void
    {
        $pending = self::response(202, self::fixture('lro-pending.json'));
        $this->script = [$pending, $pending, $pending, $pending];
        $rows = $this->client($this->budget(false), ['maxPolls' => 2])->fetch([self::metric('DailyActiveUsers')]);
        self::assertSame('error', $rows['DailyActiveUsers']['status']);
        self::assertStringContainsString('still running', $rows['DailyActiveUsers']['message']);
        self::assertCount(3, $this->sent, 'post + 2 polls');
    }

    public function testRateLimitInBrowserModeMarksRowsAndBlocksTheSharedBudget(): void
    {
        $this->script = [self::response(429, self::fixture('ratelimited.json'), ['retry-after' => '30'])];
        $client = $this->client($this->budget(false));
        $rows = $client->fetch([self::metric('Visits'), self::metric('DailyRevenue')]);

        self::assertSame('ratelimited', $rows['Visits']['status']);
        self::assertSame('ratelimited', $rows['DailyRevenue']['status'], 'no token left for the second job');
        self::assertSame(1, $client->requestsSent());
        $state = json_decode((string)file_get_contents($this->dir . '/.budget.json'), true);
        self::assertEqualsWithDelta(self::T0 + 30, $state['blockedUntil'], 0.001);
        self::assertSame([], $this->sleeps);
    }

    public function testRateLimitInCliModeWaitsRetryAfterAndRetries(): void
    {
        $this->script = [
            self::response(429, self::fixture('ratelimited.json'), ['Retry-After' => '5']),
            self::response(200, self::fixture('completed-aggregate.json')),
        ];
        $client = $this->client($this->budget(true));
        $rows = $client->fetch([self::metric('Visits')]);
        self::assertSame('ok', $rows['Visits']['status']);
        self::assertSame(2, $client->requestsSent());
        self::assertGreaterThanOrEqual(5.0, $this->clock - self::T0, 'retried only after the pause');
    }

    public function testRangeTooWideShrinksTheWindowOnce(): void
    {
        $this->script = [
            self::response(400, self::fixture('range-error.json')),
            self::response(200, self::fixture('completed-aggregate.json')),
        ];
        $rows = $this->client($this->budget(false))->fetch([self::metric('ClientCrashCount', null, 30)]);
        self::assertSame('ok', $rows['ClientCrashCount']['status']);
        self::assertSame(28, $rows['ClientCrashCount']['days']);
        self::assertSame('2026-08-18T00:00:00Z', $rows['ClientCrashCount']['startTime']);
        $retry = json_decode($this->sent[1]['body'], true);
        self::assertSame('2026-08-18T00:00:00Z', $retry['startTime']);
        self::assertSame('2026-09-15T00:00:00Z', $retry['endTime']);
    }

    public function testRejectedBreakdownFallsBackToAggregate(): void
    {
        $this->script = [
            self::response(400, self::fixture('breakdown-error.json')),
            self::response(200, self::fixture('completed-aggregate.json')),
        ];
        $rows = $this->client($this->budget(false))->fetch([self::metric('ClientCrashCount', ['Platform'])]);
        self::assertSame('ok', $rows['ClientCrashCount']['status']);
        self::assertNull($rows['ClientCrashCount']['breakdown']);
        self::assertArrayHasKey('breakdown', json_decode($this->sent[0]['body'], true));
        self::assertArrayNotHasKey('breakdown', json_decode($this->sent[1]['body'], true));
    }

    public function testApplicationErrorWithoutBreakdownIsFinal(): void
    {
        $this->script = [self::response(403, self::fixture('generic-error.json'))];
        $client = $this->client($this->budget(false));
        $rows = $client->fetch([self::metric('Visits')]);
        self::assertSame('error', $rows['Visits']['status']);
        self::assertSame('Insufficient permissions for this universe.', $rows['Visits']['message']);
        self::assertSame(403, $rows['Visits']['code']);
        self::assertSame(1, $client->requestsSent());
    }

    public function testNetworkFailuresAreRetriedWithBackoffThenFail(): void
    {
        $this->script = [self::response(0, false), self::response(0, false), self::response(0, false)];
        $client = $this->client($this->budget(false));
        $rows = $client->fetch([self::metric('Visits')]);
        self::assertSame('error', $rows['Visits']['status']);
        self::assertSame('Network error: Connection timed out', $rows['Visits']['message']);
        self::assertSame(3, $client->requestsSent());
        self::assertGreaterThanOrEqual(6.0, $this->clock - self::T0, 'backoff 2s then 4s');
    }

    public function testNonJsonAndUnexpectedHttpStatusAreErrors(): void
    {
        $this->script = [self::response(502, '<html>bad gateway</html>'), self::response(500, '{"done":true}')];
        $rows = $this->client($this->budget(false))->fetch([self::metric('Visits'), self::metric('DailyRevenue')]);
        self::assertSame('error', $rows['Visits']['status']);
        self::assertSame('Non-JSON response (HTTP 502)', $rows['Visits']['message']);
        self::assertSame('HTTP 500', $rows['DailyRevenue']['message']);
    }

    public function testRateLimitHeadersFeedTheBudget(): void
    {
        $this->script = [
            self::response(200, self::fixture('completed-aggregate.json'), ['x-ratelimit-remaining' => '3', 'x-ratelimit-reset' => '40']),
            self::response(200, self::fixture('completed-aggregate.json')),
        ];
        $client = $this->client($this->budget(false, 6));
        $rows = $client->fetch([self::metric('Visits'), self::metric('DailyRevenue')]);
        self::assertSame('ok', $rows['Visits']['status']);
        self::assertSame('ratelimited', $rows['DailyRevenue']['status'], 'remaining=3 is below the reserve of 6');
        self::assertSame(1, $client->requestsSent());
    }

    public function testDeadlineFailsEveryPendingJob(): void
    {
        $this->script = [self::response(0, false), self::response(0, false)];
        $rows = $this->client($this->budget(false), ['deadline' => 1])->fetch([self::metric('Visits')]);
        self::assertSame('error', $rows['Visits']['status']);
        self::assertSame('Overall client deadline exceeded', $rows['Visits']['message']);
    }

    public function testHourlyMetricsAlignToTheHour(): void
    {
        $this->script = [self::response(200, self::fixture('completed-aggregate.json'))];
        $rows = $this->client($this->budget(false))->fetch([self::metric('Concurrent', null, 2, 'OneHour')]);
        self::assertSame('2026-09-15T12:00:00Z', $rows['Concurrent']['endTime']);
        self::assertSame('2026-09-13T12:00:00Z', $rows['Concurrent']['startTime']);
    }

    public function testFilterTravelsInThePayload(): void
    {
        $this->script = [self::response(200, self::fixture('completed-aggregate.json'))];
        $filter = [['dimension' => 'FunnelName', 'operation' => 'IN', 'values' => ['Partita']]];
        $part = self::metric('FunnelCohortCompletionRate') + ['key' => 'FunnelCohortCompletionRate#Partita', 'filter' => $filter];
        $rows = $this->client($this->budget(false))->fetch([$part]);

        self::assertSame('ok', $rows['FunnelCohortCompletionRate#Partita']['status']);
        $body = json_decode($this->sent[0]['body'], true);
        self::assertSame($filter, $body['filter']);
        self::assertArrayNotHasKey('breakdown', $body);
        self::assertSame(self::BASE . 'v1/universes/10674300622/metrics', $this->sent[0]['url']);
    }

    public function testMissingRequiredFilterIsAnError(): void
    {
        $this->script = [self::response(400, self::fixture('filter-required-error.json'))];
        $rows = $this->client($this->budget(false))->fetch([self::metric('FunnelCohortCompletionRate')]);
        self::assertSame('error', $rows['FunnelCohortCompletionRate']['status']);
        self::assertStringContainsString('FunnelName is required as a filter', $rows['FunnelCohortCompletionRate']['message']);
    }

    public function testDimensionValuesListsEachValueOnce(): void
    {
        $this->script = [self::response(200, self::fixture('dimension-values.json'))];
        $row = $this->client($this->budget(false))->dimensionValues(self::metric('FunnelCohortCompletionRate', null, 30), 'FunnelName');

        self::assertSame('ok', $row['status']);
        self::assertSame(['FUNNEL_TYPE_ONBOARDING', 'Partita', 'Shop'], $row['values']);
        self::assertSame('2026-08-16T00:00:00Z', $row['startTime'], 'the metric window');
        $request = $this->sent[0];
        self::assertSame(self::BASE . 'v1/universes/10674300622/dimension-values', $request['url']);
        self::assertSame([
            'metric' => 'FunnelCohortCompletionRate', 'dimensions' => ['FunnelName'],
            'startTime' => '2026-08-16T00:00:00Z', 'endTime' => '2026-09-15T00:00:00Z',
        ], json_decode($request['body'], true));
    }

    public function testDimensionValuesIsPolledLikeAMetric(): void
    {
        $this->script = [
            self::response(202, json_encode(['done' => false, 'path' => 'v1/universes/10674300622/operations/dimension-values/abc'])),
            self::response(200, self::fixture('dimension-values.json')),
        ];
        $row = $this->client($this->budget(false))->dimensionValues(self::metric('FunnelCohortStep1Users'), 'FunnelName');
        self::assertSame('ok', $row['status']);
        self::assertSame('GET', $this->sent[1]['method']);
        self::assertSame(self::BASE . 'v1/universes/10674300622/operations/dimension-values/abc', $this->sent[1]['url']);
    }

    public function testDimensionValuesWithNothingLoggedIsEmpty(): void
    {
        $this->script = [self::response(200, json_encode(['done' => true, 'response' => ['values' => [['dimension' => 'FunnelName', 'values' => []]]]]))];
        $row = $this->client($this->budget(false))->dimensionValues(self::metric('FunnelCohortStep1Users'), 'FunnelName');
        self::assertSame('empty', $row['status']);
        self::assertArrayNotHasKey('values', $row);
    }
}
