<?php
/**
 * Client for POST v1/universes/{id}/metrics of the Roblox Analytics API.
 *
 * Handles the API's rough edges the way the field-tested proxy did:
 *   - HTTP 429            -> shared budget is blocked; CLI waits, browser marks 'ratelimited'
 *   - 202 / done:false    -> long-running operation, polled on a validated path
 *   - range too wide      -> the allowed maximum is read from the message and the query shrunk
 *   - breakdown rejected  -> the metric is fetched again as a plain aggregate
 *   - network failure     -> retried with linear backoff up to maxTries
 *
 * It also lists the values of a dimension (dimensionValues), which the
 * Refresher needs for the metrics the API serves only one filter value at a time.
 *
 * The HTTP layer is a callable so tests run without network (see CurlTransport
 * for the request/response array shapes).
 */
declare(strict_types=1);

namespace ManorLedger\Roblox;

use Closure;

final class AnalyticsClient
{
    private const OPERATION_PATH = '#^v1/universes/\d+/operations/[A-Za-z0-9/_-]+$#';
    private const RANGE_PATTERN  = '/maximum allowed range of ([\d.]+) days/i';
    private const HTTP_TIMEOUT   = 60;
    private const CONNECT_TIMEOUT = 10;

    private Closure $transport;
    private Closure $now;
    private Closure $sleep;
    private int $requestsSent = 0;

    /**
     * @param array{concurrency?: int, maxTries?: int, maxPolls?: int, pollWait?: float,
     *              deadline?: int, windowSecs?: int} $options
     * @param ?callable $transport fn(list<array> $requests): list<array> $responses
     * @param ?callable $now       fn(): float
     * @param ?callable $sleep     fn(float $seconds): void
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $universeId,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly RateBudget $budget,
        private readonly array $options = [],
        ?callable $transport = null,
        ?callable $now = null,
        ?callable $sleep = null,
    ) {
        $this->transport = $transport !== null ? Closure::fromCallable($transport) : Closure::fromCallable(new CurlTransport());
        $this->now       = $now !== null ? Closure::fromCallable($now) : static fn (): float => microtime(true);
        $this->sleep     = $sleep !== null
            ? Closure::fromCallable($sleep)
            : static function (float $secs): void { usleep((int)($secs * 1e6)); };
    }

    /** Number of HTTP requests actually sent since construction (posts + polls). */
    public function requestsSent(): int
    {
        return $this->requestsSent;
    }

    /**
     * Fetch a set of catalog metrics (or dimension pseudo-metrics carrying a
     * `key`). Returns one cache row per metric, keyed by `key` ?? `id`.
     *
     * @param list<array<string, mixed>> $metrics
     * @return array<string, array<string, mixed>>
     */
    public function fetch(array $metrics): array
    {
        $startedAt = ($this->now)();
        $jobs = [];
        foreach ($metrics as $metric) {
            [$start, $end, $days] = $this->window($metric);
            $jobs[] = [
                'met' => $metric, 'start' => $start, 'end' => $end, 'days' => $days,
                'phase' => 'post', 'path' => null, 'tries' => 0, 'polls' => 0,
                'shrunk' => false, 'noBreakdown' => false, 'nextAt' => 0.0, 'row' => null,
            ];
        }

        while (true) {
            $pending = array_keys(array_filter($jobs, static fn (array $j): bool => $j['row'] === null));
            if ($pending === []) {
                break;
            }
            if (($this->now)() - $startedAt > $this->opt('deadline', 600)) {
                foreach ($pending as $i) {
                    $this->finish($jobs[$i], 'error', 'Overall client deadline exceeded');
                }
                break;
            }

            $now   = ($this->now)();
            $ready = array_values(array_filter($pending, static fn (int $i) => $jobs[$i]['nextAt'] <= $now));
            if ($ready === []) {
                $wait = min(array_map(static fn (int $i) => $jobs[$i]['nextAt'], $pending)) - $now;
                ($this->sleep)(max(0.05, min(2.0, $wait)));
                continue;
            }

            // Draw the rate-limit tokens before anything leaves the process.
            $wave = [];
            foreach (array_slice($ready, 0, $this->opt('concurrency', 2)) as $i) {
                if ($this->budget->take()) {
                    $wave[] = $i;
                } else {
                    $this->finish($jobs[$i], 'ratelimited', 'Rate-limit window exhausted');
                }
            }
            if ($wave === []) {
                continue;
            }

            $requests = [];
            foreach ($wave as $i) {
                $requests[] = $this->requestFor($jobs[$i]);
            }
            $this->requestsSent += count($requests);
            $responses = ($this->transport)($requests);
            foreach ($wave as $k => $i) {
                $this->handleResponse($jobs[$i], $responses[$k] ?? ['status' => 0, 'body' => false, 'headers' => [], 'error' => 'no response']);
            }
        }

        $out = [];
        foreach ($jobs as $job) {
            $out[$job['met']['key'] ?? $job['met']['id']] = $job['row'];
        }
        return $out;
    }

