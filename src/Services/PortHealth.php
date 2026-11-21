<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

/** Existing LibreNMS IF-MIB counters; no additional SNMP requests. */
final class PortHealth
{
    public static function snapshot(object $port): ?array
    {
        try {
            $stats = $port->statistics()->first();
            if ($stats === null) { return null; }
            return [
                'inDiscardsRate' => self::number($stats->ifInDiscards_rate ?? null),
                'outDiscardsRate' => self::number($stats->ifOutDiscards_rate ?? null),
                'inDiscardsCounter' => self::number($stats->ifInDiscards ?? null),
                'outDiscardsCounter' => self::number($stats->ifOutDiscards ?? null),
                'inErrorsRate' => self::number($port->ifInErrors_rate ?? null),
                'outErrorsRate' => self::number($port->ifOutErrors_rate ?? null),
            ];
        } catch (\Throwable) {
            // Statistics availability varies by host's schema and polling mode.
            return null;
        }
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }
}
