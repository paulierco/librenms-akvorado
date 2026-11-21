<?php

declare(strict_types=1);

use LibreNMS\Plugins\AkvoradoFlow\Services\InvestigationView;
use LibreNMS\Plugins\AkvoradoFlow\Services\TrafficHeatmap;

foreach (['FlowQuery', 'FlowFormatter', 'PortTrafficHistory', 'TrafficHeatmap', 'InvestigationView'] as $service) {
    require __DIR__.'/../src/Services/'.$service.'.php';
}

function check(bool $ok, string $label): void
{
    if (! $ok) {
        fwrite(STDERR, 'FAIL '.$label.PHP_EOL);
        exit(1);
    }
    echo 'PASS '.$label.PHP_EOL;
}

$start = strtotime('2026-11-20 23:00:00 UTC');
$end = $start + 10800;
$grid = TrafficHeatmap::grid([
    't' => range($start, $end, 900), 'axis' => [1],
    'points' => [[10, 30, 0, 20, 0, 0, 0, 0, 100, null, 110, 120, 9000]],
], $start, $end);
check(array_keys($grid['rows']) === ['2026-11-20', '2026-11-21'] && $grid['hours'] === 3 && $grid['interval'] === 900,
    'UTC date rows cross midnight and use full one-hour cells');
check($grid['rows']['2026-11-20'][23] === ['start' => $start, 'stats' => ['mean' => 15.0, 'peak' => 30.0]]
    && $grid['rows']['2026-11-21'][0]['stats'] === ['mean' => 0.0, 'peak' => 0.0]
    && $grid['rows']['2026-11-21'][1]['stats'] === null
    && $grid['highest'] === 15.0 && $grid['observed'] === 1,
    'full-hour average and peak distinguish sampled traffic, zero and missing coverage');

$shifted = TrafficHeatmap::grid([
    't' => range($start + 120, $start + 7200, 1200), 'points' => [[5, 5, 40, 40, 40, 70]],
], $start, $start + 7200);
check($shifted['rows']['2026-11-20'][23]['stats'] === null
    && $shifted['rows']['2026-11-21'][0]['stats'] === null,
    'partial coverage on either side of shifted source buckets leaves a gap');

$empty = TrafficHeatmap::grid(['t' => [], 'points' => []], $start, $end);
check($empty['state'] === 'no_samples' && $empty['observed'] === 0 && $empty['rows']['2026-11-21'][1]['stats']['mean'] === 0.0,
    'a successful empty query keeps the calendar and marks hours without samples');
check(TrafficHeatmap::grid(['t' => [], 'message' => 'unavailable'], $start, $end)['state'] === 'query_failed'
    && TrafficHeatmap::grid(['t' => [$start, $start + 7200], 'points' => [[40, 20]]], $start, $end)['state'] === 'resolution_unavailable',
    'malformed and coarse collector responses cannot create apparent hourly data');

$saved = InvestigationView::query(['section' => 'traffic-heatmap', 'port' => '24', 'heatmap_days' => '28',
    'heatmap_direction' => 'out', 'host' => '192.0.2.1']);
check($saved === ['section' => 'traffic-heatmap', 'period' => 60, 'port' => 24, 'heatmap_days' => 28, 'heatmap_direction' => 'out']
    && TrafficHeatmap::lookback(['28']) === 7 && TrafficHeatmap::direction('raw-filter') === 'both',
    'saved heatmap views retain bounded scope and preset settings');