    /**
     * The values a filter-only dimension takes for a metric over the metric's
     * own window (POST v1/universes/{id}/dimension-values), with the same
     * polling, retry and rate-limit handling as a metric. Returns a cache row:
     * status 'ok' with `values`, 'empty', or a failure with `message`.
     *
     * @param array<string, mixed> $metric
     * @return array<string, mixed>
     */
    public function dimensionValues(array $metric, string $dimension): array
    {
        $listing = [
            'id'          => (string)$metric['id'],
            'key'         => $metric['id'] . '@' . $dimension,
            'granularity' => $metric['granularity'] ?? 'OneDay',
            'days'        => $metric['days'] ?? 30,
            'breakdown'   => null,
            'endpoint'    => 'dimension-values',
            'dimension'   => $dimension,
        ];
        return $this->fetch([$listing])[$listing['key']];
    }

    /** Time window aligned to the UTC day (or hour for hourly metrics). */
    private function window(array $metric, ?int $days = null): array
    {
        $days = $days ?? (int)($metric['days'] ?? 30);
        $unit = ($metric['granularity'] ?? null) === 'OneHour' ? 3600 : 86400;
        $end  = (int)floor(($this->now)() / $unit) * $unit;
        return [
            gmdate('Y-m-d\TH:i:s\Z', $end - $days * 86400),
            gmdate('Y-m-d\TH:i:s\Z', $end),
            $days,
        ];
    }

    private function requestFor(array $job): array
    {
        $common = [
            'timeout' => self::HTTP_TIMEOUT, 'connectTimeout' => self::CONNECT_TIMEOUT,
        ];
        if ($job['phase'] === 'poll') {
            return $common + [
                'method'  => 'GET',
                'url'     => $this->baseUrl . ltrim((string)$job['path'], '/'),
                'headers' => ['x-api-key' => $this->apiKey],
                'body'    => null,
            ];
        }
        if (self::isListing($job)) {
            $endpoint = 'dimension-values';
            $payload = [
                'metric'     => $job['met']['id'],
                // Plural: the singular "dimension" returns an empty list without an error.
                'dimensions' => [(string)$job['met']['dimension']],
                'startTime'  => $job['start'],
                'endTime'    => $job['end'],
            ];
        } else {
            $endpoint = 'metrics';
            $breakdown = $job['noBreakdown'] ? null : ($job['met']['breakdown'] ?? null);
            $payload = array_filter([
                'metric'      => $job['met']['id'],
                'granularity' => $job['met']['granularity'],
                'startTime'   => $job['start'],
                'endTime'     => $job['end'],
                'breakdown'   => $breakdown,
                // Set by the Refresher on the parts of a split metric.
                'filter'      => $job['met']['filter'] ?? null,
            ], static fn ($v) => $v !== null);
        }
        return $common + [
            'method'  => 'POST',
            'url'     => $this->baseUrl . 'v1/universes/' . $this->universeId . '/' . $endpoint,
            'headers' => ['x-api-key' => $this->apiKey, 'Content-Type' => 'application/json'],
            'body'    => json_encode($payload),
        ];
    }

