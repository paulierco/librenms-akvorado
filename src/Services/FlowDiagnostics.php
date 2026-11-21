<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** On-demand exporter-wide checks, independent of dashboard and Sankey queries. */
final class FlowDiagnostics
{
    private const UNKNOWN_INTERFACES = ['ingress' => 'InIfName', 'egress' => 'OutIfName'];

    public function __construct(
        private readonly ExporterResolver $resolver,
        private readonly AkvoradoClient $client,
    ) {}

    public function forDevice(object $device, int $minutes, ?array $bounds): array
    {
        $exporter = $this->resolver->resolve($device);
        if ($exporter === null) {
            return ['state' => 'unmapped'];
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $end = $bounds['end'] ?? $now->setTime((int) $now->format('H'), (int) $now->format('i'));
        $query = FlowQuery::line($exporter['ip'], null, $minutes, $end, 'l3bps', 'both', start: $bounds['start'] ?? null);
        $key = 'akvorado-flow-diagnostics-v2:'.hash('sha256', json_encode([
            config('akvorado-flow.url'), $query,
        ], JSON_THROW_ON_ERROR));

        return ['exporter' => $exporter, 'start' => $query['start'], 'end' => $query['end']]
            + Cache::remember($key, (int) config('akvorado-flow.cache_seconds', 60), fn (): array => $this->measure($query));
    }

    private function measure(array $query): array
    {
        $baseline = $this->series($query);
        if ($baseline === null) {
            return ['state' => 'query_failed'];
        }
        if ($baseline['sum'] === 0.0) {
            return ['state' => 'no_flows'];
        }

        $shares = [];
        $unknownRates = [];
        foreach (self::UNKNOWN_INTERFACES as $direction => $field) {
            $unknown = $this->series(array_replace($query, ['filter' => $query['filter'].' AND '.$field.' = ""']));
            // A successful empty response can omit the time axis entirely.
            $comparable = $unknown !== null && ($unknown['times'] === [] || $unknown['times'] === $baseline['times']);
            $shares[$direction] = self::share($baseline['sum'], $comparable ? $unknown['sum'] : null);
            $unknownRates[$direction] = $comparable
                ? ($unknown['rates'] ?: array_fill(0, count($baseline['rates']), 0.0))
                : [];
        }

        return [
            'state' => 'observed',
            'lastBucket' => $baseline['lastBucket'],
            'shares' => $shares,
            'history' => self::history($baseline, $unknownRates, strtotime($query['start']), strtotime($query['end'])),
        ];
    }

    private static function share(float $total, ?float $unknown): ?float
    {
        return $total > 0 && $unknown !== null && $unknown <= $total ? 100 * ($unknown / $total) : null;
    }

    private static function history(array $baseline, array $unknownRates, int $from, int $to): ?array
    {
        $times = $baseline['times'];
        $step = FlowFormatter::bucketStep($times);
        if ($step === null || $times[array_key_last($times)] - $times[0] > $step * 2000) {
            return null;
        }
        $indexes = array_flip($times);
        $first = $times[0] + max(0, (int) ceil(($from - $times[0]) / $step)) * $step;
        $last = min($times[array_key_last($times)], $to - $step);
        $labels = [];
        $totals = [];
        $directions = array_keys(self::UNKNOWN_INTERFACES);
        $values = array_fill_keys($directions, []);
        for ($time = $first; $time <= $last; $time += $step) {
            $index = $indexes[$time] ?? null;
            $total = $index === null ? null : $baseline['rates'][$index];
            $labels[] = gmdate('Y-m-d H:i:s', $time).' UTC';
            $totals[] = $total;
            foreach ($directions as $direction) {
                $values[$direction][] = $total === null ? null : self::share($total, $unknownRates[$direction][$index] ?? null);
            }
        }
        if ($labels === []) {
            return null;
        }

        return [
            'step' => $step,
            'totals' => $totals,
            'chart' => [
                'unit' => 'percent',
                'lines' => true,
                'labels' => $labels,
                'series' => [
                    ['label' => 'Unknown ingress', 'values' => $values['ingress']],
                    ['label' => 'Unknown egress', 'values' => $values['egress'], 'dashed' => true],
                ],
            ],
        ];
    }

    /** Akvorado returns bucket-start timestamps and L3 rates on the same grid. */
    private function series(array $query): ?array
    {
        try {
            $payload = $this->client->post('graph/line', $query);
            $times = $payload['t'] ?? null;
            $points = $payload['points'] ?? null;
            if (! is_array($times) || ! array_is_list($times) || ! is_array($points) || ! array_is_list($points)) {
                return null;
            }
            $times = array_map(FlowFormatter::unixTime(...), $times);
            if (in_array(null, $times, true)) {
                return null;
            }
            if ($points === []) {
                return ['sum' => 0.0, 'times' => $times, 'rates' => [], 'lastBucket' => null];
            }
            $rates = $points[0] ?? null;
            if (count($points) !== 1 || ! is_array($rates) || array_keys($times) !== array_keys($rates)
                || (int) ($payload['axis'][0] ?? 1) !== 1) {
                return null;
            }

            $sum = 0.0;
            $samples = [];
            $lastBucket = null;
            foreach ($rates as $index => $rate) {
                if (! is_numeric($rate) || ! is_finite((float) $rate) || (float) $rate < 0) {
                    return null;
                }
                $rate = (float) $rate;
                $samples[] = $rate;
                $sum += $rate;
                if ($rate > 0) {
                    $lastBucket = $times[$index];
                }
            }

            return [
                'sum' => $sum,
                'times' => $times,
                'rates' => $samples,
                'lastBucket' => $lastBucket === null ? null : gmdate('Y-m-d\TH:i:s\Z', $lastBucket),
            ];
        } catch (\Throwable $e) {
            Log::warning('Akvorado plugin: exporter diagnostics query failed', ['exception_type' => get_class($e)]);
            return null;
        }
    }
}
