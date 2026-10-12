<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Analytics;

use ManorLedger\Analytics\DashboardBuilder;
use ManorLedger\Storage\History;
use PHPUnit\Framework\TestCase;

final class DashboardBuilderTest extends TestCase
{
    private const FETCHED_AT = 1788332400; // 2026-09-02 07:00 UTC, the morning after the last revenue day

    private string $path;
    private array $catalogById = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/manor-builder-' . bin2hex(random_bytes(4)) . '/history.json';
        mkdir(dirname($this->path), 0700);
        copy(__DIR__ . '/../fixtures/history-small.json', $this->path);
        foreach (json_decode((string)file_get_contents(__DIR__ . '/../fixtures/catalog-small.json'), true)['metrics'] as $m) {
            $this->catalogById[$m['id']] = $m;
        }
    }

    protected function tearDown(): void
    {
        unlink($this->path);
        rmdir(dirname($this->path));
    }

    private function build(int $fetchedAt = self::FETCHED_AT): array
    {
        $config = [
            'app' => ['game' => 'Fixture Manor', 'universeId' => 1],
            'economics' => ['devexUsdPerRobux' => 0.0038, 'royaltyShare' => 0.17, 'multiples' => ['conservative' => 18, 'base' => 30], 'plateauShares' => [0.06, 0.10, 0.15]],
        ];
        $glossary = [['term' => 'DAU', 'meaning' => 'Utenti attivi giornalieri.']];

        return (new DashboardBuilder($config, $this->catalogById, $glossary))->build(new History($this->path), $fetchedAt, 1788336000);
    }

    public function testTopLevelContract(): void
    {
        $d = $this->build();
        foreach (['generatedAt', 'dataThrough', 'provisionalDate', 'game', 'assumptions', 'kpis', 'metrics', 'dimensions', 'derived', 'weekOverWeek', 'seasonality', 'sessionSurvival', 'anomalies', 'platformValuation', 'glossary'] as $key) {
            self::assertArrayHasKey($key, $d);
        }
        self::assertSame('2026-09-02T08:00:00Z', $d['generatedAt']);
        self::assertSame(['name' => 'Fixture Manor', 'universeId' => 1], $d['game']);
        self::assertSame(0.0038, $d['assumptions']['devexUsdPerRobux']);
        self::assertSame(['conservative' => 18, 'base' => 30], $d['assumptions']['multiples']);
        self::assertSame('DAU', $d['glossary'][0]['term']);
        self::assertSame(['from' => '2026-08-03', 'to' => '2026-09-01', 'days' => 30, 'fetchedAt' => '2026-09-02T07:00:00Z'], $d['coverage']);
        self::assertNull($d['sessionSurvival'], 'the fixture carries no session bucket metric');
    }

    public function testFreshestRevenueDayIsProvisionalWhenFetchedWithinADay(): void
    {
        $d = $this->build();
        self::assertSame('2026-09-01', $d['provisionalDate']);
        self::assertSame('2026-08-31', $d['dataThrough']);

        $later = $this->build(self::FETCHED_AT + 2 * 86400); // fetched 09-04: 09-01 is consolidated by then
        self::assertNull($later['provisionalDate']);
        self::assertSame('2026-09-01', $later['dataThrough']);
    }

    public function testKpisUseConsolidatedWindowOnly(): void
    {
        $k = $this->build()['kpis'];
        // 08-25..08-31: 1022 1023 1024 1025 2026 2027 1028 -> 9175/7; previous week 9126/7
        self::assertSame(1310.71, $k['revenue7dRobux']['value']);
        self::assertEqualsWithDelta(9175 / 9126 - 1, $k['revenue7dRobux']['delta'], 1e-4);
        self::assertEqualsWithDelta(1310.714 * 0.0038 * 0.83 * 30 * 30, $k['valuationBaseUsd']['value'], 0.05);
        self::assertSame(['value' => 0.03, 'date' => '2026-08-31'], $k['d7Retention']);
        self::assertSame(0.1379, $k['d1Retention']['value'], 'D1 on 08-31 weighted by platform DAU: (0.1x328 + 0.2x200)/528');
    }

    public function testLegacyBreakdownIsAggregatedAndFlagged(): void
    {
        $m = $this->build()['metrics'];
        $dau = $m['DailyActiveUsers'];
        self::assertTrue($dau['aggregatedFromBreakdown']);
        self::assertSame('sum', $dau['aggregation']);
        self::assertSame('Utenti attivi giornalieri', $dau['name']);
        self::assertSame('Engagement', $dau['category']);
        self::assertSame('int', $dau['unit']);
        self::assertSame('Platform', $dau['breakdown']);
        self::assertSame('', $dau['series'][0]['label']);
        self::assertSame(500, $dau['series'][0]['values'][0]);
        self::assertCount(29, $dau['dates'], 'full calendar 08-03..08-31');
        self::assertNull($dau['series'][0]['values'][12], '08-15 is missing in the fixture: null, not 0');

        self::assertSame('weightedMean', $m['ForwardD1Retention']['aggregation']);
        self::assertEqualsWithDelta(0.14, $m['ForwardD1Retention']['series'][0]['values'][0], 1e-9);
        self::assertArrayNotHasKey('aggregatedFromBreakdown', $m['DailyCohortRetention'], 'cohort days have no meaningful total');
        self::assertArrayNotHasKey('aggregatedFromBreakdown', $m['ItemMonetizationRevenue']);
        self::assertArrayNotHasKey('DailyActiveUsers|Country', $m, 'pairs live under dimensions');
    }

    public function testFunnelMetricsShowOneLinePerFunnelAndNoTotal(): void
    {
        $history = json_decode((string)file_get_contents($this->path), true);
        $history['metrics']['FunnelCohortStep1Users'] = ['unit' => 'int', 'series' => [
            ''        => ['2026-08-20' => 900, '2026-08-21' => 950],   // the cross-funnel sum Roblox served until 8 Oct
            'Partita' => ['2026-08-20' => 400, '2026-08-21' => 420],
            'Shop'    => ['2026-08-20' => 30, '2026-08-21' => 35],
        ]];
        $history['metrics']['FunnelCohortCompletionRate'] = ['unit' => 'pct', 'series' => ['' => ['2026-08-20' => 0.7]]];
        file_put_contents($this->path, json_encode($history));
        foreach (['FunnelCohortStep1Users' => 'int', 'FunnelCohortCompletionRate' => 'pct'] as $id => $format) {
            $this->catalogById[$id] = ['id' => $id, 'name' => $id, 'category' => 'Funnels', 'format' => $format,
                'dimensions' => ['FunnelName', 'FunnelStep'], 'breakdown' => ['FunnelName']];
        }

        $m = $this->build()['metrics'];
        $step1 = $m['FunnelCohortStep1Users'];
        self::assertSame(['Partita', 'Shop'], array_column($step1['series'], 'label'), 'neither the old sum nor a new one');
        self::assertArrayNotHasKey('aggregatedFromBreakdown', $step1);
        self::assertArrayNotHasKey('FunnelCohortCompletionRate', $m, 'only the retired sum: nothing to show');
    }

    public function testDimensionsSelectPairsAndGroupCountries(): void
    {
        $dims = $this->build()['dimensions'];
        self::assertSame(['DailyActiveUsers|Platform', 'DailyRevenue|Platform', 'DailyActiveUsers|Country'], array_keys($dims));
        $labels = array_column($dims['DailyActiveUsers|Country']['series'], 'label');
        self::assertCount(9, $labels);
        self::assertSame('C1', $labels[0]);
        self::assertSame('Altri', $labels[8]);
        self::assertSame(20 + 10, $dims['DailyActiveUsers|Country']['series'][8]['values'][0], 'C9 + C10 on an even day');
        self::assertSame(['Phone', 'Computer'], array_column($dims['DailyActiveUsers|Platform']['series'], 'label'), 'unknown bucket dropped');
        self::assertSame('Country', $dims['DailyActiveUsers|Country']['dimension']);
    }

    public function testDerivedSeries(): void
    {
        $der = $this->build()['derived'];
        self::assertSame(['revenueUsdNet', 'revenueUsdGross', 'revenue7dAvgRobux', 'revenueCumulativeUsdNet', 'valuationUsd', 'arpdauRobux', 'arppuRobux', 'dauMauStickiness', 'revenuePlatformShare'], array_keys($der));
        self::assertSame('usd', $der['revenueUsdNet']['unit']);
        self::assertSame(round(1000 * 0.0038 * 0.83, 2), $der['revenueUsdNet']['series'][0]['values'][0]);
        self::assertSame(3.8, $der['revenueUsdGross']['series'][0]['values'][0]);
        self::assertSame(['conservative', 'base'], array_column($der['valuationUsd']['series'], 'label'));
        self::assertSame('2026-08-31', end($der['valuationUsd']['dates']), 'valuation stops at the consolidated day');
        self::assertSame('2026-09-01', end($der['revenueUsdNet']['dates']), 'per-day series include the provisional day');
        self::assertSame(2.0, $der['arpdauRobux']['series'][0]['values'][0]);
        self::assertSame(100.0, $der['arppuRobux']['series'][0]['values'][0]);
        self::assertSame(0.1, $der['dauMauStickiness']['series'][0]['values'][0]);
        self::assertSame(['Phone', 'Computer'], array_column($der['revenuePlatformShare']['series'], 'label'));
        self::assertSame(0.6, $der['revenuePlatformShare']['series'][0]['values'][0]);
    }

    public function testWeekOverWeekSeasonalityAnomaliesAndPlateau(): void
    {
        $d = $this->build();
        self::assertCount(8, $d['weekOverWeek']);
        $last = end($d['weekOverWeek']);
        self::assertSame(['date' => '2026-09-01', 'weekday' => 'mar', 'provisional' => true], ['date' => $last['date'], 'weekday' => $last['weekday'], 'provisional' => $last['provisional']]);
        self::assertSame(1029, $last['revenue']);
        self::assertSame(1022, $last['revenuePrev']);
        self::assertNull($last['dau'], 'DAU lags a day behind revenue');
        self::assertFalse($d['weekOverWeek'][6]['provisional']);

        self::assertSame(2, $d['seasonality']['weeks'], 'the DAU hole on 08-15 breaks the third week back');
        self::assertEqualsWithDelta(7.0, array_sum($d['seasonality']['revenueIndex']), 1e-3);
        self::assertGreaterThan(1.3, $d['seasonality']['revenueIndex'][6]);

        self::assertCount(1, $d['anomalies']);
        self::assertSame('ClientCrashRate15m', $d['anomalies'][0]['metric']);
        self::assertSame('2026-08-31', $d['anomalies'][0]['date']);
        self::assertSame('high', $d['anomalies'][0]['severity']);

        self::assertCount(3, $d['platformValuation']);
        self::assertSame(0.06, $d['platformValuation'][0]['share']);
        self::assertSame((int)round(528 * 0.06), $d['platformValuation'][0]['dau']);
    }
}
