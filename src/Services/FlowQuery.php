<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use InvalidArgumentException;

/** Query builder; inputs are validated and no raw user filter reaches Akvorado. */
final class FlowQuery
{
    public const CONVERSATION_RECHECK_LIMIT = 8;
    public const CONVERSATION_RANK_LIMIT = 24;
    public const DETAILED_FLOW_LIMIT = 25;
    public const MAP_RANK_LIMIT = 14;

    private const BASE_DIMENSIONS = ['SrcAddr' => 'Source IP', 'DstAddr' => 'Destination IP', 'DstPort' => 'Destination port', 'SrcAS' => 'Source AS'];
    private const OPTIONAL_DIMENSIONS = [
        'SrcNetPrefix' => 'Reported source prefix', 'DstNetPrefix' => 'Reported destination prefix',
        'SrcVlan' => 'Source VLAN', 'DstVlan' => 'Destination VLAN',
        'SrcMAC' => 'Source MAC', 'DstMAC' => 'Destination MAC',
    ];
    private const L2_FIELDS = ['src_vlan' => 'SrcVlan', 'dst_vlan' => 'DstVlan', 'src_mac' => 'SrcMAC', 'dst_mac' => 'DstMAC'];

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
            if (! is_string($value) || ($address = self::addressOrPrefix($value)) === null) {
                throw new InvalidArgumentException('Invalid '.$field.' IP address or CIDR');
            }
            $result[$field] = $address;
        }
        foreach (['dst_port' => 65535, 'proto' => 255, 'src_vlan' => 4094, 'dst_vlan' => 4094] as $field => $max) {
            $value = $input[$field] ?? null;
            if ($value === null || $value === '') { continue; }
            if (! is_string($value) && ! is_int($value)) { throw new InvalidArgumentException('Invalid numeric filter'); }
            $value = (string) $value;
            if (! preg_match('/\A[0-9]{1,5}\z/D', $value) || (int) $value > $max
                || ((int) $value === 0 && isset(self::L2_FIELDS[$field]))) {
                throw new InvalidArgumentException('Invalid numeric filter');
            }
            $result[$field] = (string) (int) $value;
        }
        foreach (['src_mac', 'dst_mac'] as $field) {
            $value = $input[$field] ?? null;
            if ($value === null || $value === '') { continue; }
            if (! is_string($value) || ! preg_match('/\A(?:[0-9a-f]{2}(?::[0-9a-f]{2}){5}|[0-9a-f]{2}(?:-[0-9a-f]{2}){5}|[0-9a-f]{4}(?:\.[0-9a-f]{4}){2})\z/i', $value)) {
                throw new InvalidArgumentException('Invalid MAC address');
            }
            $result[$field] = implode(':', str_split(strtolower(str_replace([':', '-', '.'], '', $value)), 2));
        }
        return $result;
    }

    public static function addressOrPrefix(string $value): ?string
    {
        if (strlen($value) > 49) {
            return null;
        }
        [$address, $length] = array_pad(explode('/', $value, 2), 2, null);
        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return null;
        }
        if ($length === null) {
            return $address;
        }
        $packed = inet_pton($address);
        if (! preg_match('/\A[0-9]{1,3}\z/', $length) || (int) $length > strlen($packed) * 8) {
            return null;
        }
        $bits = (int) $length;
        $bytes = strlen($packed);
        $full = intdiv($bits, 8);
        $network = substr($packed, 0, $full);
        if ($bits % 8 !== 0) {
            $network .= chr(ord($packed[$full]) & (0xff << (8 - $bits % 8)));
        }

        return inet_ntop(str_pad($network, $bytes, "\0")).'/'.$bits;
    }

    private static function focusClauses(array $focus): array
    {
        $safe = self::focus($focus);
        $parts = [];
        if (isset($safe['host'])) {
            $parts[] = '(SrcAddr = '.$safe['host'].' OR DstAddr = '.$safe['host'].')';
        }
        foreach (['src' => 'SrcAddr', 'dst' => 'DstAddr', 'dst_port' => 'DstPort', 'proto' => 'Proto'] + self::L2_FIELDS as $key => $column) {
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

    public static function enteredBounds(mixed $start, mixed $end, int $now = 0): array
    {
        return self::bounds((string)self::utcTimestamp($start), (string)self::utcTimestamp($end), $now);
    }

    private static function utcTimestamp(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2})?\z/', $value)) {
            throw new InvalidArgumentException('Enter both dates in UTC');
        }
        $value = strlen($value) === 16 ? $value.':00' : $value;
        $format = 'Y-m-d\TH:i:s';
        $time = \DateTimeImmutable::createFromFormat('!'.$format, $value, new \DateTimeZone('UTC'));
        if ($time === false || $time->format($format) !== $value) {
            throw new InvalidArgumentException('Invalid UTC calendar date');
        }

        return $time->getTimestamp();
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
        return isset(self::BASE_DIMENSIONS[$dimension]) || isset(self::OPTIONAL_DIMENSIONS[$dimension]) ? $dimension : 'SrcAddr';
    }

    public static function dimensionOptions(array $advertised): array
    {
        return self::BASE_DIMENSIONS + array_filter(self::OPTIONAL_DIMENSIONS,
            static fn ($field) => in_array($field, $advertised, true), ARRAY_FILTER_USE_KEY);
    }

    public static function layer2Options(array $dimensions): array
    {
        $options = [];
        foreach (self::L2_FIELDS as $field => $column) {
            if (isset($dimensions[$column])) {
                $options[$field] = $dimensions[$column];
            }
        }

        return $options;
    }

    public static function filterLabels(?array $dimensions = null): array
    {
        return ['host' => 'Host', 'src' => 'Source', 'dst' => 'Destination', 'dst_port' => 'Dst port', 'proto' => 'Protocol']
            + self::layer2Options($dimensions ?? self::OPTIONAL_DIMENSIONS);
    }

    /** Rank drilling uses the same validation as the corresponding filter input. */
    public static function rankingFilter(string $dimension, string $label): array
    {
        $fields = ['SrcAddr' => 'src', 'DstAddr' => 'dst', 'DstPort' => 'dst_port', 'SrcNetPrefix' => 'src', 'DstNetPrefix' => 'dst']
            + array_flip(self::L2_FIELDS);
        if (! isset($fields[$dimension]) || in_array($label, ['', 'Other', '::'], true)
            || (in_array($dimension, ['SrcNetPrefix', 'DstNetPrefix'], true) && ! str_contains($label, '/'))) {
            return [];
        }
        try {
            $filter = self::focus([$fields[$dimension] => $label]);
            return in_array(reset($filter), ['::/0', '0.0.0.0/0', '00:00:00:00:00:00'], true) ? [] : $filter;
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    public static function isOptionalDimension(string $dimension): bool
    {
        return isset(self::OPTIONAL_DIMENSIONS[$dimension]);
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

    /** Recheck a batch of directed IP pairs without changing the investigation filters. */
    public static function conversationPresence(string $ip, string|array|null $interfaceName, array $focus, array $pairs, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        if ($pairs === [] || count($pairs) > self::CONVERSATION_RECHECK_LIMIT) {
            throw new InvalidArgumentException('Invalid conversation recheck batch size');
        }
        $query = self::sankey($ip, $interfaceName, 60, $end, ['SrcAddr', 'DstAddr'], count($pairs), $focus, $start);
        $matches = [];
        foreach ($pairs as $pair) {
            $source = $pair['source'] ?? null;
            $destination = $pair['destination'] ?? null;
            if (! filter_var($source, FILTER_VALIDATE_IP) || ! filter_var($destination, FILTER_VALIDATE_IP)) {
                throw new InvalidArgumentException('Conversation recheck requires both endpoints');
            }
            $matches[] = '(SrcAddr = '.$source.' AND DstAddr = '.$destination.')';
        }
        $query['filter'] .= ' AND ('.implode(' OR ', $matches).')';

        return $query;
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
