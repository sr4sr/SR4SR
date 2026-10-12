<?php
/**
 * Read-only view over config/metrics.json (the metric catalog) and
 * config/dimensions.json (the metric × dimension pairs fetched as extra
 * requests). Everything the refresher and the analytics layer need to know
 * about a metric comes from here, never from the raw JSON.
 */
declare(strict_types=1);

namespace ManorLedger\Roblox;

use InvalidArgumentException;
use RuntimeException;

final class MetricCatalog
{
    /**
     * Breakdowns that are part of the metric's own definition and therefore
     * safe to request together with it: each label is a distinct bucket of the
     * same population (a data-store status, a cohort day, a thumbnail asset).
     *
     * Every other catalog breakdown is a *user* dimension (Platform, Country,
     * AgeGroupV2, IsNewUser, ...). Those are deliberately NOT requested with the
     * metric: a user who plays on phone and on PC is counted once in the real
     * DAU but twice in the per-platform series, so summing the split
     * overstates the aggregate. Such metrics are fetched as a plain aggregate
     * series (label "") and their Platform split comes from a dimension pair.
     */
    public const INTRINSIC_BREAKDOWNS = [
        'AdFormat',
        'CohortDay',
        'CohortWeek',
        'CustomEventName',
        'DataStoreOperation',
        'DataStoreStatus',
        'FunnelStep',
        'MemoryStoreOperation',
        'MemoryStoreStatus',
        'MemoryUsageCategory',
        'SessionTimeBucket',
        'SpeechToTextTranscriptionStatus',
        'TextToSpeechRawAudioStatus',
        'ThumbnailAsset',
        'TransactionType',
    ];

    /**
     * Dimensions the API accepts only as a filter, never as a breakdown, and
     * that it requires on every metric declaring them: since 8 Oct 2026 a
     * funnel metric without a FunnelName filter is rejected outright
     * ("Dimension FunnelName is required as a filter"). Such a metric is
     * fetched once per value, its series labelled with the value (Refresher).
     */
    public const SPLIT_DIMENSIONS = ['FunnelName'];

    /** @var array<string, array<string, mixed>> metric id => definition */
    private array $byId = [];

    /** @var array<string, array<string, mixed>> "Metric|Dimension" => pseudo-metric definition */
    private array $pairs = [];

    private int $universeId;

    /**
     * @param array<string, mixed> $metricsJson    decoded config/metrics.json
     * @param array<string, mixed> $dimensionsJson decoded config/dimensions.json
     */
    public function __construct(array $metricsJson, array $dimensionsJson)
    {
        if (!isset($metricsJson['metrics']) || !is_array($metricsJson['metrics'])) {
            throw new InvalidArgumentException('metrics.json has no "metrics" list');
        }
        $this->universeId = (int)($metricsJson['universeId'] ?? 0);
        foreach ($metricsJson['metrics'] as $metric) {
            if (!is_array($metric) || !isset($metric['id'])) {
                continue;
            }
            $metric['breakdown'] = $this->effectiveBreakdown($metric);
            $metric['splitBy']   = self::splitDimension($metric);
            $this->byId[(string)$metric['id']] = $metric;
        }
        foreach ($dimensionsJson['pairs'] ?? [] as $pair) {
            $pseudo = $this->pairToMetric($pair);
            if ($pseudo !== null) {
                $this->pairs[$pseudo['key']] = $pseudo;
            }
        }
    }

    public static function load(string $metricsPath, string $dimensionsPath): self
    {
        return new self(self::readJson($metricsPath), self::readJson($dimensionsPath));
    }

    public function universeId(): int
    {
        return $this->universeId;
    }

    /** @return list<array<string, mixed>> every catalog metric, breakdown already normalised */
    public function metrics(): array
    {
        return array_values($this->byId);
    }

    /** @return ?array<string, mixed> */
    public function byId(string $id): ?array
    {
        return $this->byId[$id] ?? null;
    }

    /** @return list<array<string, mixed>> metrics with OneDay granularity (what the dashboard plots) */
    public function dailyMetrics(): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (array $m): bool => ($m['granularity'] ?? null) === 'OneDay',
        ));
    }

    /**
     * Metric × dimension pairs as fetchable pseudo-metrics: same definition as
     * the base metric, with `key` = "Metric|Dimension" and `breakdown` forced
     * to that dimension. Pairs whose dimension the API does not declare for
     * that metric are silently dropped, as the legacy proxy did.
     *
     * @return array<string, array<string, mixed>> key => pseudo-metric
     */
    public function dimensionPairs(): array
    {
        return $this->pairs;
    }

    /**
     * The filter-only dimension a catalog entry has to be split on, or null.
     *
     * @param array<string, mixed> $metric
     */
    private static function splitDimension(array $metric): ?string
    {
        foreach (self::SPLIT_DIMENSIONS as $dimension) {
            if (in_array($dimension, $metric['dimensions'] ?? [], true)) {
                return $dimension;
            }
        }
        return null;
    }

    /** Unit (catalog `format`) of a metric id or of a "Metric|Dimension" key. */
    public function unitOf(string $id): ?string
    {
        $metricId = str_contains($id, '|') ? strstr($id, '|', true) : $id;
        $unit = $this->byId[(string)$metricId]['format'] ?? null;
        return $unit === null ? null : (string)$unit;
    }

    /**
     * Breakdown actually sent to the API for a catalog metric: the catalog
     * value when it is intrinsic, null when it is a user dimension.
     *
     * @param array<string, mixed> $metric
     * @return ?list<string>
     */
    private function effectiveBreakdown(array $metric): ?array
    {
        $breakdown = $metric['breakdown'] ?? null;
        if (!is_array($breakdown) || $breakdown === []) {
            return null;
        }
        $kept = array_values(array_filter(
            array_map('strval', $breakdown),
            static fn (string $dim): bool => in_array($dim, self::INTRINSIC_BREAKDOWNS, true),
        ));
        return $kept === [] ? null : $kept;
    }

    /**
     * @param mixed $pair
     * @return ?array<string, mixed>
     */
    private function pairToMetric(mixed $pair): ?array
    {
        if (!is_array($pair) || !isset($pair['metric'], $pair['dimension'])) {
            return null;
        }
        $base = $this->byId[(string)$pair['metric']] ?? null;
        if ($base === null) {
            return null;
        }
        $dimension = (string)$pair['dimension'];
        if (!in_array($dimension, $base['dimensions'] ?? [], true)) {
            return null;
        }
        $pseudo              = $base;
        $pseudo['key']       = $pair['metric'] . '|' . $dimension;
        $pseudo['breakdown'] = [$dimension];
        $pseudo['name']      = (string)($pair['name'] ?? $base['name'] ?? $pseudo['key']);
        $pseudo['barSort']   = 'value';
        return $pseudo;
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException("Cannot read $path");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("$path is not valid JSON");
        }
        return $data;
    }
}
