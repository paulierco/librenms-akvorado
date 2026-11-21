<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use App\Models\Device;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

final class PortVisibility
{
    public function __construct(
        private readonly ExporterResolver $resolver,
        private readonly AkvoradoClient $client,
    ) {}

    public function forDevice(Device $device, User $viewer, int $minutes, ?array $bounds): array
    {
        $ports = $device->ports()->hasAccess($viewer)->where('deleted', 0)
            ->orderBy('ifName')->orderBy('port_id')->simplePaginate(25, [
                'port_id', 'ifIndex', 'ifName', 'ifDescr', 'ifAlias', 'poll_time', 'ifInOctets_rate', 'ifOutOctets_rate',
            ]);
        $names = $ports->getCollection()->pluck('ifName')->uniqueStrict()
            ->filter(static fn ($name) => FlowQuery::validInterfaceName($name) && $name !== 'Other')->values()->all();
        // An enriched ifName cannot distinguish two ports sharing a name on the same exporter.
        $duplicates = $names === [] ? [] : $device->ports()->where('deleted', 0)->whereIn('ifName', $names)
            ->pluck('ifName')->countBy()->filter(static fn (int $count) => $count > 1)->keys()->all();
        $names = array_values(array_diff($names, $duplicates));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $end = $bounds['end'] ?? $now->setTime((int)$now->format('H'), (int)$now->format('i'));
        $start = $bounds['start'] ?? $end->modify('-'.$minutes.' minutes');

        $exporter = $this->resolver->resolve($device);
        $observations = [];
        if ($exporter !== null && $names !== []) {
            $observations = $this->observe($exporter['ip'], $names, $start, $end);
        }

        return [
            'ports' => $ports,
            'names' => array_fill_keys($names, true),
            'duplicates' => array_fill_keys($duplicates, true),
            'exporter' => $exporter,
            'observations' => $observations,
            'start' => $start,
            'end' => $end,
        ];
    }

    private function observe(string $ip, array $names, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $key = 'akvorado-port-visibility-v1:'.hash('sha256', json_encode([
            config('akvorado-flow.url'), $ip, $names, $start->getTimestamp(), $end->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
        return Cache::remember($key, (int)config('akvorado-flow.cache_seconds', 60),
            function () use ($ip, $names, $start, $end): array {
                $observations = [];
                foreach (['ingress', 'egress'] as $direction) {
                    try {
                        $query = FlowQuery::interfaceVisibility($ip, $names, $direction, $start, $end);
                        $observations[$direction] = self::reported($this->client->post('graph/line', $query), $names);
                    } catch (\Throwable $e) {
                        $observations[$direction] = [];
                        Log::warning('Akvorado plugin: port visibility check failed', [
                            'direction' => $direction, 'exception_type' => get_class($e),
                        ]);
                    }
                }
                return $observations;
            });
    }

    /** @return array<string,bool> Only confirmed observations and confirmed empty results. */
    public static function reported(array $payload, array $names): array
    {
        $rows = $payload['rows'] ?? null;
        $points = $payload['points'] ?? null;
        $times = $payload['t'] ?? null;
        if (! is_array($rows) || ! is_array($points) || ! is_array($times)
            || array_keys($rows) !== array_keys($points) || ($rows !== [] && $times === [])) {
            throw new UnexpectedValueException('Unusable interface visibility response');
        }

        $requested = array_fill_keys($names, false);
        $reported = [];
        $remainder = false;
        foreach ($rows as $i => $row) {
            if (! is_array($row) || count($row) !== 1 || ! is_string($row[0] ?? null)
                || ! is_array($points[$i]) || count($points[$i]) !== count($times)
                || (int)($payload['axis'][$i] ?? 1) !== 1) {
                throw new UnexpectedValueException('Unusable interface visibility series');
            }
            $positive = false;
            foreach ($points[$i] as $point) {
                if (! is_numeric($point) || ! is_finite((float)$point) || (float)$point < 0) {
                    throw new UnexpectedValueException('Unusable interface visibility sample');
                }
                $positive = $positive || (float)$point > 0;
            }
            if (array_key_exists($row[0], $requested)) {
                $reported[$row[0]] = ($reported[$row[0]] ?? false) || $positive;
            } else {
                // A positive Other series makes unreturned names indeterminate.
                $remainder = $remainder || $positive;
            }
        }

        return $remainder ? $reported : $reported + $requested;
    }

    public static function snmpRate(mixed $octetsPerSecond): ?float
    {
        $rate = is_numeric($octetsPerSecond) ? (float)$octetsPerSecond * 8 : -1;

        return is_finite($rate) && $rate >= 0 ? $rate : null;
    }
}