    private function handleResponse(array &$job, array $response): void
    {
        $headers = array_change_key_case($response['headers'] ?? [], CASE_LOWER);
        $remaining = isset($headers['x-ratelimit-remaining']) ? (int)$headers['x-ratelimit-remaining'] : null;
        $reset     = isset($headers['x-ratelimit-reset']) ? (int)$headers['x-ratelimit-reset'] : null;
        $retry     = isset($headers['retry-after']) ? (float)$headers['retry-after'] : null;
        // Roblox's declared quota beats any estimate.
        $this->budget->observe($remaining, $reset);

        $http = (int)($response['status'] ?? 0);
        $body = $response['body'] ?? false;
        $now  = ($this->now)();

        // 429 first: an empty-bodied 429 must not be mistaken for a network hiccup.
        if ($http === 429) {
            $pause = $retry ?? ($reset !== null ? (float)$reset : (float)$this->opt('windowSecs', 60));
            $this->budget->exhausted($pause);
            if ($this->budget->allowsSleep()) {
                $job['nextAt'] = $now + $pause;
            } else {
                $this->finish($job, 'ratelimited', 'Rate-limit window exhausted');
            }
            return;
        }
        if ($body === false || $body === '') {
            $job['tries']++;
            if ($job['tries'] < $this->opt('maxTries', 3)) {
                $job['nextAt'] = $now + 2 * $job['tries'];
            } else {
                $error = (string)($response['error'] ?? '');
                $this->finish($job, 'error', 'Network error: ' . ($error !== '' ? $error : 'no response'));
            }
            return;
        }

        $json = json_decode((string)$body, true);
        $msg  = self::errorMessage(is_array($json) ? $json : null);

        if ($msg !== '' && !$job['shrunk'] && preg_match(self::RANGE_PATTERN, $msg, $m)) {
            [$job['start'], $job['end'], $job['days']] = $this->window($job['met'], max(1, (int)floor((float)$m[1])));
            $job['shrunk'] = true;
            $job['phase']  = 'post';
            $job['nextAt'] = $now + 0.3;
            return;
        }
        if ($msg !== '') {
            // If the breakdown is the cause, the metric is still worth having in aggregate form.
            if (!$job['noBreakdown'] && !empty($job['met']['breakdown'])) {
                $job['noBreakdown'] = true;
                $job['phase']  = 'post';
                $job['nextAt'] = $now + 0.3;
                return;
            }
            $this->finish($job, 'error', $msg, ['code' => $json['error']['code'] ?? $http]);
            return;
        }
        if (!is_array($json)) {
            $this->finish($job, 'error', 'Non-JSON response (HTTP ' . $http . ')');
            return;
        }

        if (($json['done'] ?? true) === false) {
            $this->schedulePoll($job, $json, $now);
            return;
        }
        if ($http !== 200 && $http !== 202) {
            $this->finish($job, 'error', 'HTTP ' . $http);
            return;
        }
        if (self::isListing($job)) {
            $values = self::extractValues($json, (string)$job['met']['dimension']);
            if ($values !== []) {
                $this->finish($job, 'ok', '', ['values' => $values]);
            } else {
                $this->finish($job, 'empty', 'No value of this dimension in the period');
            }
            return;
        }
        $series = self::extractSeries($json);
        if ($series !== []) {
            $this->finish($job, 'ok', '', ['series' => $series]);
        } else {
            $this->finish($job, 'empty', 'No data for this metric in the period');
        }
    }

