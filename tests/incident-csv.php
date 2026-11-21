<?php

declare(strict_types=1);

use LibreNMS\Plugins\AkvoradoFlow\Services\IncidentCsv;
use LibreNMS\Plugins\AkvoradoFlow\Services\SankeyDiagram;

foreach (['FlowQuery', 'PeriodComparison', 'SankeyDiagram', 'IncidentCsv'] as $service) {
    require __DIR__.'/../src/Services/'.$service.'.php';
}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, 'FAIL '.$message.PHP_EOL);
        exit(1);
    }
    echo 'PASS '.$message.PHP_EOL;
}

function readCsv(array $table): array
{
    $stream = fopen('php://temp', 'w+b');
    IncidentCsv::write($stream, $table);
    rewind($stream);
    $headers = fgetcsv($stream, escape: '');
    $rows = [];
    while (($row = fgetcsv($stream, escape: '')) !== false) {
        $rows[] = array_combine($headers, $row);
    }
    fclose($stream);

    return $rows;
}

$filter = 'ExporterAddress = 192.0.2.12 AND (InIfName = "1/1/24" OR OutIfName = "1/1/24")';
$panel = [
    'ok' => true, 'windowStart' => 1795172400, 'windowEnd' => 1795176000, 'filterExpression' => $filter,
    'conversations' => [['source' => '192.0.2.44', 'destination' => '2001:db8::10', 'bps' => 2350.5]],
    'detailedFlows' => [['source' => '192.0.2.44', 'destination' => '2001:db8::10', 'protocol' => '6', 'port' => '443', 'bps' => 2350.5]],
];
$data = ['panel' => $panel, 'selectedPort' => (object) ['port_id' => 24, 'ifName' => '1/1/24']];
$row = readCsv(IncidentCsv::build('conversations', $data, 10, 'example,"rack"'))[0];
check($row['window_start_utc'] === '2026-11-20T11:00:00Z' && $row['window_end_utc'] === '2026-11-20T12:00:00Z'
    && $row['rate_unit'] === 'L3 bits/s' && $row['flow_filter'] === $filter
    && $row['row_limit'] === '24' && $row['average_bps'] === '2350.5' && $row['device'] === 'example,"rack"',
    'conversations carry exact UTC bounds, rank cap, filter, units and numeric rates');
$row = readCsv(IncidentCsv::build('detailed-flows', $data, 10, 'example'))[0];
check($row['row_limit'] === '25' && $row['protocol'] === '6' && $row['destination_port'] === '443',
    'detailed flow export keeps its actual cap and service columns');

$data['panel']['sankeyView'] = 'ports';
$data['panel']['sankey'] = SankeyDiagram::fromResponse([
    'rows' => [['', '  =example'], ['1/1/1', '1/1/24'], ['Other', '1/1/1']], 'xps' => [40, 30, 10],
], ['InIfName', 'OutIfName']);
$rows = readCsv(IncidentCsv::build('map', $data, 10, '＝switch', 'unknown_ingress'));
check(count($rows) === 1 && $rows[0]['InIfName'] === SankeyDiagram::PLACEHOLDER_UNRESOLVED
    && $rows[0]['OutIfName'] === "'=example" && $rows[0]['device'] === "'＝switch"
    && $rows[0]['excluded_ranked_rate_percent'] === '50'
    && $rows[0]['akvorado_rank_limit'] === '14' && $rows[0]['row_limit'] === '32',
    'map export applies the displayed path filter and protects spreadsheet text cells');

$data['panel']['periodComparison'] = [
    'start' => '2026-11-20 10:00:00', 'end' => '2026-11-20 11:00:00',
    'increases' => [['source' => '192.0.2.44', 'destination' => '192.0.2.66', 'bps' => 100, 'previousBps' => 10, 'increaseBps' => 90]],
    'conversationChanges' => [
        ['source' => '192.0.2.44', 'destination' => '192.0.2.55', 'window' => 'current', 'bps' => 120, 'otherObserved' => false],
    ],
];
$rows = readCsv(IncidentCsv::build('previous-period', $data, 10, 'example'));
check($rows[0]['increase_bps'] === '90' && $rows[0]['previous_start_utc'] === '2026-11-20T10:00:00Z'
    && $rows[0]['recheck_limit_per_window'] === '8' && $rows[1]['row_type'] === 'ranking_change'
    && $rows[1]['other_window'] === 'no_samples' && $rows[1]['previous_bps'] === '',
    'comparison export preserves each window and does not turn absent rates into zero');

$data['panel']['conversations'] = [];
$row = readCsv(IncidentCsv::build('conversations', $data, 10, 'example'))[0];
check($row['dataset_status'] === 'no_rows' && $row['source_ip'] === '', 'empty export retains its scope and status');
$data['panel']['partial'] = ['Conversations unavailable'];
check(IncidentCsv::build('conversations', $data, 10, 'example') === null, 'collector failures are not exported as empty results');

$data['portComparison'] = [
    'second' => (object) ['port_id' => 25, 'ifName' => '1/1/25'],
    'history' => ['start' => 1795172400, 'end' => 1795172460, 'step' => 60, 'charts' => [
        'out' => [
            'hasSnmp' => true, 'hasFlow' => true, 'timestamps' => [1795172460],
            'series' => [['values' => [0.0]], ['values' => [null]], ['values' => [800.0]], ['values' => [600.0]]],
            'averages' => ['snmp' => ['rates' => [0.0, 800.0], 'count' => 1, 'shares' => [0.0, 100.0]], 'flow' => null],
        ],
    ]],
];
$rows = readCsv(IncidentCsv::build('port-comparison', $data, 10, 'example'));
check($rows[0]['second_port_id'] === '25' && $rows[0]['row_type'] === 'matched_average' && $rows[0]['snmp_b_share_percent'] === '100'
    && $rows[1]['direction'] === 'egress' && $rows[1]['bucket_seconds'] === '60'
    && $rows[1]['snmp_a_bps'] === '0' && $rows[1]['akvorado_l2_a_bps'] === '' && $rows[1]['akvorado_l2_b_bps'] === '600',
    'port comparison preserves summary shares, exact buckets, missing values and measured zero');
