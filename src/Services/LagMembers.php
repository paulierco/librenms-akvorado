<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use Illuminate\Support\Facades\Log;

/**
 * Read-only LAG mapping from LibreNMS's discovered IF-MIB ifStack relationships.
 * This never guesses membership from an interface name, alias, or ifIndex range.
 */
final class LagMembers
{
    public const MAX_MEMBERS = 12;

    /**
     * @return list<array{port_id:int,ifIndex:int,ifName:string,membership?:string}>
     */
    public static function forPort(object $port): array
    {
        $deviceId = (int) ($port->device_id ?? 0);
        $portId = (int) ($port->port_id ?? 0);
        if ($deviceId <= 0 || $portId <= 0) {
            return [];
        }

        try {
            // In IF-MIB, high is the aggregate/logical interface, low a member.
            // Some devices do not expose ifStack entries for LACP: return [] rather than guessing.
            $relationships = \App\Models\PortStack::query()
                ->where('device_id', $deviceId)
                ->where('high_port_id', $portId)
                ->whereIn('ifStackStatus', ['active', '1'])
                ->limit(self::MAX_MEMBERS + 1)
                ->get(['low_port_id']);

            if ($relationships->count() > self::MAX_MEMBERS) {
                return []; // Cannot safely construct an incomplete LAG filter.
            }
            $ids = $relationships->pluck('low_port_id')
                ->filter(static fn ($id) => (int) $id > 0 && (int) $id !== $portId)
                ->unique()->values()->all();
            if ($ids === []) {
                // No scheduled collector exists, so membership is simply unknown here.
                // Never substitute a previously written mapping: it would be stale fact.
                return [];
            }
            $members = \App\Models\Port::query()
                ->hasAccess(auth()->user())
                ->where('device_id', $deviceId)
                ->whereIn('port_id', $ids)
                ->where('deleted', 0)
                ->get(['port_id', 'device_id', 'ifIndex', 'ifName']);

            // Only use a complete, authorized set. Partial membership would mislead.
            if ($members->count() !== count($ids)) {
                return [];
            }
            return self::normalize($members->all(), $portId);
        } catch (\Throwable $e) {
            Log::warning('Akvorado plugin: LAG membership lookup unavailable', [
                'exception_type' => get_class($e),
            ]);
            return [];
        }
    }

    /**
     * Pure, tested normalizer. Returns no members on incomplete or duplicate mapping.
     * @param list<object|array<string,mixed>> $ports
     * @return list<array{port_id:int,ifIndex:int,ifName:string}>
     */
    public static function normalize(array $ports, int $parentId): array
    {
        $result = [];
        $seenNames = [];
        foreach ($ports as $port) {
            $item = is_array($port) ? $port : [
                'port_id' => $port->port_id ?? null,
                'ifIndex' => $port->ifIndex ?? null,
                'ifName' => $port->ifName ?? null,
            ];
            $id = (int) ($item['port_id'] ?? 0);
            $index = (int) ($item['ifIndex'] ?? 0);
            $name = (string) ($item['ifName'] ?? '');
            if ($id <= 0 || $id === $parentId || $index <= 0
                || $name === '' || strlen($name) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $name) || isset($seenNames[$name])) {
                return [];
            }
            $seenNames[$name] = true;
            $result[] = ['port_id' => $id, 'ifIndex' => $index, 'ifName' => $name];
        }
        if (count($result) > self::MAX_MEMBERS) {
            return [];
        }
        usort($result, static fn ($a, $b) => strnatcmp($a['ifName'], $b['ifName']));
        return $result;
    }
}
