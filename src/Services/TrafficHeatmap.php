<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use App\Models\Device;
use App\Models\Port;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class TrafficHeatmap
{
    public const LOOKBACKS = [1 => 'Last 24 hours', 7 => 'Last 7 days', 14 => 'Last 14 days', 28 => 'Last 28 days'];
    public const DIRECTIONS = ['both' => 'Ingress or egress', 'in' => 'Ingress', 'out' => 'Egress'];

    private const HOUR = 3600;

    public function __construct(private readonly ExporterResolver $resolver, private readonly AkvoradoClient $client) {}

    public static function lookback(mixed $value): int
    {
        return (is_string($value) || is_int($value)) && isset(self::LOOKBACKS[$value]) ? (int)$value : 7;
    }

    public static function direction(mixed $value): string
    {
        return is_string($value) && isset(self::DIRECTIONS[$value]) ? $value : 'both';
    }

    public function forScope(Device $device, ?Port $port, int $days, string $direction, ?array $bounds): array
    {
        $lastComplete = intdiv(time(), self::HOUR) * self::HOUR;
        $from = $bounds === null ? $lastComplete - $days * 86400 : $bounds['start']->getTimestamp();
        $to = $bounds === null ? $lastComplete : min($bounds['end']->getTimestamp(), $lastComplete);
        $from = (int)ceil($from / self::HOUR) * self::HOUR;
        $to = intdiv($to, self::HOUR) * self::HOUR;
        $scope = ['start' => $from, 'end' => $to];
        if ($from >= $to) {
            return $scope + ['state' => 'no_complete_hours'];
        }
        $ip = $this->resolver->resolve($device)['ip'] ?? null;
        if ($ip === null) {
            return $scope + ['state' => 'unmapped'];
        }
        $name = $port === null ? null : (string)$port->ifName;
        if ($name !== null && (! FlowQuery::validInterfaceName($name)
            || $device->ports()->where('ifName', $name)->pluck('ifName')->filter(static fn ($value) => $value === $name)->count() !== 1)) {
            return $scope + ['state' => 'interface_unavailable'];
        }
        $query = FlowQuery::line($ip, $name, 60, new DateTimeImmutable('@'.$to), 'l3bps',
            $port === null ? 'both' : self::direction($direction), start: new DateTimeImmutable('@'.$from));
        $query['points'] = max(5, min(2000, intdiv($to - $from, self::HOUR) * 4));
        $key = 'akvorado-traffic-heatmap-v1:'.hash('sha256', json_encode([config('akvorado-flow.url'), $query], JSON_THROW_ON_ERROR));

        return $scope + Cache::remember($key, (int)config('akvorado-flow.cache_seconds', 60), function () use ($query, $from, $to): array {
            try {
                return self::grid($this->client->post('graph/line', $query), $from, $to);
            } catch (\Throwable $e) {
                Log::warning('Akvorado plugin: traffic heatmap query failed', ['exception_type' => get_class($e)]);
                return ['state' => 'query_failed'];
            }
        });
    }

    /** Calendar rows by UTC date, with one cell per complete hour. */
    public static function grid(array $payload, int $from, int $to): array
    {
        if (! is_array($payload['t'] ?? null) || ! is_array($payload['points'] ?? null)) {
            return ['state' => 'query_failed'];
        }
        $empty = $payload['points'] === [];
        $buckets = $empty ? [] : PortTrafficHistory::flowBuckets($payload, $from, $to);
        if (! $empty && ($buckets === [] || $buckets['step'] > self::HOUR)) {
            return ['state' => 'resolution_unavailable'];
        }
        $rows = [];
        $highest = 0.0;
        $observed = 0;
        $available = 0;
        for ($hour = $from; $hour + self::HOUR <= $to; $hour += self::HOUR) {
            $date = gmdate('Y-m-d', $hour);
            $column = (int)gmdate('G', $hour);
            $stats = $empty ? ['mean' => 0.0, 'peak' => 0.0] : FlowFormatter::intervalStats($buckets, $hour, $hour + self::HOUR);
            $rows[$date][$column] = ['start' => $hour, 'stats' => $stats];
            if ($stats !== null) {
                $available++;
                $highest = max($highest, $stats['mean']);
                $observed += (int)($stats['mean'] > 0.0);
            }
        }

        return [
            'state' => $available === 0 ? 'incomplete' : ($observed === 0 ? 'no_samples' : 'observed'),
            'rows' => $rows, 'highest' => $highest, 'observed' => $observed,
            'hours' => intdiv($to - $from, self::HOUR), 'interval' => $buckets['step'] ?? null,
        ];
    }
}
