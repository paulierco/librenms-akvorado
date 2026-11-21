<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

final class FlowFormatter
{
    /** @return array{samples:list<float>,labels:list<string>,avg:float,max:float,last:float} */
    public static function line(array $payload, string $displayMetric = 'l3bps', int $minutes = 60): array
    {
        // Akvorado response: t[timeIndex] and points[seriesIndex][timeIndex].
        $times = $payload['t'] ?? [];
        $series = $payload['points'] ?? [];
        $empty = ['samples' => [], 'labels' => [], 'avg' => 0.0, 'max' => 0.0, 'last' => 0.0];
        if (! is_array($times) || ! is_array($series) || $times === [] || $series === []) {
            return $empty;
        }
        $samples = array_fill(0, count($times), 0.0);
        $included = 0;
        foreach ($series as $idx => $values) {
            if (! is_array($values) || (isset($payload['axis'][$idx]) && (int) $payload['axis'][$idx] !== 1)) {
                continue;
            }
            $included++;
            foreach ($values as $timeIdx => $value) {
                if (is_int($timeIdx) && array_key_exists($timeIdx, $samples) && is_numeric($value)) {
                    $samples[$timeIdx] += max(0.0, (float) $value);
                }
            }
        }
        if (! $included) {
            return $empty;
        }
        $count = count($samples);
        // Akvorado's final point may be an incomplete/current bucket. For its
        // 'last' value, the API itself uses the preceding completed bucket.
        if ($displayMetric === 'bytes') {
            $bucketSeconds = self::bucketSeconds($times, $minutes);
            // L3 bps -> bytes per bucket, NOT bytes/second or raw packet data.
            $samples = array_map(static fn (float $v): float => $v * $bucketSeconds / 8, $samples);
        }
        // Exclude the current bucket and trailing padding from completed-window statistics.
        $tailStart = self::completedEnd($samples);
        $completed = array_slice($samples, 0, $tailStart);
        if ($completed === []) { $completed = $samples; }
        $plotLimit = count($completed);
        $labels = array_map([self::class, 'timeLabel'], $times);
        $stamps = array_map([self::class, 'unixTime'], $times);
        return [
            'samples' => $samples,
            'plot' => $completed,
            'labels' => array_slice($labels, 0, $plotLimit),
            'plotLabels' => array_slice($labels, 0, $plotLimit),
            'timestamps' => $stamps,
            'plotTimestamps' => array_slice($stamps, 0, $plotLimit),
            'partialTailExcluded' => $plotLimit < $count,
            // Average over completed buckets only, on exactly the set the plot shows.
            'avg' => array_sum($completed) / max(1, count($completed)),
            // Peak over completed buckets only: a partial bin is never a true peak.
            'max' => max($completed),
            'last' => $completed[count($completed) - 1],
        ];
    }

    /** Minimum series length at which the newest bin is treated as an incomplete interval. */
    private const PARTIAL_TAIL_MIN_BINS = 4;

    /**
     * End index (exclusive) of the completed part of a series.
     *
     * Always drops trailing zero padding. Then drops the last non-zero bin, because the
     * newest bin is the still-filling or lagging one, but only for series long enough to
     * be a real window. Akvorado requires points>=5 and returns seven bins or more, so a
     * shorter synthetic series cannot distinguish padding from data and is left intact.
     * Never returns less than one bin.
     * @param list<float> $samples
     */
    private static function completedEnd(array $samples): int
    {
        $count = count($samples);
        if ($count === 0) { return 0; }
        $end = $count;
        while ($end > 1 && (float) $samples[$end - 1] <= 0.0) { $end--; }
        if ($count >= self::PARTIAL_TAIL_MIN_BINS && $end > 1) { $end--; }
        return max(1, $end);
    }

