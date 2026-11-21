<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class PeriodComparison
{
    public const INCREASE_LIMIT = 8;
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

        $key = 'akvorado-period-comparison-v2:'.hash('sha256', json_encode([
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
                $query = FlowQuery::sankey($ip, $interfaces, $minutes, $start, ['SrcAddr', 'DstAddr'], FlowQuery::CONVERSATION_RANK_LIMIT, $focus, $previousStart);
                $payload = $this->query('graph/sankey', $query);

                return [
                    'curves' => $curves,
                    'conversations' => FlowFormatter::conversationRows($payload ?? []) === null
                        ? null : FlowFormatter::conversations($payload, FlowQuery::CONVERSATION_RANK_LIMIT),
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
        $increases = null;
        $conversationChanges = null;
        if ($previous['conversations'] !== null && ! in_array('Conversations unavailable', $panel['partial'] ?? [], true)) {
            $currentPairs = self::rankedPairs($panel['conversations'] ?? []);
            $previousPairs = self::rankedPairs($previous['conversations']);
            $increases = self::rankedIncreases($currentPairs, $previousPairs);
            $conversationChanges = [
                ...$this->recheck($currentPairs, $previousPairs, 'current', $ip, $interfaces, $focus, $previousStart, $start),
                ...$this->recheck($previousPairs, $currentPairs, 'previous', $ip, $interfaces, $focus, $start, $end),
            ];
        }

        return [
            'start' => $previousStart->format('Y-m-d H:i:s'),
            'end' => $start->format('Y-m-d H:i:s'),
            'series' => $series,
            'observed' => $observed,
            'unavailable' => $unavailable,
            'increases' => $increases,
            'conversationChanges' => $conversationChanges,
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
        return self::rankedIncreases(self::rankedPairs($current), self::rankedPairs($previous));
    }

    private static function rankedIncreases(array $current, array $previous): array
    {
        $increases = [];
        foreach ($current as $id => $row) {
            $previousRate = $previous[$id]['bps'] ?? null;
            if ($previousRate !== null && $row['bps'] > $previousRate) {
                $increases[] = $row + ['previousBps' => $previousRate, 'increaseBps' => $row['bps'] - $previousRate];
            }
        }
        usort($increases, static fn ($a, $b) => $b['increaseBps'] <=> $a['increaseBps']);

        return array_slice($increases, 0, self::INCREASE_LIMIT);
    }

    private static function rankedPairs(array $ranking): array
    {
        $pairs = [];
        foreach ($ranking as $row) {
            $id = self::pairKey($row['source'], $row['destination']);
            if ($id !== null && is_finite($row['bps']) && $row['bps'] > ($pairs[$id]['bps'] ?? 0.0)) {
                $pairs[$id] = $row;
            }
        }

        return $pairs;
    }

    /** Match address spellings used by different Console responses, including mapped IPv4. */
    private static function pairKey(mixed $source, mixed $destination): ?string
    {
        $addresses = [];
        foreach ([$source, $destination] as $address) {
            $packed = is_string($address) ? @inet_pton($address) : false;
            if ($packed === false || $packed === str_repeat("\0", strlen($packed))) {
                return null;
            }
            if (str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
                $packed = substr($packed, 12);
                if ($packed === "\0\0\0\0") {
                    return null;
                }
            }
            $addresses[] = bin2hex($packed);
        }

        return implode(':', $addresses);
    }

    private function recheck(array $ranked, array $other, string $window, string $ip, string|array|null $interfaces, array $focus, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        // The rankings are ordered by bitrate. Exclude shared pairs before applying the cap.
        $candidates = array_slice(array_diff_key($ranked, $other), 0, FlowQuery::CONVERSATION_RECHECK_LIMIT, true);
        if ($candidates === []) {
            return [];
        }

        $query = FlowQuery::conversationPresence($ip, $interfaces, $focus, $candidates, $start, $end);
        $key = 'akvorado-conversation-presence-v1:'.hash('sha256', json_encode([config('akvorado-flow.url'), $query], JSON_THROW_ON_ERROR));
        $result = Cache::remember($key, (int) config('akvorado-flow.cache_seconds', 60), function () use ($query, $candidates): array {
            return ['observed' => $this->observedPairs($this->query('graph/sankey', $query), $candidates)];
        });
        $observed = $result['observed'];
        $rows = [];
        foreach ($candidates as $id => $row) {
            $rows[] = $row + ['window' => $window, 'otherObserved' => $observed === null ? null : isset($observed[$id])];
        }

        return $rows;
    }

    /** A matching row establishes presence even when Akvorado rounds its rate to zero. */
    private function observedPairs(?array $payload, array $candidates): ?array
    {
        $rows = FlowFormatter::conversationRows($payload ?? []);
        if ($rows === null) {
            return null;
        }
        $observed = [];
        foreach ($rows as $row) {
            $id = self::pairKey($row[0], $row[1]);
            if ($id === null || ! isset($candidates[$id])) {
                return null;
            }
            $observed[$id] = true;
        }

        return $observed;
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
