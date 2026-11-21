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
        $key = 'akvorado-flow-diagnostics-v1:'.hash('sha256', json_encode([
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
        foreach (['ingress' => 'InIfName', 'egress' => 'OutIfName'] as $direction => $field) {
            $unknown = $this->series(array_replace($query, ['filter' => $query['filter'].' AND '.$field.' = ""']));
            $shares[$direction] = $unknown !== null
                && $unknown['times'] === $baseline['times']
                && $unknown['sum'] <= $baseline['sum']
                ? 100 * $unknown['sum'] / $baseline['sum']
                : null;
        }

        return ['state' => 'observed', 'lastBucket' => $baseline['lastBucket'], 'shares' => $shares];
    }

    /** Identical query windows and time grids let summed L3 rates measure relative volume. */
    private function series(array $query): ?array
    {
        try {
            $payload = $this->client->post('graph/line', $query);
            $times = $payload['t'] ?? null;
            $points = $payload['points'] ?? null;
            if (! is_array($times) || ! is_array($points)) {
                return null;
            }
            if ($points === []) {
                return ['sum' => 0.0, 'times' => $times, 'lastBucket' => null];
            }
            $rates = $points[0] ?? null;
            if (count($points) !== 1 || ! is_array($rates) || array_keys($times) !== array_keys($rates)
                || (int) ($payload['axis'][0] ?? 1) !== 1) {
                return null;
            }

            $sum = 0.0;
            $lastBucket = null;
            foreach ($rates as $index => $rate) {
                if (! is_numeric($rate) || ! is_finite((float) $rate) || (float) $rate < 0
                    || ! is_string($times[$index]) || strtotime($times[$index]) === false) {
                    return null;
                }
                $sum += (float) $rate;
                if ((float) $rate > 0) {
                    $lastBucket = $times[$index];
                }
            }

            return ['sum' => $sum, 'times' => $times, 'lastBucket' => $lastBucket];
        } catch (\Throwable $e) {
            Log::warning('Akvorado plugin: exporter diagnostics query failed', ['exception_type' => get_class($e)]);
            return null;
        }
    }
}
