<?php
/**
 * Assembles data/dashboard.json from the history, following the contract in
 * docs/ARCHITECTURE.md. The catalog arrives as a plain id => entry array and
 * the glossary as a decoded list, so the builder depends on no other agent's
 * classes and tests can feed it tiny synthetic inputs.
 */
declare(strict_types=1);

namespace ManorLedger\Analytics;

use ManorLedger\Storage\History;

final class DashboardBuilder
{
    private const DIMENSION_PAIRS = [
        'DailyActiveUsers|Platform', 'DailyRevenue|Platform', 'DailyActiveUsers|Country',
        'DailyActiveUsers|AgeGroupV2', 'DailyActiveUsers|IsNewUser', 'ItemMonetizationRevenue|Platform',
        // Where the players came from: the funnel by AcquisitionSource is what
        // the Ads view reads, paid sources included (SponsoredAds, SearchAds).
        'UniqueUsersWithImpressions|AcquisitionSource', 'UniqueUsersWithClicks|AcquisitionSource',
        'UniqueUsersWithPlaySessions|AcquisitionSource', 'DailyActiveUsers|AcquisitionSource',
        'EndToEndCVR|AcquisitionSource',
    ];
    /** History pairs the ads analysis folds into paid vs organic (not all of them are published as dimensions). */
    private const SOURCE_PAIRS = [
        'impressions' => 'UniqueUsersWithImpressions|AcquisitionSource',
        'clicks'      => 'UniqueUsersWithClicks|AcquisitionSource',
        'plays'       => 'UniqueUsersWithPlaySessions|AcquisitionSource',
        'dau'         => 'DailyActiveUsers|AcquisitionSource',
        'd1'          => 'ForwardD1Retention|AcquisitionSource',
        'd7'          => 'ForwardD7Retention|AcquisitionSource',
    ];
    private const COUNTRY_TOP = 8;
    private const OTHER_LABEL = 'Altri';
    /** Roblox's bucket for rows it could not attribute: a stray 0/1, noise on a chart. */
    private const UNKNOWN_PREFIX = 'RAQI_RESERVED';
    /** The only breakdown whose labels can be averaged into a rate, weighted by DAU per label. */
    private const WEIGHT_DIMENSION = 'Platform';
    /** Source of the in-session survival curve: its labels are seconds, not a real breakdown. */
    private const SESSION_BUCKET_METRIC = 'TotalSessionsEndedInBucket';

    private readonly Economics $economics;
    private readonly Seasonality $seasonality;
    private readonly Anomalies $anomalies;
    private readonly SessionSurvival $sessionSurvival;
    private readonly AdsAnalysis $adsAnalysis;

    /**
     * @param array $config        Decoded config/app.php.
     * @param array $catalogById   metricId => catalog entry (name, en, category, format, breakdown...).
     * @param array $glossary      Decoded config/glossary.json (list of {term, meaning}).
     * @param array|null $adsLedger Decoded data/ads.json (campaign spend), null when never imported.
     */
    public function __construct(
        private readonly array $config,
        private readonly array $catalogById,
        private readonly array $glossary,
        private readonly ?array $adsLedger = null,
    ) {
        $eco = $config['economics'];
        $this->economics = new Economics(
            (float)$eco['devexUsdPerRobux'],
            (float)$eco['royaltyShare'],
            $eco['multiples'],
            $eco['plateauShares'],
        );
        $this->seasonality = new Seasonality();
        $this->anomalies   = new Anomalies();
        $this->sessionSurvival = new SessionSurvival();
        $this->adsAnalysis = new AdsAnalysis($this->economics);
    }

