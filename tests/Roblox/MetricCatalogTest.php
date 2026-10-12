<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Roblox;

use InvalidArgumentException;
use ManorLedger\Roblox\MetricCatalog;
use PHPUnit\Framework\TestCase;

final class MetricCatalogTest extends TestCase
{
    private static function realCatalog(): MetricCatalog
    {
        $root = dirname(__DIR__, 2);
        return MetricCatalog::load($root . '/config/metrics.json', $root . '/config/dimensions.json');
    }

    public function testLoadsTheFullCatalog(): void
    {
        $catalog = self::realCatalog();
        self::assertCount(111, $catalog->metrics());
        self::assertSame(10674300622, $catalog->universeId());
        self::assertNull($catalog->byId('NoSuchMetric'));
        self::assertSame('DailyRevenue', $catalog->byId('DailyRevenue')['id']);
    }

    public function testDailyMetricsExcludeWeeklyAndUnbucketedGranularities(): void
    {
        $daily = self::realCatalog()->dailyMetrics();
        $ids   = array_column($daily, 'id');
        self::assertCount(102, $daily);
        self::assertContains('DailyActiveUsers', $ids);
        self::assertNotContains('WeeklyCohortRetention', $ids, 'OneWeek granularity');
        self::assertNotContains('FunnelStepChurnRate', $ids, 'granularity None');
    }

    public function testUserDimensionBreakdownsAreDroppedButIntrinsicOnesKept(): void
    {
        $catalog = self::realCatalog();
        // Summing per-platform uniques overstates DAU: fetched as an aggregate.
        foreach (['DailyActiveUsers', 'DailyRevenue', 'Visits', 'AverageSessionLengthMinutes', 'ForwardD1Retention'] as $id) {
            self::assertNull($catalog->byId($id)['breakdown'], "$id must be requested without breakdown");
        }
        self::assertSame(['DataStoreStatus'], $catalog->byId('DataStoreRequestsByStatus')['breakdown']);
        self::assertSame(['MemoryUsageCategory'], $catalog->byId('MemoryUsageAvg')['breakdown']);
        self::assertSame(['ThumbnailAsset'], $catalog->byId('ThumbnailImpressions')['breakdown']);
        self::assertSame(['SessionTimeBucket'], $catalog->byId('TotalSessionsEndedInBucket')['breakdown']);
        self::assertSame(['CohortDay'], $catalog->byId('DailyCohortRetention')['breakdown']);
        self::assertNull($catalog->byId('TotalPlayTimeHours')['breakdown']);
    }

    public function testFunnelMetricsAreSplitPerFunnelNameNeverBrokenDownByIt(): void
    {
        $catalog = self::realCatalog();
        foreach (['FunnelCohortCompletionRate', 'FunnelCohortSessionCompletionRate', 'FunnelCohortStep1Users'] as $id) {
            self::assertSame('FunnelName', $catalog->byId($id)['splitBy'], "$id needs a FunnelName filter");
            self::assertNull($catalog->byId($id)['breakdown'], 'FunnelName is filter-only: never a breakdown');
        }
        self::assertSame('FunnelName', $catalog->byId('FunnelUserTotalCount')['splitBy']);
        self::assertSame(['FunnelStep'], $catalog->byId('FunnelUserTotalCount')['breakdown'], 'the step breakdown stays');
        self::assertNull($catalog->byId('DailyActiveUsers')['splitBy']);
    }

    public function testPlatformSplitsComeFromDimensionPairs(): void
    {
        $pairs = self::realCatalog()->dimensionPairs();
        self::assertCount(71, $pairs);
        foreach (['DailyActiveUsers|Platform', 'DailyRevenue|Platform', 'Visits|Platform'] as $key) {
            self::assertArrayHasKey($key, $pairs);
            self::assertSame(['Platform'], $pairs[$key]['breakdown']);
            self::assertSame($key, $pairs[$key]['key']);
        }
        self::assertSame('DailyActiveUsers', $pairs['DailyActiveUsers|Platform']['id']);
        self::assertSame('OneDay', $pairs['DailyActiveUsers|Platform']['granularity']);
        self::assertSame('Ricavi per Platform', $pairs['DailyRevenue|Platform']['name']);
    }

    public function testUnitOfAcceptsMetricIdsAndPairKeys(): void
    {
        $catalog = self::realCatalog();
        self::assertSame('robux', $catalog->unitOf('DailyRevenue'));
        self::assertSame('robux', $catalog->unitOf('DailyRevenue|Platform'));
        self::assertSame('pct', $catalog->unitOf('ForwardD1Retention|Country'));
        self::assertSame('bytes', $catalog->unitOf('ClientMemoryUsageAvg'));
        self::assertNull($catalog->unitOf('Unknown|Platform'));
    }

    public function testPairsWithUndeclaredDimensionOrUnknownMetricAreDropped(): void
    {
        $metrics = ['universeId' => '1', 'metrics' => [
            ['id' => 'A', 'granularity' => 'OneDay', 'days' => 30, 'format' => 'int',
             'dimensions' => ['Country'], 'breakdown' => ['Platform']],
        ]];
        $dims = ['pairs' => [
            ['metric' => 'A', 'dimension' => 'Country', 'name' => 'A by country'],
            ['metric' => 'A', 'dimension' => 'Platform'],   // not declared for A
            ['metric' => 'B', 'dimension' => 'Country'],    // unknown metric
            'garbage',
        ]];
        $catalog = new MetricCatalog($metrics, $dims);
        self::assertSame(['A|Country'], array_keys($catalog->dimensionPairs()));
        self::assertSame('A by country', $catalog->dimensionPairs()['A|Country']['name']);
        self::assertNull($catalog->byId('A')['breakdown'], 'Platform is a user dimension');
    }

    public function testRejectsCatalogWithoutMetricsList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MetricCatalog(['universeId' => '1'], ['pairs' => []]);
    }
}
