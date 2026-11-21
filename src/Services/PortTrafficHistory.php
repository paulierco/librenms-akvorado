<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use App\Data\Graphing\DataSeries;
use App\Graphs\Rrd\RrdSeriesReader;
use App\Models\Device;
use App\Models\Port;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class PortTrafficHistory
{
    private const DIRECTIONS = ['in' => 'INOCTETS', 'out' => 'OUTOCTETS'];

    public function __construct(
        private readonly ExporterResolver $resolver,
        private readonly AkvoradoClient $client,
    ) {}

    public function forPort(Device $device, Port $port, int $minutes, ?array $bounds): array
    {
        [$start, $end] = self::window($minutes, $bounds);
        $ip = $this->resolver->resolve($device)['ip'] ?? null;
        $samples = $this->samples($device, $port, $ip, $start, $end);

        return self::chart($samples['snmp'], $samples['flows'], $start->getTimestamp(), $end->getTimestamp())
            + ['start' => $start->getTimestamp(), 'end' => $end->getTimestamp(), 'exporterMapped' => $ip !== null];
    }

    public function forComparison(Device $device, Port $first, Port $second, int $minutes, ?array $bounds): array
    {
        [$start, $end] = self::window($minutes, $bounds);
        $ip = $this->resolver->resolve($device)['ip'] ?? null;
        $nameCounts = $ip === null ? [] : array_count_values($device->ports()
            ->whereIn('ifName', [(string)$first->ifName, (string)$second->ifName])->pluck('ifName')
            ->filter(static fn ($name) => is_string($name))->all());
        $ports = [];
        $ambiguous = [];
        foreach ([$first, $second] as $port) {
            $name = (string)$port->ifName;
            $label = $name !== '' ? $name : ($port->ifDescr ?: 'Port '.$port->port_id);
            $duplicate = ($nameCounts[$name] ?? 0) > 1;
            if ($duplicate || (string)$first->ifName === (string)$second->ifName) {
                $label .= ' (ifIndex '.$port->ifIndex.')';
            }
            if ($duplicate) {
                $ambiguous[] = $label;
            }
            $ports[(int)$port->port_id] = ['label' => $label]
                + $this->samples($device, $port, $duplicate ? null : $ip, $start, $end);
        }

        return self::comparisonChart($ports, $start->getTimestamp(), $end->getTimestamp())
            + [
                'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(),
                'exporterMapped' => $ip !== null, 'ambiguous' => $ambiguous, 'portLabels' => array_column($ports, 'label'),
            ];
    }

    private static function window(int $minutes, ?array $bounds): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $end = $bounds['end'] ?? $now->setTime((int)$now->format('H'), (int)$now->format('i'));
        $start = $bounds['start'] ?? $end->modify('-'.$minutes.' minutes');

        return [$start, $end];
    }

    private function samples(Device $device, Port $port, ?string $ip, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $from = $start->getTimestamp();
        $to = $end->getTimestamp();
        $name = (string)$port->ifName;

        $key = 'akvorado-port-traffic-history-v2:'.hash('sha256', json_encode([
            config('akvorado-flow.url'), $device->device_id, $port->port_id, $ip, $name, $from, $to,
        ], JSON_THROW_ON_ERROR));
        return Cache::remember($key, (int)config('akvorado-flow.cache_seconds', 60),
            function () use ($port, $ip, $name, $start, $end, $from, $to): array {
                $snmp = $this->snmp($port, $from, $to);
                $points = max(72, min(360, count($snmp['timestamps'] ?? []) * 2));
                $flows = [];
                if ($ip !== null && FlowQuery::validInterfaceName($name)) {
                    foreach (array_keys(self::DIRECTIONS) as $direction) {
                        $flows[$direction] = $this->flows($ip, $name, $direction, $start, $end, $points);
                    }
                }

                return ['snmp' => $snmp, 'flows' => $flows];
            });
    }

    private function snmp(Port $port, int $from, int $to): array
    {
        if (! class_exists(RrdSeriesReader::class) || ! method_exists($port, 'metricIdentity')) {
            return [];
        }

        try {
            $metric = $port->metricIdentity('port');
            $series = [];
            foreach (self::DIRECTIONS as $direction => $field) {
                $series[$direction] = new DataSeries(
                    metric: $metric, field: $field, description: $direction, unit: 'bps', scale: 8
                );
            }
            $data = app(RrdSeriesReader::class)->read($series, $from, $to, 144);

            return ['timestamps' => $data->timestamps, 'series' => $data->series];
        } catch (\Throwable $e) {
            Log::warning('Akvorado plugin: port SNMP history unavailable', ['exception_type' => get_class($e)]);
            return [];
        }
    }

    private function flows(string $ip, string $name, string $direction, DateTimeImmutable $start, DateTimeImmutable $end, int $points): array
    {
        try {
            $query = FlowQuery::line($ip, $name, 60, $end, 'l2bps', $direction, start: $start);
            $query['points'] = $points;

            return self::flowBuckets($this->client->post('graph/line', $query), $start->getTimestamp(), $end->getTimestamp());
        } catch (\Throwable $e) {
            Log::warning('Akvorado plugin: historical flow query failed', [
                'direction' => $direction, 'exception_type' => get_class($e),
            ]);
            return [];
        }
    }

    /** Akvorado timestamps describe bucket starts. Keep only complete intervals. */
    public static function flowBuckets(array $payload, int $from, int $to): array
    {
        $times = $payload['t'] ?? null;
        $samples = $payload['points'][0] ?? null;
        if (! is_array($times) || ! is_array($samples) || array_keys($times) !== array_keys($samples)
            || count($payload['points']) !== 1 || (int)($payload['axis'][0] ?? 1) !== 1) {
            return [];
        }
        $times = array_map(FlowFormatter::unixTime(...), $times);
        $step = FlowFormatter::bucketStep($times);
        if ($step === null) {
            return [];
        }
        $rates = [];
        foreach ($times as $index => $timestamp) {
            if ($timestamp >= $from && $timestamp + $step <= $to) {
                $rates[$timestamp] = self::rate($samples[$index]);
            }
        }

        return ['origin' => $times[0], 'step' => $step, 'rates' => $rates];
    }

    /** RRD timestamps describe interval ends. Use the same interval for both rate estimates. */
    public static function chart(array $snmp, array $flows, int $from, int $to): array
    {
        return self::charts([['snmp' => $snmp, 'flows' => $flows]], $from, $to);
    }

    public static function comparisonChart(array $ports, int $from, int $to): array
    {
        return self::charts($ports, $from, $to);
    }

    /** Convert each source to interval starts before choosing the shared grid. */
    private static function buckets(array $snmp, array $flows, int $from, int $to): array
    {
        $times = $snmp['timestamps'] ?? [];
        $step = FlowFormatter::bucketStep($times);
        $sources = [];
        foreach (array_keys(self::DIRECTIONS) as $direction) {
            $rrd = [];
            if ($step !== null) {
                $rates = [];
                foreach ($times as $index => $end) {
                    if ($end - $step >= $from && $end <= $to) {
                        $rates[$end - $step] = self::rate($snmp['series'][$direction]['avg'][$index] ?? null);
                    }
                }
                $rrd = ['origin' => $times[0] - $step, 'step' => $step, 'rates' => $rates];
            }
            $sources[$direction] = ['snmp' => $rrd, 'flow' => $flows[$direction] ?? []];
        }

        return $sources;
    }

    private static function charts(array $ports, int $from, int $to): array
    {
        $sources = array_map(static fn ($port) => self::buckets($port['snmp'], $port['flows'], $from, $to), $ports);
        $gridSources = $sources;
        ksort($gridSources); // Swapping the selected ports changes the display order, not the grid.
        $grid = [];
        foreach ($gridSources as $port) {
            foreach (['snmp', 'flow'] as $type) {
                foreach ($port as $direction) {
                    $source = $direction[$type];
                    if (($source['step'] ?? 0) > ($grid['step'] ?? 0)) {
                        $grid = $source;
                    }
                }
            }
        }
        if ($grid === []) {
            return ['step' => null, 'charts' => []];
        }

        $step = $grid['step'];
        $origin = $grid['origin'];
        $ends = [];
        $first = $origin + (int)ceil(($from + $step - $origin) / $step) * $step;
        for ($end = $first; $end <= $to; $end += $step) {
            $ends[] = $end;
        }
        $labels = array_map(static fn (int $end) => gmdate('Y-m-d H:i:s', $end).' UTC', $ends);
        $charts = [];
        $comparison = count($ports) === 2;
        foreach (array_keys(self::DIRECTIONS) as $direction) {
            $series = [];
            $valuesByType = ['snmp' => [], 'flow' => []];
            $colorIndex = 0;
            foreach ($sources as $id => $port) {
                foreach (['snmp' => 'LibreNMS SNMP', 'flow' => 'Akvorado L2'] as $type => $label) {
                    $values = array_map(static fn ($end) => self::intervalMean($port[$direction][$type], $end - $step, $end), $ends);
                    $valuesByType[$type][] = $values;
                    $series[] = [
                        'label' => $comparison ? $ports[$id]['label'].' · '.$label : $label,
                        'values' => $values,
                        'dashed' => $type === 'flow',
                    ] + ($comparison ? ['colorIndex' => $colorIndex] : []);
                }
                $colorIndex += 2;
            }

            $charts[$direction] = [
                'labels' => $labels,
                'timestamps' => $ends,
                'unit' => 'l2bps',
                'lines' => true,
                'series' => $series,
                'hasSnmp' => self::hasRate(array_merge(...$valuesByType['snmp'])),
                'hasFlow' => self::hasRate(array_merge(...$valuesByType['flow'])),
            ] + ($comparison ? ['averages' => array_map(static fn ($values) => self::matchedAverages(...$values), $valuesByType)] : []);
        }

        return ['step' => $step, 'charts' => $charts];
    }

    private static function matchedAverages(array $first, array $second): ?array
    {
        $count = 0;
        $left = 0.0;
        $right = 0.0;
        foreach ($first as $index => $rate) {
            if ($rate !== null && $second[$index] !== null) {
                $left += $rate;
                $right += $second[$index];
                $count++;
            }
        }
        if ($count === 0) {
            return null;
        }
        $total = $left + $right;

        return ['count' => $count, 'rates' => [$left / $count, $right / $count],
            'shares' => $total > 0 ? [100 * $left / $total, 100 * $right / $total] : null];
    }

    private static function intervalMean(array $series, int $from, int $end): ?float
    {
        if ($series === []) {
            return null;
        }
        $step = $series['step'];
        $origin = $series['origin'];
        $first = $origin + (int)floor(($from - $origin) / $step) * $step;
        $total = 0.0;
        for ($slot = $first; $slot < $end; $slot += $step) {
            $rate = $series['rates'][$slot] ?? null;
            if ($rate === null) {
                return null;
            }
            $seconds = min($end, $slot + $step) - max($from, $slot);
            $total += $rate * $seconds;
        }

        return $total / ($end - $from);
    }

    private static function rate(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float)$value) && (float)$value >= 0 ? (float)$value : null;
    }

    private static function hasRate(array $rates): bool
    {
        foreach ($rates as $rate) {
            if ($rate !== null) {
                return true;
            }
        }
        return false;
    }
}
