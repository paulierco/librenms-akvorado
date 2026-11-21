<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use InvalidArgumentException;

/** Query builder; inputs are validated and no raw user filter reaches Akvorado. */
final class FlowQuery
{
    public static function filter(string $ip, string|array|null $interfaceName = null, string $direction = 'both', array $focus = []): string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) { throw new InvalidArgumentException('Invalid exporter address'); }
        $filter = 'ExporterAddress = '.$ip;
        if ($interfaceName !== null) {
            // Only names discovered through LibreNMS may enter multi-interface
            // mode. Each name is still validated/escaped independently.
            $names = is_array($interfaceName) ? $interfaceName : [$interfaceName];
            if (count($names) < 1 || count($names) > 13) {
                throw new InvalidArgumentException('Invalid interface count');
            }
            $quoted = self::quotedInterfaces($names);
            $in = array_map(static fn ($q) => 'InIfName = '.$q, $quoted);
            $out = array_map(static fn ($q) => 'OutIfName = '.$q, $quoted);
            $part = match ($direction) {
                'in' => count($in) === 1 ? $in[0] : '('.implode(' OR ', $in).')',
                'out' => count($out) === 1 ? $out[0] : '('.implode(' OR ', $out).')',
                'both' => '('.implode(' OR ', [...$in, ...$out]).')',
                default => throw new InvalidArgumentException('Invalid direction'),
            };
            $filter .= ' AND '.$part;
        }
        if ($interfaceName === null && $direction !== 'both') { throw new InvalidArgumentException('Direction requires port'); }
        foreach (self::focusClauses($focus) as $clause) {
            $filter .= ' AND '.$clause;
        }
        return $filter;
    }

    public static function validInterfaceName(mixed $name): bool
    {
        return is_string($name) && $name !== '' && strlen($name) <= 255
            && ! preg_match('/[\x00-\x1F\x7F]/', $name);
    }

    /** @return list<string> */
    private static function quotedInterfaces(array $names): array
    {
        $quoted = [];
        foreach ($names as $name) {
            if (! self::validInterfaceName($name)) {
                throw new InvalidArgumentException('Invalid interface name');
            }
            $quoted[] = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $name).'"';
        }
        if (count(array_unique($quoted)) !== count($quoted)) {
            throw new InvalidArgumentException('Duplicate interface name');
        }

        return $quoted;
    }

    /** Validate the only user-controlled traffic filters we support; never accept raw expressions. */
    public static function focus(array $input): array
    {
        $result = [];
        foreach (['src', 'dst', 'host'] as $field) {
            $value = $input[$field] ?? null;
            if ($value === null || $value === '') { continue; }
            if (! is_string($value) || strlen($value) > 45 || ! filter_var($value, FILTER_VALIDATE_IP)) {
                throw new InvalidArgumentException('Invalid '.$field.' IP address');
            }
            $result[$field] = $value;
        }
        foreach (['dst_port' => 65535, 'proto' => 255] as $field => $max) {
            $value = $input[$field] ?? null;
            if ($value === null || $value === '') { continue; }
            if (! is_string($value) && ! is_int($value)) { throw new InvalidArgumentException('Invalid numeric filter'); }
            $value = (string) $value;
            if (! preg_match('/\A[0-9]{1,5}\z/D', $value) || (int) $value > $max) {
                throw new InvalidArgumentException('Invalid numeric filter');
            }
            $result[$field] = (string) (int) $value;
        }
        return $result;
    }

    private static function focusClauses(array $focus): array
    {
        $safe = self::focus($focus);
        $parts = [];
        if (isset($safe['host'])) {
            $parts[] = '(SrcAddr = '.$safe['host'].' OR DstAddr = '.$safe['host'].')';
        }
        foreach (['src' => 'SrcAddr', 'dst' => 'DstAddr', 'dst_port' => 'DstPort', 'proto' => 'Proto'] as $key => $column) {
            if (isset($safe[$key])) { $parts[] = $column.' = '.$safe[$key]; }
        }
        return $parts;
    }

    /** Optional fixed zoom bounds; reject oversize, future or too-short windows. */
    public static function bounds(mixed $start, mixed $end, int $now = 0): ?array
    {
        if (($start === null || $start === '') && ($end === null || $end === '')) { return null; }
        if (! is_string($start) || ! is_string($end)
            || ! preg_match('/\A[0-9]{10}\z/D', $start)
            || ! preg_match('/\A[0-9]{10}\z/D', $end)) {
            throw new InvalidArgumentException('Invalid zoom timestamps');
        }
        $now = $now ?: time();
        $first = (int) $start; $last = (int) $end;
        if ($last <= $first || $last - $first < 300 || $last - $first > 604800
            || $first < $now - 691200 || $last > $now + 60) {
            throw new InvalidArgumentException('Zoom interval must be 5 minutes–7 days and within recent history');
        }
        return [
            'start' => (new \DateTimeImmutable('@'.$first))->setTimezone(new \DateTimeZone('UTC')),
            'end' => (new \DateTimeImmutable('@'.$last))->setTimezone(new \DateTimeZone('UTC')),
        ];
    }

    public static function window(int $minutes): int
    {
        return in_array($minutes, [60, 360, 1440, 10080], true) ? $minutes : 60;
    }

    /** Client-side display modes; bytes means L3 volume estimated from an L3 rate, not a native API unit. */
    public static function metric(string $metric): string
    {
        return in_array($metric, ['l3bps', 'l2bps', 'pps', 'bytes'], true) ? $metric : 'l3bps';
    }

    public static function dimension(string $dimension): string
    {
        return in_array($dimension, ['SrcAddr', 'DstAddr', 'DstPort', 'SrcAS'], true) ? $dimension : 'SrcAddr';
    }

    /** @return array<string,mixed> */
    public static function line(string $ip, string|array|null $interfaceName, int $minutes, \DateTimeImmutable $now, string $units = 'l3bps', string $direction = 'both', ?string $dimension = null, int $limit = 10, array $focus = [], ?\DateTimeImmutable $start = null): array
    {
        $units = self::metric($units);
        $apiUnit = $units === 'bytes' ? 'l3bps' : $units;
        $dims = $dimension !== null ? [self::dimension($dimension)] : [];
        return array_replace(self::common($ip, $interfaceName, $minutes, $now, $direction, $focus, $start), [
            'units' => $apiUnit, 'points' => 100, 'bidirectional' => false,
            'dimensions' => $dims, 'limit' => $dims === [] ? 1 : max(1, min(20, $limit)),
        ]);
    }

    /**
     * Optional per-member chart query. Grouping is a fixed, server-selected
     * Akvorado enriched interface dimension; never from a user input string.
     * Only physical members are included, not the logical aggregate.
     */
    public static function lagMemberLine(string $ip, array $memberNames, int $minutes, \DateTimeImmutable $now,
        string $direction, array $focus = [], ?\DateTimeImmutable $start = null): array
    {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new InvalidArgumentException('Invalid member direction');
        }
        $payload = self::line($ip, $memberNames, $minutes, $now, 'l3bps', $direction, null, 1, $focus, $start);
        $payload['dimensions'] = [$direction === 'in' ? 'InIfName' : 'OutIfName'];
        $payload['limit'] = min(12, count($memberNames));
        return $payload;
    }

    /**
     * Fixed Sankey presets only: do not let user-controlled dimension names
     * reach the upstream query. Port relationships require enriched In/OutIfName.
     * @return list<string>
     */
    public static function sankeyPreset(string $preset): array
    {
        return match ($preset) {
            'ports' => ['InIfName', 'OutIfName'],
            'services' => ['SrcAddr', 'DstAddr', 'DstPort'],
            default => ['SrcAddr', 'DstAddr'],
        };
    }

    public static function sankeyMode(string $value): string
    {
        return in_array($value, ['ports', 'ips', 'services'], true) ? $value : 'ports';
    }

    /** @return array<string,mixed> */
    public static function sankey(string $ip, string|array|null $interfaceName, int $minutes, \DateTimeImmutable $now, array $dimensions, int $limit = 16, array $focus = [], ?\DateTimeImmutable $start = null): array
    {
        $allowed = ['SrcAddr', 'DstAddr', 'SrcPort', 'DstPort', 'Proto', 'InIfName', 'OutIfName'];
        if (count($dimensions) < 2 || count($dimensions) > 4 || array_diff($dimensions, $allowed) !== []) {
            throw new InvalidArgumentException('Invalid dimensions');
        }
        return array_replace(self::common($ip, $interfaceName, $minutes, $now, 'both', $focus, $start), [
            'dimensions' => array_values($dimensions), 'limit' => max(1, min(30, $limit)),
            'bidirectional' => false,
        ]);
    }

    /** Restrict each direction to the page of names and request a group for every name. */
    public static function interfaceVisibility(string $ip, array $names, string $direction, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        if ($names === [] || count($names) > 25) {
            throw new InvalidArgumentException('Invalid interface visibility batch size');
        }
        $field = match ($direction) {
            'ingress' => 'InIfName',
            'egress' => 'OutIfName',
            default => throw new InvalidArgumentException('Invalid interface visibility direction'),
        };
        $query = self::line($ip, null, 60, $end, 'pps', start: $start);
        $matches = array_map(static fn (string $name) => $field.' = '.$name, self::quotedInterfaces($names));

        return array_replace($query, [
            'dimensions' => [$field],
            'filter' => $query['filter'].' AND ('.implode(' OR ', $matches).')',
            'limit' => count($names),
            'points' => 5,
        ]);
    }

    /** Backward-compatible alias for tests and integrations. */
    public static function conversations(string $ip, string|array|null $interfaceName, int $minutes, \DateTimeImmutable $now, array $dimensions = ['SrcAddr','DstAddr'], string $direction = 'both', int $limit = 16, array $focus = [], ?\DateTimeImmutable $start = null): array
    {
        $base = self::sankey($ip, $interfaceName, $minutes, $now, $dimensions, $limit, $focus, $start);
        $base['filter'] = self::filter($ip, $interfaceName, $direction, $focus);
        return $base;
    }

    private static function common(string $ip, string|array|null $interfaceName, int $minutes, \DateTimeImmutable $now, string $direction = 'both', array $focus = [], ?\DateTimeImmutable $start = null): array
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        return [
            'start' => ($start ?? $now->modify('-'.self::window($minutes).' minutes'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'end' => $now->format('Y-m-d\TH:i:s\Z'),
            'dimensions' => [], 'limit' => 1, 'limitType' => 'avg',
            'filter' => self::filter($ip, $interfaceName, $direction, $focus), 'units' => 'l3bps',
        ];
    }
}