    /** Long-running operation: switch to polling on a strictly validated path. */
    private function schedulePoll(array &$job, array $json, float $now): void
    {
        // The path comes from the remote response: only the documented shape
        // is accepted, so it can never become an arbitrary URL fragment.
        $candidate = ltrim((string)($json['path'] ?? $job['path'] ?? ''), '/');
        if ($candidate !== '' && !preg_match(self::OPERATION_PATH, $candidate)) {
            $this->finish($job, 'error', 'Unexpected polling path, request aborted');
            return;
        }
        $job['polls']++;
        if ($job['polls'] > $this->opt('maxPolls', 8)) {
            $this->finish($job, 'error', 'Query still running on Roblox side after max polls, retry the refresh');
            return;
        }
        $job['phase']  = 'poll';
        $job['path']   = $candidate !== '' ? $candidate : $job['path'];
        $job['nextAt'] = $now + $this->opt('pollWait', 1.5);
        if ($job['path'] === null) {
            $this->finish($job, 'error', 'Operation without a polling path');
        }
    }

    private function finish(array &$job, string $status, string $message = '', array $extra = []): void
    {
        $breakdown = (!$job['noBreakdown'] && !empty($job['met']['breakdown']))
            ? $job['met']['breakdown'][0] : null;
        $job['row'] = array_merge([
            'id'          => $job['met']['id'],
            'status'      => $status,
            'key'         => $job['met']['key'] ?? $job['met']['id'],
            'granularity' => $job['met']['granularity'] ?? null,
            'startTime'   => $job['start'],
            'endTime'     => $job['end'],
            'days'        => $job['days'],
            'series'      => [],
            'breakdown'   => $breakdown,
        ], $extra);
        if ($message !== '') {
            $job['row']['message'] = $message;
        }
    }

    /** Series of a completed response, most relevant first (by total, then by length). */
    private static function extractSeries(array $json): array
    {
        $series = [];
        foreach ($json['response']['values'] ?? [] as $value) {
            $parts = [];
            foreach ($value['breakdowns'] ?? [] as $b) {
                // Roblox shape: {"dimension":"Platform","value":"Phone"} -> keep the value.
                $parts[] = is_array($b) ? (string)($b['value'] ?? reset($b)) : (string)$b;
            }
            $points = [];
            foreach ($value['dataPoints'] ?? [] as $p) {
                if (($p['value'] ?? null) === null) {
                    continue;
                }
                $points[] = ['t' => $p['time'] ?? null, 'v' => (float)$p['value']];
            }
            if ($points !== []) {
                $series[] = [
                    'label'  => implode(' · ', $parts),
                    'total'  => array_sum(array_column($points, 'v')),
                    'points' => $points,
                ];
            }
        }
        usort($series, static fn (array $a, array $b): int =>
            [abs($b['total']), count($b['points'])] <=> [abs($a['total']), count($a['points'])]);
        return $series;
    }

    private static function isListing(array $job): bool
    {
        return ($job['met']['endpoint'] ?? null) === 'dimension-values';
    }

    /**
     * Values of one dimension from a completed dimension-values response, in
     * the order Roblox lists them (not meaningful: it is not the step order).
     *
     * @return list<string>
     */
    private static function extractValues(array $json, string $dimension): array
    {
        $values = [];
        foreach ($json['response']['values'] ?? [] as $entry) {
            if (!is_array($entry) || ($entry['dimension'] ?? null) !== $dimension) {
                continue;
            }
            foreach ($entry['values'] ?? [] as $v) {
                $value = is_array($v) ? (string)($v['value'] ?? '') : (string)$v;
                if ($value !== '' && !in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }
        return $values;
    }

    /** Error message in either of the two shapes Roblox uses. */
    private static function errorMessage(?array $json): string
    {
        if ($json === null) {
            return '';
        }
        if (isset($json['error']['message'])) {
            return (string)$json['error']['message'];
        }
        if (isset($json['errors'][0]['message'])) {
            return (string)$json['errors'][0]['message'];
        }
        return '';
    }

    private function opt(string $name, int|float $default): int|float
    {
        return $this->options[$name] ?? $default;
    }
}
