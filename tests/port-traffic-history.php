<?php

declare(strict_types=1);

use LibreNMS\Plugins\AkvoradoFlow\Services\PortTrafficHistory;

require __DIR__.'/../src/Services/FlowFormatter.php';
require __DIR__.'/../src/Services/PortTrafficHistory.php';

function check(bool $ok, string $description): void
{
    if (! $ok) {
        fwrite(STDERR, "FAIL $description\n");
        exit(1);
    }
    echo "PASS $description\n";
}

$from = 1795271400;
$to = $from + 125;
$times = array_map(static fn ($offset) => gmdate(DATE_ATOM, $from + $offset), [0, 25, 50, 75, 100, 125]);
$incoming = PortTrafficHistory::flowBuckets(['t' => $times, 'points' => [[10, 20, 30, 40, 50, 999]]], $from, $to);
$outgoing = PortTrafficHistory::flowBuckets(['t' => $times, 'points' => [[0, 0, 0, null, 80, 999]]], $from, $to);
check(count($incoming['rates']) === 5 && ! isset($incoming['rates'][$to]), 'terminal and partial flow buckets are excluded');
$data = PortTrafficHistory::chart([
    'timestamps' => [$from + 60, $from + 120],
    'series' => ['in' => ['avg' => [88, null]], 'out' => ['avg' => [0, 100]]],
], ['in' => $incoming, 'out' => $outgoing], $from, $to);
$ingress = $data['charts']['in'];
$egress = $data['charts']['out'];
check($data['step'] === 60 && $ingress['timestamps'] === [$from + 60, $from + 120]
    && $egress['timestamps'] === $ingress['timestamps'], 'both directions share the bucket-end time grid');
check($ingress['series'][0]['values'] === [88.0, null] && $egress['series'][0]['values'] === [0.0, 100.0],
    'an unknown SNMP sample differs from a real zero');
check($ingress['series'][1]['values'] === [17.5, 2450 / 60]
    && $egress['series'][1]['values'] === [0.0, null], 'flow estimates are time weighted and missing coverage leaves a gap');

$from = 1795271400;
$to = $from + 240;
$flow = PortTrafficHistory::flowBuckets([
    't' => [$from, $from + 120, $to], 'points' => [[100, 200, 0]],
], $from, $to);
$history = PortTrafficHistory::chart([
    'timestamps' => [$from + 60, $from + 120, $from + 180, $from + 240],
    'series' => ['in' => ['avg' => [10, 20, 30, 50]]],
], ['in' => $flow], $from, $to);
check($history['step'] === 120 && $history['charts']['in']['series'][0]['values'] === [15.0, 40.0],
    'a coarser exporter requires the SNMP series to use equally wide buckets');
$flowOnly = PortTrafficHistory::chart([], ['in' => $flow], $from, $to);
check($flowOnly['charts']['in']['hasFlow'] && ! $flowOnly['charts']['in']['hasSnmp'],
    'one source remains available when the other source has no timeline');

$zeroFlow = PortTrafficHistory::flowBuckets([
    't' => [$from, $from + 120, $to], 'points' => [[0, 0, 0]],
], $from, $to);
$ports = [
    11 => ['label' => '1/1/1', 'snmp' => [
        'timestamps' => [$from + 60, $from + 120, $from + 180, $from + 240],
        'series' => ['in' => ['avg' => [100, null, 300, 400]], 'out' => ['avg' => [0, 0, 0, 0]]],
    ], 'flows' => ['in' => $flow, 'out' => $flow]],
    24 => ['label' => '1/1/24', 'snmp' => [
        'timestamps' => [$from + 120, $from + 240],
        'series' => ['in' => ['avg' => [300, 600]], 'out' => ['avg' => [0, 0]]],
    ], 'flows' => ['in' => $zeroFlow]],
];
$compare = PortTrafficHistory::comparisonChart($ports, $from, $to);
$ingress = $compare['charts']['in'];
$egress = $compare['charts']['out'];
check($compare['step'] === 120 && $ingress['timestamps'] === [$from + 120, $to]
    && $ingress['timestamps'] === $egress['timestamps']
    && array_column($ingress['series'], 'values') === [[null, 350.0], [100.0, 200.0], [300.0, 600.0], [0.0, 0.0]],
    'both ports and sources use the shared coarser grid with independent gaps');
check($ingress['averages']['snmp']['count'] === 1 && $ingress['averages']['snmp']['rates'] === [350.0, 600.0]
    && $ingress['averages']['flow'] === ['count' => 2, 'rates' => [150.0, 0.0], 'shares' => [100.0, 0.0]]
    && $egress['averages']['flow'] === null && $egress['averages']['snmp']['shares'] === null,
    'traffic shares use matched buckets and preserve zero values and unavailable data');
$ports[24]['snmp']['timestamps'] = [$from + 180, $from + 300];
$normal = PortTrafficHistory::comparisonChart($ports, $from, $to)['charts']['in'];
$reversed = PortTrafficHistory::comparisonChart(array_reverse($ports, true), $from, $to)['charts']['in'];
check($normal['timestamps'] === $reversed['timestamps']
    && array_column($normal['series'], 'colorIndex') === [0, 0, 2, 2]
    && str_starts_with($reversed['series'][0]['label'], '1/1/24'),
    'swapping ports changes label order and keeps the shared time grid');
