<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class PeriodComparison
{
    public function __construct(private readonly AkvoradoClient $client) {}

    /** Compare with the immediately preceding window of exactly the same duration. */
    public function forPanel(array $panel, string $ip, string|array|null $interfaces, int $minutes, string $metric, array $focus, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $duration = $end->getTimestamp() - $start->getTimestamp();
        $previousStart = $start->setTimestamp($start->getTimestamp() - $duration);
        $currentCurves = array_filter([
            'both' => $panel['traffic'] ?? null,
            'in' => $panel['trafficIn'] ?? null,
            'out' => $panel['trafficOut'] ?? null,
        ], static fn ($curve) => ! empty($curve['samples']));
        $directions = array_keys($currentCurves);

        $key = 'akvorado-period-comparison-v1:'.hash('sha256', json_encode([
            config('akvorado-flow.url'), $ip, $interfaces, $metric, $focus, $directions,
            $previousStart->getTimestamp(), $start->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
        $previous = Cache::remember($key, (int) config('akvorado-flow.cache_seconds', 60),
            function () use ($ip, $interfaces, $minutes, $metric, $focus, $directions, $previousStart, $start): array {
                $curves = [];
                foreach ($directions as $direction) {
                    $query = FlowQuery::line($ip, $interfaces, $minutes, $start, $metric, $direction, focus: $focus, start: $previousStart);
                    $payload = $this->query('graph/line', $query);
                    $curve = $payload === null ? null : FlowFormatter::line($payload, $metric, $minutes);
                    $curves[$direction] = empty($curve['samples']) ? null : $curve;
                }
                $query = FlowQuery::sankey($ip, $interfaces, $minutes, $start, ['SrcAddr', 'DstAddr'], 24, $focus, $previousStart);
                $payload = $this->query('graph/sankey', $query);

                return [
                    'curves' => $curves,
                    'conversations' => is_array($payload['rows'] ?? null) ? FlowFormatter::conversations($payload, 24) : null,
                ];
            });

        $labels = ['both' => 'Previous period', 'in' => 'Previous ingress', 'out' => 'Previous egress'];
        $axis = [];
        foreach ($currentCurves as $curve) {
            if (count($curve['plotTimestamps'] ?? []) >= 2) {
                $axis = $curve['plotTimestamps'];
                break;
            }
        }
        $series = [];
        $unavailable = [];
        $observed = false;
        foreach ($previous['curves'] as $direction => $curve) {
            $values = $curve === null ? [] : self::align($axis, $curve, $duration, $previousStart->getTimestamp(), $start->getTimestamp());
            if ($curve === null || ($axis !== [] && count(array_filter($values, static fn ($value) => $value !== null)) < 2)) {
                $unavailable[] = $labels[$direction];
                continue;
            }
            $observed = $observed || max($curve['samples']) > 0;
            if ($axis !== []) {
                $series[] = ['label' => $labels[$direction], 'values' => $values, 'previous' => true];
            }
        }
        $increases = $previous['conversations'] !== null && ! in_array('Conversations unavailable', $panel['partial'] ?? [], true)
            ? self::increases($panel['conversations'] ?? [], $previous['conversations'])
            : null;

        return [
            'start' => $previousStart->format('Y-m-d H:i:s'),
            'end' => $start->format('Y-m-d H:i:s'),
            'series' => $series,
            'observed' => $observed,
            'unavailable' => $unavailable,
            'increases' => $increases,
        ];
    }

    /** Align comparable bucket starts after shifting by the window duration. */
    public static function align(array $axis, array $curve, int $duration, int $start, int $end): array
    {
        $stamps = $curve['timestamps'] ?? [];
        $origin = $stamps[0] ?? null;
        $step = ($stamps[1] ?? 0) - (int) $origin;
        if ($origin === null || $step <= 0 || ($axis[1] ?? 0) - ($axis[0] ?? 0) !== $step) {
            return [];
        }
        $rates = [];
        foreach ($stamps as $index => $stamp) {
            if (is_int($stamp) && $stamp >= $start && $stamp + $step <= $end && isset($curve['samples'][$index])) {
                $rates[$stamp] = $curve['samples'][$index];
            }
        }

        return array_map(static function (?int $stamp) use ($rates, $origin, $duration, $step): ?float {
            if ($stamp === null) {
                return null;
            }
            $bucket = $origin + (int) round(($stamp - $duration - $origin) / $step) * $step;
            return $rates[$bucket] ?? null;
        }, $axis);
    }

    /** Compare actual IP pairs that appear in both rankings and omit the aggregate remainder. */
    public static function increases(array $current, array $previous): array
    {
        $key = static function (array $row): ?string {
            $source = @inet_pton($row['source']);
            $destination = @inet_pton($row['destination']);
            if ($source === false || $destination === false
                || trim($source, "\0") === '' || trim($destination, "\0") === '') {
                return null;
            }
            return bin2hex($source).':'.bin2hex($destination);
        };
        $previousRates = [];
        foreach ($previous as $row) {
            $id = $key($row);
            if ($id !== null && is_finite($row['bps'])) {
                $previousRates[$id] = $row['bps'];
            }
        }

        $increases = [];
        foreach ($current as $row) {
            $id = $key($row);
            $previousRate = $id === null ? null : ($previousRates[$id] ?? null);
            if ($previousRate !== null && is_finite($row['bps']) && $row['bps'] > $previousRate) {
                $increases[] = $row + ['previousBps' => $previousRate, 'increaseBps' => $row['bps'] - $previousRate];
            }
        }
        usort($increases, static fn ($a, $b) => $b['increaseBps'] <=> $a['increaseBps']);

        return array_slice($increases, 0, 8);
    }

    private function query(string $endpoint, array $payload): ?array
    {
        try {
            return $this->client->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('Akvorado plugin: previous period query failed', ['exception_type' => get_class($e)]);
            return null;
        }
    }
}
