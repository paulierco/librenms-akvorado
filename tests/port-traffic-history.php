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
