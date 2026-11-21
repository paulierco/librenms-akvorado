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
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $end = $bounds['end'] ?? $now->setTime((int)$now->format('H'), (int)$now->format('i'));
        $start = $bounds['start'] ?? $end->modify('-'.$minutes.' minutes');
        $from = $start->getTimestamp();
        $to = $end->getTimestamp();
        $exporter = $this->resolver->resolve($device);
        $ip = $exporter['ip'] ?? null;
        $name = (string)$port->ifName;

        $key = 'akvorado-port-traffic-history-v1:'.hash('sha256', json_encode([
            config('akvorado-flow.url'), $device->device_id, $port->port_id, $ip, $name, $from, $to,
        ], JSON_THROW_ON_ERROR));
        $history = Cache::remember($key, (int)config('akvorado-flow.cache_seconds', 60),
            function () use ($port, $ip, $name, $start, $end, $from, $to): array {
                $snmp = $this->snmp($port, $from, $to);
                $points = max(72, min(360, count($snmp['timestamps'] ?? []) * 2));
                $flows = [];
                if ($ip !== null && FlowQuery::validInterfaceName($name)) {
                    foreach (array_keys(self::DIRECTIONS) as $direction) {
                        $flows[$direction] = $this->flows($ip, $name, $direction, $start, $end, $points);
                    }
                }

                return self::chart($snmp, $flows, $from, $to);
            });

        return $history + ['start' => $from, 'end' => $to, 'exporterMapped' => $ip !== null];
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
        $times = $snmp['timestamps'] ?? [];
        $snmpStep = FlowFormatter::bucketStep($times);
        $steps = array_filter([$snmpStep, $flows['in']['step'] ?? null, $flows['out']['step'] ?? null]);
        if ($steps === []) {
            return ['step' => null, 'charts' => []];
        }
        $step = max($steps);
        $origin = match (true) {
            $snmpStep === $step => $times[0],
            ($flows['in']['step'] ?? null) === $step => $flows['in']['origin'],
            default => $flows['out']['origin'],
        };

        $ends = [];
        $first = $origin + (int)ceil(($from + $step - $origin) / $step) * $step;
        for ($end = $first; $end <= $to; $end += $step) {
            $ends[] = $end;
        }
        $labels = array_map(static fn (int $end) => gmdate('Y-m-d H:i:s', $end).' UTC', $ends);
        $charts = [];
        foreach (array_keys(self::DIRECTIONS) as $direction) {
            $rrd = [];
            if ($snmpStep !== null) {
                $rates = [];
                foreach ($times as $index => $end) {
                    if ($end - $snmpStep >= $from && $end <= $to) {
                        $rates[$end - $snmpStep] = self::rate($snmp['series'][$direction]['avg'][$index] ?? null);
                    }
                }
                $rrd = ['origin' => $times[0] - $snmpStep, 'step' => $snmpStep, 'rates' => $rates];
            }
            $snmpValues = [];
            $flowValues = [];
            foreach ($ends as $end) {
                $snmpValues[] = self::intervalMean($rrd, $end - $step, $end);
                $flowValues[] = self::intervalMean($flows[$direction] ?? [], $end - $step, $end);
            }
            $charts[$direction] = [
                'labels' => $labels,
                'timestamps' => $ends,
                'unit' => 'l2bps',
                'lines' => true,
                'series' => [
                    ['label' => 'LibreNMS SNMP', 'values' => $snmpValues],
                    ['label' => 'Akvorado L2', 'values' => $flowValues, 'dashed' => true],
                ],
                'hasSnmp' => self::hasRate($snmpValues),
                'hasFlow' => self::hasRate($flowValues),
            ];
        }

        return ['step' => $step, 'charts' => $charts];
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
