<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

/**
 * Safe metadata for LibreNMS's existing, authorized port graph endpoint.
 * No direct rrdtool invocation, API token, raw RRD path, or SNMP polling.
 * Each graph request is authenticated and authorized by LibreNMS itself.
 */
final class SnmpHistory
{
    /** @return array<string,mixed>|null */
    public static function forPort(object $port, int $from, int $to): ?array
    {
        $id = filter_var($port->port_id ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ]);
        if ($id === false || $id === null || $from <= 0 || $to <= $from || $to - $from > 10080 * 60 || $to > time() + 300) {
            return null;
        }
        $query = static fn (string $type): array => [
            'type' => $type,
            'id' => (int) $id,
            'from' => $from,
            'to' => $to,
            'width' => 1080,
            'height' => 270,
            'legend' => 'yes',
        ];

        $speed = self::speedBps($port);
        $in = self::percentOfSpeed($port->ifInOctets_rate ?? null, $speed);
        $out = self::percentOfSpeed($port->ifOutOctets_rate ?? null, $speed);

        return [
            'portId' => (int) $id,
            'start' => $from,
            'end' => $to,
            'startUtc' => gmdate('Y-m-d H:i', $from),
            'endUtc' => gmdate('Y-m-d H:i', $to),
            'graphs' => [
                // Canonical graph types already used by LibreNMS's own ports UI.
                'traffic' => $query('port_bits'),
                // Standard LibreNMS errors graph contains errors and discards.
                'errorsDiscards' => $query('port_errors'),
                'packets' => $query('port_upkts'),
            ],
            'speedBps' => $speed,
            // These are *latest polled* rates, not averages over the graph window.
            'currentUtilizationIn' => $in,
            'currentUtilizationOut' => $out,
            'currentInBps' => self::bytesToBits($port->ifInOctets_rate ?? null),
            'currentOutBps' => self::bytesToBits($port->ifOutOctets_rate ?? null),
        ];
    }

    public static function speedBps(object $port): ?float
    {
        $raw = self::nonNegative($port->ifSpeed ?? null);
        $highMbps = self::nonNegative($port->ifHighSpeed ?? null);
        $high = $highMbps !== null && $highMbps > 0 ? $highMbps * 1000000 : null;
        // ifSpeed can saturate at 2^32-1. Prefer ifHighSpeed in that case.
        if ($high !== null && ($raw === null || $raw <= 0 || $raw >= 4294967295 || $high > $raw * 1.1)) {
            return $high;
        }
        return $raw !== null && $raw > 0 ? $raw : $high;
    }

    public static function percentOfSpeed(mixed $octetsRate, ?float $speedBps): ?float
    {
        $rate = self::nonNegative($octetsRate);
        if ($rate === null || $speedBps === null || $speedBps <= 0) {
            return null;
        }
        return round($rate * 8 / $speedBps * 100, 2);
    }

    private static function bytesToBits(mixed $octetsRate): ?float
    {
        $rate = self::nonNegative($octetsRate);
        return $rate === null ? null : $rate * 8;
    }

    private static function nonNegative(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
    }
}