    /**
     * Akvorado dimensioned line output has rows[series] and points[series][time].
     * Limit output for the client and exclude reverse/secondary axes.
     * "Other" is preserved as an aggregate remainder, not presented as a port.
     * @return array{labels:list<string>,series:list<array{label:string,samples:list<float>}>,unit:string}
     */
    public static function groupedSeries(array $payload, int $limit = 8): array
    {
        $times = $payload['t'] ?? [];
        $points = $payload['points'] ?? [];
        $rows = $payload['rows'] ?? [];
        $result = ['labels' => [], 'series' => [], 'unit' => 'l3bps'];
        if (! is_array($times) || ! is_array($points) || ! is_array($rows) || count($times) < 2) {
            return $result;
        }
        $times = array_slice(array_values($times), 0, 500);
        $result['labels'] = array_map([self::class, 'timeLabel'], $times);
        foreach ($points as $i => $values) {
            if (count($result['series']) >= min(12, max(1, $limit))) { break; }
            if (! is_array($values) || ! is_array($rows[$i] ?? null) || (int) ($payload['axis'][$i] ?? 1) !== 1) { continue; }
            $row = $rows[$i];
            $name = implode(' / ', array_map('strval', array_slice($row, 0, 3)));
            $name = substr(trim($name), 0, 80);
            if ($name === '') { continue; }
            $samples = [];
            foreach ($times as $timeIndex => $_) {
                $value = $values[$timeIndex] ?? null;
                $samples[] = is_numeric($value) && is_finite((float) $value) ? max(0.0, (float) $value) : 0.0;
            }
            $result['series'][] = ['label' => $name, 'samples' => $samples];
        }
        // The stacked chart has the same trailing partial bucket as the main curve;
        // drop it from the plotted payload so the stack does not visibly collapse.
        if (count($times) > 1 && count($result['series'])) {
            $plotLimit = count($times) - 1;
            $result['plotLabels'] = array_slice($result['labels'], 0, $plotLimit);
            foreach ($result['series'] as $i => $entry) {
                $result['series'][$i]['plot'] = array_slice($entry['samples'], 0, $plotLimit);
            }
            $result['partialTailExcluded'] = true;
        }
        return $result;
    }

    /**
     * Chart only reflects the ranked returned rows; not a true whole-network DPI distribution.
     * @return list<array{label:string,value:float}>
     */
    public static function portBreakdown(array $protocolPorts, int $limit = 8): array
    {
        $sums = [];
        // Akvorado's port dimension reports an explicit 'Other' remainder row and,
        // an unresolved '0' bucket holding traffic that enrichment
        // could not attribute to a service port. Both are aggregate placeholders,
        // not services. Presenting them as slices made the mix look dominated by
        // phantom ports, so keep them out of the per-service ranking.
        $placeholderPorts = ['', '0', 'other'];
        foreach ($protocolPorts as $row) {
            $rawPort = trim((string) ($row['port'] ?? ''));
            if (in_array(strtolower($rawPort), $placeholderPorts, true)) { continue; }
            $protocol = trim((string) ($row['protocol'] ?? '?'));
            $protocol = match (strtolower($protocol)) {
                '6' => 'TCP', '17' => 'UDP', '1' => 'ICMP', '58' => 'ICMPv6',
                default => $protocol,
            };
            $name = $protocol.'/'.$rawPort;
            $value = (float) ($row['bps'] ?? 0);
            if ($value <= 0 || ! is_finite($value)) { continue; }
            $sums[$name] = ($sums[$name] ?? 0.0) + $value;
        }
        arsort($sums, SORT_NUMERIC);
        $result = [];
        foreach (array_slice($sums, 0, max(1, min(12, $limit)), true) as $label => $value) {
            $result[] = ['label' => substr($label, 0, 80), 'value' => $value];
        }
        return $result;
    }