    /** @param int $fetchedAt Unix time of the fetch the history was last fed with (decides the provisional day). */
    public function build(History $history, int $fetchedAt, ?int $now = null): array
    {
        $history->load();
        $revenueAll = $this->aggregate($history, 'ItemMonetizationRevenue');
        $dau        = $this->aggregate($history, 'DailyActiveUsers');

        // The freshest revenue day is provisional when the fetch happened within a day of it:
        // Roblox publishes it a day late and revises it upward (+4.5..7% observed) the day after.
        $provisionalDate = null;
        $revenue = $revenueAll;
        if ($revenueAll !== []) {
            $fresh = array_key_last($revenueAll);
            $fetchDay = gmdate('Y-m-d', $fetchedAt);
            if ((strtotime($fetchDay . ' UTC') - strtotime($fresh . ' UTC')) / 86400 <= 1) {
                $provisionalDate = $fresh;
                unset($revenue[$fresh]);
            }
        }
        $allDates = $history->dates();
        $dataThrough = $revenue !== [] ? array_key_last($revenue) : ($allDates === [] ? null : end($allDates));

        $inputs = [
            'revenue'     => $revenue,
            'dau'         => $dau,
            'mau'         => $this->aggregate($history, 'MonthlyActiveUsers'),
            'payingUsers' => $this->aggregate($history, 'PayingUsers'),
            'cvr'         => $this->aggregate($history, 'PayingUsersCVR'),
            'd1'          => $this->aggregate($history, 'ForwardD1Retention'),
            'd7'          => $this->aggregate($history, 'ForwardD7Retention'),
            'stickiness'  => $this->aggregate($history, 'DauMauStickiness'),
        ];
        $kpis = $dataThrough === null ? [] : $this->economics->kpis($inputs, $dataThrough);
        $dimensions = $this->dimensions($history);
        $arpdau7 = $kpis['arpdau7Robux']['value'] ?? null;
        $peakDau = $dau === [] ? null : max($dau);

        return [
            'generatedAt'      => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()),
            'dataThrough'      => $dataThrough,
            'provisionalDate'  => $provisionalDate,
            'coverage'         => [
                'from'      => $allDates === [] ? null : $allDates[0],
                'to'        => $allDates === [] ? null : end($allDates),
                'days'      => count($allDates),
                'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z', $fetchedAt),
            ],
            'game'             => ['name' => $this->config['app']['game'], 'universeId' => $this->config['app']['universeId']],
            'assumptions'      => $this->assumptions(),
            'kpis'             => $kpis,
            'metrics'          => $this->metrics($history),
            'dimensions'       => $dimensions,
            'derived'          => $this->derived($revenueAll, $revenue, $dau, $inputs, $dimensions),
            'ads'              => $this->adsAnalysis->build($this->adsLedger, $this->bySource($history), $revenueAll, $dau),
            'weekOverWeek'     => $this->weekOverWeek($revenueAll, $dau, $provisionalDate),
            'seasonality'      => $dataThrough === null ? null : $this->seasonality->build($revenue, $dau, $dataThrough),
            'sessionSurvival'  => $this->sessionSurvival->build($history->metric(self::SESSION_BUCKET_METRIC), $dataThrough),
            'anomalies'        => $this->anomalies->detect($this->candidateMetrics($history), $provisionalDate),
            'platformValuation'=> ($peakDau === null || $arpdau7 === null) ? [] : $this->economics->plateauScale((float)$peakDau, (float)$arpdau7),
            'glossary'         => $this->glossary,
        ];
    }

    private function assumptions(): array
    {
        $eco = $this->config['economics'];

        return [
            'devexUsdPerRobux' => $eco['devexUsdPerRobux'],
            'royaltyShare'     => $eco['royaltyShare'],
            'multiples'        => $eco['multiples'],
            'plateauShares'    => $eco['plateauShares'],
            'notes'            => 'Tasso DevEx Roblox Developer Exchange (set 2026); quote di terzi (royalty e altre) del '
                . round($eco['royaltyShare'] * 100) . '% trattenute prima del netto. '
                . 'Media a 7 giorni consecutivi su giorni consolidati: l\'ultimo giorno di ricavi è provvisorio '
                . 'e resta fuori dalla finestra. Multiplo base ' . $eco['multiples']['base'] . 'x sul mensile netto se il livello tiene, '
                . $eco['multiples']['conservative'] . 'x sugli scenari di plateau.',
        ];
    }

    /** The aggregate ("" label) of a metric, or the fold of its labels when Roblox only gave a breakdown. */
    private function aggregate(History $history, string $id): array
    {
        $metric = $history->metric($id);
        if ($metric === null) {
            return [];
        }
        if (isset($metric['series'][''])) {
            return Series::compact($metric['series']['']);
        }

        return $this->foldLabels($history, $id, $metric) ?? [];
    }

    /**
     * Additive units are summed over the labels. A rate broken down by
     * platform is averaged weighting each platform by its DAU; any other
     * breakdown of a rate (cohort day, thumbnail, memory category) has no
     * meaningful total and returns null.
     */
    private function foldLabels(History $history, string $id, array $metric): ?array
    {
        $labelled = $this->knownLabels($metric['series']);
        if ($labelled === []) {
            return null;
        }
        if ($this->isAdditive($metric['unit'])) {
            return Series::aggregateLabels($labelled, true);
        }
        if (($this->catalogById[$id]['breakdown'][0] ?? null) !== self::WEIGHT_DIMENSION) {
            return null;
        }
        $dau = $history->metric('DailyActiveUsers');
        $weights = $dau === null || isset($dau['series']['']) ? [] : $this->knownLabels($dau['series']);

        return Series::aggregateLabels($labelled, false, $weights);
    }

    /** A funnel metric, fetched once per FunnelName (Roblox\MetricCatalog::SPLIT_DIMENSIONS). */
    private function isSplitPerFunnel(string $id): bool
    {
        return in_array('FunnelName', $this->catalogById[$id]['dimensions'] ?? [], true);
    }

    private function isAdditive(string $unit): bool
    {
        return in_array($unit, Series::ADDITIVE_UNITS, true);
    }

    private function knownLabels(array $series): array
    {
        return array_filter($series, static fn ($label) => $label !== '' && !str_starts_with((string)$label, self::UNKNOWN_PREFIX), ARRAY_FILTER_USE_KEY);
    }

    private function metrics(History $history): array
    {
        $out = [];
        foreach ($history->ids() as $id) {
            if (str_contains($id, '|')) {
                continue;
            }
            $metric = $history->metric($id);
            $labelMaps = $metric['series'];
            $flags = [];
            if ($this->isSplitPerFunnel($id)) {
                // Until 8 Oct 2026 Roblox summed every funnel into one series;
                // funnels measure different things, so neither that series nor
                // a fold of the per-funnel ones is a meaningful total.
                unset($labelMaps['']);
                if ($labelMaps === []) {
                    continue;
                }
            } elseif (!isset($labelMaps[''])) {
                $aggregate = $this->foldLabels($history, $id, $metric);
                if ($aggregate !== null) {
                    $labelMaps = ['' => $aggregate] + $labelMaps;
                    $flags = ['aggregatedFromBreakdown' => true, 'aggregation' => $this->isAdditive($metric['unit']) ? 'sum' : 'weightedMean'];
                }
            }
            $catalog = $this->catalogById[$id] ?? [];
            $out[$id] = [
                'unit'      => $metric['unit'],
                'name'      => $catalog['name'] ?? $id,
                'en'        => $catalog['en'] ?? $id,
                'category'  => $catalog['category'] ?? 'Other',
                'desc'      => $catalog['desc'] ?? '',
                'breakdown' => $catalog['breakdown'][0] ?? null,
            ] + $this->block($metric['unit'], $labelMaps) + $flags;
        }

        return $out;
    }

    /** Selected metric x dimension pairs, falling back to the metric's own native breakdown. */
    private function dimensions(History $history): array
    {
        $out = [];
        foreach (self::DIMENSION_PAIRS as $pair) {
            [$metricId, $dimension] = explode('|', $pair, 2);
            $source = $history->metric($pair);
            if ($source === null) {
                $own = $history->metric($metricId);
                $native = $this->catalogById[$metricId]['breakdown'][0] ?? null;
                if ($own === null || $native !== $dimension || isset($own['series'][''])) {
                    continue;
                }
                $source = $own;
            }
            $labelMaps = $this->knownLabels($source['series']);
            if ($labelMaps === []) {
                continue;
            }
            if ($dimension === 'Country') {
                $labelMaps = $this->topWithOthers($labelMaps, self::COUNTRY_TOP);
            }
            $catalog = $this->catalogById[$metricId] ?? [];
            $out[$pair] = [
                'metric'    => $metricId,
                'dimension' => $dimension,
                'name'      => ($catalog['name'] ?? $metricId) . ' per ' . $dimension,
                'category'  => $catalog['category'] ?? 'Other',
            ] + $this->block($source['unit'], $labelMaps);
        }

        return $out;
    }

    /**
     * The AcquisitionSource breakdowns the ads analysis needs, as
     * label => (date => value) maps with Roblox's unattributed bucket dropped.
     *
     * @return array<string, array<string, array<string, int|float|null>>>
     */
    private function bySource(History $history): array
    {
        $out = [];
        foreach (self::SOURCE_PAIRS as $key => $pair) {
            $metric = $history->metric($pair);
            if ($metric !== null) {
                $out[$key] = $this->knownLabels($metric['series']);
            }
        }

        return $out;
    }

    private function topWithOthers(array $labelMaps, int $top): array
    {
        uasort($labelMaps, static fn (array $a, array $b) => array_sum($b) <=> array_sum($a));
        $keep = array_slice($labelMaps, 0, $top, true);
        $rest = array_slice($labelMaps, $top, null, true);
        if ($rest !== []) {
            $keep[self::OTHER_LABEL] = Series::sum(...array_values($rest));
        }

        return $keep;
    }

    private function derived(array $revenueAll, array $revenue, array $dau, array $inputs, array $dimensions): array
    {
        $eco = $this->economics;
        $valuation = $eco->valuationSeries($revenue);
        $stickiness = $inputs['stickiness'] !== [] ? $inputs['stickiness'] : Series::ratio($dau, $inputs['mau']);

        $platformShare = [];
        if (isset($dimensions['DailyRevenue|Platform'])) {
            $byLabel = [];
            foreach ($dimensions['DailyRevenue|Platform']['series'] as $s) {
                $byLabel[$s['label']] = array_combine($dimensions['DailyRevenue|Platform']['dates'], $s['values']);
            }
            $total = Series::sum(...array_values($byLabel));
            foreach ($byLabel as $label => $map) {
                $platformShare[$label] = Series::ratio($map, $total);
            }
        }

        return [
            'revenueUsdNet'           => $this->block('usd', ['' => $eco->netUsdSeries($revenueAll)], 2),
            'revenueUsdGross'         => $this->block('usd', ['' => $eco->grossUsdSeries($revenueAll)], 2),
            'revenue7dAvgRobux'       => $this->block('robux', ['' => Series::rollingMean($revenue, 7)], 2),
            'revenueCumulativeUsdNet' => $this->block('usd', ['' => $eco->netUsdSeries(Series::cumulative($revenueAll))], 2),
            'valuationUsd'            => $this->block('usd', $valuation, 2),
            'arpdauRobux'             => $this->block('robux', ['' => Series::ratio($revenueAll, $dau)], 4),
            'arppuRobux'              => $this->block('robux', ['' => Series::ratio($revenueAll, $inputs['payingUsers'])], 2),
            'dauMauStickiness'        => $this->block('pct', ['' => $stickiness], 4),
            'revenuePlatformShare'    => $this->block('pct', $platformShare, 4),
        ];
    }

    private function weekOverWeek(array $revenue, array $dau, ?string $provisionalDate): array
    {
        $rows = [];
        foreach (array_keys(Series::lastN($revenue, 8)) as $date) {
            $prev = $revenue[Series::shift($date, -7)] ?? null;
            if ($prev === null) {
                continue;
            }
            $rows[] = [
                'date'        => $date,
                'weekday'     => Seasonality::WEEKDAYS[Series::weekday($date) - 1],
                'revenue'     => $revenue[$date],
                'revenuePrev' => $prev,
                'delta'       => $prev == 0 ? null : round($revenue[$date] / $prev - 1, 4),
                'dau'         => $dau[$date] ?? null,
                'arpdau'      => empty($dau[$date]) ? null : round($revenue[$date] / $dau[$date], 4),
                'provisional' => $date === $provisionalDate,
            ];
        }

        return $rows;
    }

    /** Watched metrics with a guaranteed "" aggregate, so Anomalies never has to fold labels itself. */
    private function candidateMetrics(History $history): array
    {
        $out = [];
        foreach (array_keys(Anomalies::CANDIDATES) as $id) {
            $metric = $history->metric($id);
            if ($metric === null) {
                continue;
            }
            if (!isset($metric['series'][''])) {
                $aggregate = $this->foldLabels($history, $id, $metric);
                if ($aggregate !== null) {
                    $metric['series'] = ['' => $aggregate] + $metric['series'];
                }
            }
            $out[$id] = $metric;
        }

        return $out;
    }

    /** @param array<string, array<string, int|float|null>> $labelMaps */
    private function block(string $unit, array $labelMaps, ?int $decimals = null): array
    {
        return Series::block($unit, $labelMaps, $decimals);
    }
}
