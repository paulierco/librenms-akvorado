<?php

declare(strict_types=1);

require __DIR__.'/../src/Services/FlowQuery.php';
require __DIR__.'/../src/Services/SankeyDiagram.php';

use LibreNMS\Plugins\AkvoradoFlow\Services\FlowQuery;
use LibreNMS\Plugins\AkvoradoFlow\Services\SankeyDiagram;

function testCase(bool $pass, string $text): void
{
    if (! $pass) { fwrite(STDERR, "FAIL $text\n"); exit(1); }
    echo "PASS $text\n";
}

$now = new DateTimeImmutable('2026-11-20T12:00:00Z');
foreach (['ports'=>['InIfName','OutIfName'],'ips'=>['SrcAddr','DstAddr'],'services'=>['SrcAddr','DstAddr','DstPort']] as $mode=>$dimensions) {
    testCase(FlowQuery::sankeyPreset($mode)===$dimensions, "preset $mode");
    $request=FlowQuery::sankey('192.0.2.1', '1/1/1', 60, $now, $dimensions, 12, ['src'=>'192.0.2.44']);
    testCase($request['dimensions']===$dimensions && $request['limit']===12 && $request['units']==='l3bps'
        && str_contains($request['filter'],'InIfName = "1/1/1"')
        && str_contains($request['filter'],'OutIfName = "1/1/1"')
        && str_contains($request['filter'],'SrcAddr = 192.0.2.44'), "scoped query for $mode");
}
testCase(FlowQuery::sankeyMode('arbitrary-SQL')==='ports', 'unrecognized Sankey mode is not executed');
try {
    FlowQuery::sankey('192.0.2.1',null,60,$now,['SrcAddr','DROP TABLE']);
    testCase(false,'unsupported dimension rejected');
} catch (InvalidArgumentException) {
    testCase(true,'unsupported dimension rejected');
}

$payload=[
    'rows'=>[['1/1/1','1/1/13'],['1/1/1','1/1/14'],['Other','1/1/13'],['1/1/1','1/1/13'],['bad'],['1/1/7','1/1/8'],['x','y'],['a','b']],
    'xps'=>[200000000,150000000,90000000,100000000,1000,INF,-25,12000],
    'axis'=>[1,1,1,1,1,1,1,2],
];
$result=SankeyDiagram::fromResponse($payload,['InIfName','OutIfName']);
testCase(count($result['rows'])===3,'duplicates aggregated, reverse axis, malformed and invalid rates skipped');
testCase($result['rows'][0]['values']===['1/1/1','1/1/13'] && $result['rows'][0]['bps']===300000000.0,'highest path sums observed rates');
testCase($result['totalBps']===540000000.0 && $result['hasOther'],'ranked totals and Other are labeled');

$utf=SankeyDiagram::fromResponse(['rows'=>[[str_repeat('é',70),'x']], 'xps'=>[8000]],['SrcAddr','DstAddr']);
testCase(json_encode($utf,JSON_THROW_ON_ERROR)!==false, 'UTF-8 names are serializable after truncation');
$invalid=SankeyDiagram::fromResponse(['rows'=>[['a','b'],'not-array'], 'xps'=>[0,100]],['SrcAddr','DstAddr']);
testCase($invalid['rows']===[],'no positive matching rows produces empty Sankey rather than fabricated graph');

$panel=file_get_contents(__DIR__.'/../resources/views/hooks/panel.blade.php');
$script=file_get_contents(__DIR__.'/../resources/views/hooks/sankey-script.blade.php');
$tabs=file_get_contents(__DIR__.'/../src/Tabs/AkvoradoTab.php');
$traffic=file_get_contents(__DIR__.'/../src/Services/TrafficService.php');
$chartScript=file_get_contents(__DIR__.'/../resources/views/hooks/chart-script.blade.php');
testCase(str_contains($panel,'data-akv-tab="sankey"') && str_contains($panel,'data-akv-sankey') && str_contains($panel,'@json('),'Sankey tab and safe JSON payload');
testCase(str_contains($chartScript,"root.dataset.akvSankeyLoaded!=='true'") && str_contains($tabs,"$".'showSankey ? $sankeyView : null'), 'Sankey API is loaded only on demand');
testCase(str_contains($traffic,"$".'sankeyView !== null') && str_contains($traffic, "'akvorado-sankey-v") && str_contains($traffic, "'akvorado-flow-v"), 'Sankey query isolated and independently cached');
testCase(str_contains($tabs,"$".'duplicates[$name]') && str_contains($script,'Object.prototype.hasOwnProperty.call(ports,value)'), 'clickable switchports restricted to nonambiguous authorized mapping');
testCase(!str_contains($script, 'innerHTML') && str_contains($script, 'textContent') && str_contains($script,'createElementNS'), 'vector renderer uses DOM-safe text and attributes');
testCase(str_contains($script,'section') && str_contains($script,'sankey_view') && str_contains($script,'port'), 'drilldowns preserve investigation scope');
echo "All Sankey v0.7 tests passed.\n";