    public static function unixTime(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $n = (int) $value;
            if ($n > 100000000000) { $n = (int) floor($n / 1000); }
            return $n >= 946684800 && $n <= 4102444800 ? $n : null;
        }
        if (! is_string($value)) { return null; }
        try { return (new \DateTimeImmutable($value))->getTimestamp(); }
        catch (\Throwable) { return null; }
    }

    private static function timeLabel(mixed $value): string
    {
        if (is_numeric($value)) {
            $n = (int) $value;
            // Some versions serialize timestamps in milliseconds.
            if ($n > 100000000000) { $n = (int) floor($n / 1000); }
            return $n >= 946684800 && $n <= 4102444800 ? gmdate('Y-m-d H:i', $n).' UTC' : (string) $value;
        }
        if (! is_string($value)) { return ''; }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC';
        } catch (\Throwable) { return substr($value, 0, 40); }
    }

    /** One chosen dimension ranked by the source API, not derived from top conversations. */
    public static function rankings(array $payload, string $metric = 'l3bps', int $limit = 10): array
    {
        $result = [];
        foreach (($payload['rows'] ?? []) as $i => $row) {
            if (! is_array($row) || ! isset($row[0]) || (int) ($payload['axis'][$i] ?? 1) !== 1) { continue; }
            $label = substr((string) $row[0], 0, 100);
            // 'Other' is an aggregate remainder, not a real IP / AS / port.
            $avg = $payload['average'][$i] ?? null;
            $total = $payload['total'][$i] ?? null;
            $value = $metric === 'bytes' ? (is_numeric($total) ? (float) $total / 8 : null)
                : (is_numeric($avg) ? (float) $avg : null);
            if ($value === null || $value < 0) { continue; }
            // Akvorado returns '0: ???' when its AS dictionary cannot resolve a flow.
            // That label carries no operator meaning and must not rank as an ASN.
            $result[] = [
                'label' => $label,
                'value' => $value,
                'isOther' => $label === 'Other',
                'isUnresolved' => str_contains($label, '???'),
            ];
        }
        usort($result, static fn ($a, $b) => (($a['isOther'] <=> $b['isOther']) ?: ($b['value'] <=> $a['value'])));
        return array_slice($result, 0, max(1, min(20, $limit)));
    }

    /**
     * True when a ranking consists only of placeholder rows (Other / unresolved AS).
     * The AS dimension against an unfed dictionary returns only '0: ???', so the view
     * must explain the gap rather than presenting it as a real top-N list.
     * @param list<array{label:string,value:float,isOther?:bool,isUnresolved?:bool}> $rankings
     */
    public static function rankingsArePlaceholder(array $rankings): bool
    {
        foreach ($rankings as $entry) {
            if (empty($entry['isOther']) && empty($entry['isUnresolved'])) { return false; }
        }
        return count($rankings) > 0;
    }

    /** Bucket duration from API time axis, with safe fallback. */
    public static function bucketSeconds(array $times, int $minutes): float
    {
        if (count($times) >= 2) {
            try {
                $a = is_numeric($times[0]) ? (float) $times[0] : (new \DateTimeImmutable((string) $times[0]))->getTimestamp();
                $b = is_numeric($times[1]) ? (float) $times[1] : (new \DateTimeImmutable((string) $times[1]))->getTimestamp();
                if ($a > 100000000000) { $a /= 1000; }
                if ($b > 100000000000) { $b /= 1000; }
                if ($b > $a && $b - $a <= 86400) { return $b - $a; }
            } catch (\Throwable) { /* use fallback */ }
        }
        return max(1.0, $minutes * 60 / max(1, count($times) - 1));
    }

    public static function readable(float $value, string $metric): string
    {
        return match ($metric) {
            'pps' => self::packetRate($value),
            'bytes' => self::bytes($value),
            default => self::bitrate($value),
        };
    }

    public static function bytes(float $value): string
    {
        foreach (['TB' => 1e12, 'GB' => 1e9, 'MB' => 1e6, 'KB' => 1e3] as $unit => $factor) {
            if ($value >= $factor) { return number_format($value / $factor, 2).' '.$unit; }
        }
        return number_format($value, 0).' B';
    }

    /** @return list<array{source:string,destination:string,bps:float}> */
    public static function conversations(array $payload, int $limit = 12): array
    {
        $result = [];
        foreach (($payload['rows'] ?? []) as $i => $row) {
            if (! is_array($row) || count($row) < 2 || (int) ($payload['axis'][$i] ?? 1) !== 1) { continue; }
            $bps = $payload['xps'][$i] ?? 0;
            if (! is_numeric($bps) || (float) $bps <= 0) { continue; }
            $result[] = ['source' => (string) $row[0], 'destination' => (string) $row[1], 'bps' => (float) $bps];
        }
        usort($result, static fn ($a, $b) => $b['bps'] <=> $a['bps']);
        return array_slice($result, 0, max(1, min(30, $limit)));
    }

    /**
     * Summarize only the TOP RETURNED Sankey rows, not all traffic.
     * Never interpret this as a full application/DPI distribution.
     * @return list<array{protocol:string,port:string,bps:float}>
     */
    public static function protocolPorts(array $payload, int $limit = 12): array
    {
        $result = [];
        foreach (($payload['rows'] ?? []) as $i => $row) {
            if (! is_array($row) || count($row) < 2 || (int) ($payload['axis'][$i] ?? 1) !== 1) { continue; }
            $bps = $payload['xps'][$i] ?? 0;
            if (! is_numeric($bps) || $bps <= 0) { continue; }
            $proto = substr((string) $row[0], 0, 40);
            $port = substr((string) $row[1], 0, 40);
            $result[] = ['protocol' => $proto, 'port' => $port, 'bps' => (float) $bps];
        }
        usort($result, static fn ($a, $b) => $b['bps'] <=> $a['bps']);
        return array_slice($result, 0, max(1, min(30, $limit)));
    }

    /** Optional flow explorer with explicit address, protocol and destination port columns. */
    public static function detailedFlows(array $payload, int $limit = 25): array
    {
        $result = [];
        foreach (($payload['rows'] ?? []) as $i => $row) {
            if (! is_array($row) || count($row) < 4 || (int) ($payload['axis'][$i] ?? 1) !== 1) { continue; }
            $bps = $payload['xps'][$i] ?? null;
            if (! is_numeric($bps) || (float) $bps <= 0 || ! is_finite((float)$bps)) { continue; }
            $result[] = [
                'source' => substr((string)$row[0], 0, 100),
                'destination' => substr((string)$row[1], 0, 100),
                'protocol' => substr((string)$row[2], 0, 24),
                'port' => substr((string)$row[3], 0, 24),
                'bps' => (float)$bps,
            ];
        }
        usort($result, static fn ($a, $b) => $b['bps'] <=> $a['bps']);
        return array_slice($result, 0, max(1, min(30, $limit)));
    }

    /**
     * Quantify how much returned traffic has no concrete group label (port/service).
     * Akvorado can emit an explicit 'Other' remainder and a numeric
     * '0' bucket for flows whose destination port could not be resolved. A stacked
     * chart hides that share inside 'Other', so expose the number instead of letting
     * the reader assume the named rows describe all traffic.
     * @param list<array{label:string,samples:list<float>}> $series
     * @return array{named:float,unattributed:float,unattributedShare:float,hasUnattributed:bool}
     */
    public static function attribution(array $series): array
    {
        $named = 0.0;
        $unattributed = 0.0;
        $placeholders = ['', 'other', 'unknown', '(unknown)', '::', '0'];
        foreach ($series as $entry) {
            $samples = $entry['plot'] ?? $entry['samples'] ?? [];
            if (! is_array($samples)) { continue; }
            $value = 0.0;
            foreach ($samples as $sample) {
                if (is_numeric($sample) && is_finite((float) $sample)) { $value += max(0.0, (float) $sample); }
            }
            $label = strtolower(trim((string) ($entry['label'] ?? '')));
            if (in_array($label, $placeholders, true)) {
                $unattributed += $value;
            } else {
                $named += $value;
            }
        }
        $total = $named + $unattributed;
        return [
            'named' => $named,
            'unattributed' => $unattributed,
            'unattributedShare' => $total > 0 ? round(100 * $unattributed / $total, 1) : 0.0,
            'hasUnattributed' => $unattributed > 0,
        ];
    }

    /** Aggregated over TOP RETURNED conversations only. */
    public static function topTalkers(array $conversations, string $side = 'source', int $limit = 8): array
    {
        if (! in_array($side, ['source', 'destination'], true)) { return []; }
        $sums = [];
        foreach ($conversations as $conversation) {
            $key = (string) ($conversation[$side] ?? '');
            if ($key === '') { continue; }
            $sums[$key] = ($sums[$key] ?? 0.0) + (float) ($conversation['bps'] ?? 0.0);
        }
        arsort($sums, SORT_NUMERIC);
        $result = [];
        foreach (array_slice($sums, 0, $limit, true) as $address => $bps) {
            $result[] = ['address' => (string) $address, 'bps' => $bps];
        }
        return $result;
    }

    public static function bitrate(float $value): string
    {
        foreach (['Tbps' => 1e12, 'Gbps' => 1e9, 'Mbps' => 1e6, 'Kbps' => 1e3] as $unit => $factor) {
            if ($value >= $factor) { return number_format($value / $factor, 2).' '.$unit; }
        }
        return number_format($value, 0).' bps';
    }

    public static function packetRate(float $value): string
    {
        if ($value >= 1e6) { return number_format($value / 1e6, 2).' Mpps'; }
        if ($value >= 1e3) { return number_format($value / 1e3, 2).' kpps'; }
        return number_format($value, 1).' pps';
    }

    /** @param list<float> $samples */
    public static function svgPoints(array $samples, int $width = 700, int $height = 150, ?float $scaleMax = null): string
    {
        $count = count($samples);
        if ($count < 2) { return ''; }
        $max = max(1.0, $scaleMax ?? max($samples));
        $points = [];
        foreach ($samples as $i => $value) {
            $x = 4 + ($width - 8) * $i / ($count - 1);
            $y = ($height - 6) - ($height - 12) * max(0.0, $value) / $max;
            $points[] = sprintf('%.1f,%.1f', $x, $y);
        }
        return implode(' ', $points);
    }
}
