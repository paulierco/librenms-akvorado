<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

final class IncidentCsv
{
    public const REPORTS = ['conversations', 'detailed-flows', 'previous-period', 'map', 'port-comparison'];

    public static function build(string $report, array $data, int $deviceId, string $deviceName, string $pathMode = 'all'): ?array
    {
        $port = $data['selectedPort'] ?? null;
        $context = [
            'report' => $report,
            'device_id' => $deviceId, 'device' => $deviceName,
            'port_id' => $port->port_id ?? null, 'interface' => $port->ifName ?? null,
        ];
        if ($report === 'port-comparison') {
            return self::portComparison($data['portComparison'] ?? [], $context);
        }

        $panel = $data['panel'] ?? [];
        if (empty($panel['ok']) || ! isset($panel['windowStart'], $panel['windowEnd'])) {
            return null;
        }
        $context += [
            'window_start_utc' => self::utc($panel['windowStart']), 'window_end_utc' => self::utc($panel['windowEnd']),
            'rate_unit' => 'L3 bits/s', 'flow_filter' => $panel['filterExpression'],
        ];

        return match ($report) {
            'conversations' => self::conversations($panel, $context, false),
            'detailed-flows' => self::conversations($panel, $context, true),
            'previous-period' => self::previous($panel['periodComparison'] ?? [], $context),
            'map' => self::map($panel, $context, $pathMode),
            default => null,
        };
    }

    private static function conversations(array $panel, array $context, bool $detailed): ?array
    {
        $dataset = $detailed ? 'detailedFlows' : 'conversations';
        $failure = $detailed ? 'Detailed conversations unavailable' : 'Conversations unavailable';
        if (! isset($panel[$dataset]) || in_array($failure, $panel['partial'] ?? [], true)) {
            return null;
        }
        $limit = $detailed ? FlowQuery::DETAILED_FLOW_LIMIT : FlowQuery::CONVERSATION_RANK_LIMIT;
        $columns = ['rank', 'source_ip', 'destination_ip'];
        if ($detailed) {
            $columns = [...$columns, 'protocol', 'destination_port'];
        }
        $columns[] = 'average_bps';
        $rows = [];
        foreach ($panel[$dataset] as $index => $row) {
            $values = [$index + 1, $row['source'], $row['destination']];
            if ($detailed) {
                $values = [...$values, $row['protocol'], $row['port']];
            }
            $rows[] = [...$values, $row['bps']];
        }

        return self::table($context + ['akvorado_rank_limit' => $limit, 'row_limit' => $limit], $columns, $rows);
    }

    private static function previous(array $comparison, array $context): ?array
    {
        if (! isset($comparison['increases'], $comparison['conversationChanges'])) {
            return null;
        }
        $rows = [];
        foreach ($comparison['increases'] as $row) {
            $rows[] = ['increase', $row['source'], $row['destination'], 'both', $row['bps'], $row['previousBps'], $row['increaseBps'], null];
        }
        foreach ($comparison['conversationChanges'] as $row) {
            $current = $row['window'] === 'current';
            $rows[] = [
                'ranking_change', $row['source'], $row['destination'], $row['window'],
                $current ? $row['bps'] : null, $current ? null : $row['bps'], null,
                match ($row['otherObserved']) {
                    true => 'observed_below_cutoff', false => 'no_samples', null => 'recheck_unavailable',
                },
            ];
        }

        return self::table($context + [
            'previous_start_utc' => str_replace(' ', 'T', $comparison['start']).'Z',
            'previous_end_utc' => str_replace(' ', 'T', $comparison['end']).'Z',
            'akvorado_rank_limit' => FlowQuery::CONVERSATION_RANK_LIMIT,
            'increase_row_limit' => PeriodComparison::INCREASE_LIMIT,
            'recheck_limit_per_window' => FlowQuery::CONVERSATION_RECHECK_LIMIT,
        ], ['row_type', 'source_ip', 'destination_ip', 'ranked_window', 'current_bps', 'previous_bps', 'increase_bps', 'other_window'], $rows);
    }

    private static function map(array $panel, array $context, string $pathMode): ?array
    {
        $preset = $panel['sankeyView'] ?? 'ports';
        $diagram = $panel['sankey'] ?? null;
        if ($diagram === null && ($panel['flowState'] ?? null) !== 'no_matching_flows') {
            return null;
        }
        $dimensions = $diagram['dimensions'] ?? FlowQuery::sankeyPreset($preset);
        $mode = $dimensions === ['InIfName', 'OutIfName'] ? SankeyDiagram::pathMode($pathMode) : 'all';
        $filtered = $diagram === null ? ['rows' => [], 'excludedShare' => 0.0] : SankeyDiagram::filterPaths($diagram, $mode);
        $rows = [];
        foreach ($filtered['rows'] as $index => $row) {
            $rows[] = [$index + 1, ...$row['values'], $row['bps']];
        }

        return self::table($context + [
            'map_preset' => $preset, 'path_filter' => $mode, 'excluded_ranked_rate_percent' => $filtered['excludedShare'],
            'akvorado_rank_limit' => FlowQuery::MAP_RANK_LIMIT, 'row_limit' => SankeyDiagram::DISPLAY_LIMIT,
        ], ['rank', ...$dimensions, 'average_bps'], $rows);
    }

    private static function portComparison(array $comparison, array $context): ?array
    {
        $history = $comparison['history'] ?? null;
        if ($history === null || ! isset($comparison['second'])) {
            return null;
        }
        $context += [
            'second_port_id' => $comparison['second']->port_id, 'second_interface' => $comparison['second']->ifName,
            'window_start_utc' => self::utc($history['start']), 'window_end_utc' => self::utc($history['end']),
            'rate_unit' => 'bits/s', 'bucket_seconds' => $history['step'],
        ];
        $rows = [];
        foreach ($history['charts'] as $direction => $chart) {
            if (! $chart['hasSnmp'] && ! $chart['hasFlow']) {
                continue;
            }
            $direction = $direction === 'in' ? 'ingress' : 'egress';
            $snmp = $chart['averages']['snmp'];
            $flow = $chart['averages']['flow'];
            $rows[] = [
                'matched_average', $direction, null, null,
                $snmp['rates'][0] ?? null, $snmp['rates'][1] ?? null, $flow['rates'][0] ?? null, $flow['rates'][1] ?? null,
                $snmp['count'] ?? 0, $flow['count'] ?? 0,
                $snmp['shares'][0] ?? null, $snmp['shares'][1] ?? null, $flow['shares'][0] ?? null, $flow['shares'][1] ?? null,
            ];
            foreach ($chart['timestamps'] as $index => $end) {
                $rows[] = [
                    'bucket', $direction, self::utc($end - $history['step']), self::utc($end),
                    $chart['series'][0]['values'][$index], $chart['series'][2]['values'][$index],
                    $chart['series'][1]['values'][$index], $chart['series'][3]['values'][$index],
                    null, null, null, null, null, null,
                ];
            }
        }

        return self::table($context, [
            'row_type', 'direction', 'bucket_start_utc', 'bucket_end_utc',
            'snmp_a_bps', 'snmp_b_bps', 'akvorado_l2_a_bps', 'akvorado_l2_b_bps',
            'snmp_matched_buckets', 'akvorado_matched_buckets',
            'snmp_a_share_percent', 'snmp_b_share_percent', 'akvorado_a_share_percent', 'akvorado_b_share_percent',
        ], $rows);
    }

    private static function utc(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private static function table(array $context, array $columns, array $rows): array
    {
        $context += ['dataset_status' => $rows === [] ? 'no_rows' : 'ok'];

        return [
            'headers' => [...array_keys($context), ...$columns],
            'context' => array_values($context),
            'rows' => $rows ?: [array_fill(0, count($columns), null)],
        ];
    }

    /** @param resource $output */
    public static function write($output, array $table): void
    {
        fputcsv($output, $table['headers'], ',', '"', '', "\r\n");
        foreach ($table['rows'] as $row) {
            fputcsv($output, array_map(self::cell(...), [...$table['context'], ...$row]), ',', '"', '', "\r\n");
        }
    }

    private static function cell(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = mb_scrub($value, 'UTF-8');
            if (preg_match('/\A[\x00-\x20\p{Z}\x{FEFF}]*[=+\-@＝＋－＠]|\A[\t\r\n]/u', $value)) {
                return "'".$value;
            }
        }

        return is_float($value) ? (is_finite($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null) : $value;
    }
}
