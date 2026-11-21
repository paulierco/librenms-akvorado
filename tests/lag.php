<?php

declare(strict_types=1);
require __DIR__.'/../src/Services/FlowQuery.php';
require __DIR__.'/../src/Services/LagMembers.php';
use LibreNMS\Plugins\AkvoradoFlow\Services\FlowQuery;
use LibreNMS\Plugins\AkvoradoFlow\Services\LagMembers;
function checkLag(bool $condition, string $name): void {
    if (! $condition) { fwrite(STDERR, "FAIL {$name}\n"); exit(1); }
    echo "PASS {$name}\n";
}
$ports = [
    (object) ['port_id'=>12, 'ifIndex'=>1001, 'ifName'=>'1/1/1'],
    (object) ['port_id'=>13, 'ifIndex'=>1002, 'ifName'=>'2/1/1'],
];
$members = LagMembers::normalize($ports, 10);
checkLag(count($members) === 2 && $members[0]['ifName']==='1/1/1', 'discovered members normalized');
checkLag(LagMembers::normalize([$ports[0],$ports[0]],10)===[], 'reject duplicate membership names');
checkLag(LagMembers::normalize([(object)['port_id'=>10,'ifIndex'=>40000001,'ifName'=>'0/1']],10)===[], 'reject parent self-relationship');
$names = ['0/1', ...array_column($members, 'ifName')];
$normal = FlowQuery::filter('192.0.2.5','0/1','both');
checkLag($normal === 'ExporterAddress = 192.0.2.5 AND (InIfName = "0/1" OR OutIfName = "0/1")', 'logical port stays unchanged');
$in = FlowQuery::filter('192.0.2.5',$names,'in');
$out = FlowQuery::filter('192.0.2.5',$names,'out');
$both = FlowQuery::filter('192.0.2.5',$names,'both');
checkLag(str_contains($in,'InIfName = "0/1"') && str_contains($in,'InIfName = "1/1/1"') && str_contains($in,'InIfName = "2/1/1"') && !str_contains($in,'OutIfName'), 'member-inclusive inbound');
checkLag(str_contains($out,'OutIfName = "1/1/1"') && !str_contains($out,'InIfName'), 'member-inclusive outbound');
checkLag(str_contains($both,'InIfName = "1/1/1"') && str_contains($both,'OutIfName = "2/1/1"'), 'member-inclusive combined');
checkLag(!str_contains($both,' OR true') && str_starts_with($both,'ExporterAddress = 192.0.2.5 AND'), 'scope remains exporter-limited');
$now=new DateTimeImmutable('2026-11-20T12:00:00Z');
$req=FlowQuery::line('192.0.2.5',$names,60,$now,'l3bps','in');
checkLag($req['filter']===$in, 'member scope on line requests');
$req=FlowQuery::sankey('192.0.2.5',$names,60,$now,['SrcAddr','DstAddr']);
checkLag($req['filter']===$both, 'member scope on sankey requests');
$escaped = FlowQuery::filter('192.0.2.5', ['0/1', 'member" OR true'], 'in');
checkLag(str_contains($escaped, 'InIfName = "member\" OR true"'), 'injection-looking label is escaped');
foreach ([['0/1', 27],['0/1',"2/1/1\nX"],array_fill(0,20,'x'), []] as $bad) {
    try { FlowQuery::filter('192.0.2.5',$bad,'in'); checkLag(false,'reject unsafe list'); }
    catch (InvalidArgumentException) { checkLag(true,'reject unsafe list'); }
}
$blade = file_get_contents(__DIR__.'/../resources/views/hooks/panel.blade.php');
checkLag(str_contains($blade,"['trafficOut']") && str_contains($blade,'Observed {{ $directionName }}'), 'port cards use both traffic directions');
$view = file_get_contents(__DIR__.'/../resources/views/device/tabs/akvorado.blade.php');
checkLag(str_contains($view, 'Include discovered LAG members') && str_contains($view, 'lag_members'), 'LAG view option visible');
// The member-inclusive flag must be part of the cache key. Without it a logical-only
// payload was reused for an opted-in request, so the member view returned 0 ingress on
// 0/30 while its physical members carried real inbound traffic.
$traffic = file_get_contents(__DIR__.'/../src/Services/TrafficService.php');
checkLag(str_contains($traffic, "'lag-members' : 'logical'"), 'member flag participates in the cache key');
checkLag(str_contains($traffic, 'stale non-member payload discarded'), 'opted-in request cannot receive a non-member payload');

// Completed-window semantics: the newest bin is never a complete interval.
require_once __DIR__.'/../src/Services/FlowFormatter.php';
$steady = [
    't' => ['2026-11-20T11:00:00Z','2026-11-20T11:01:00Z','2026-11-20T11:02:00Z','2026-11-20T11:03:00Z','2026-11-20T11:04:00Z'],
    'points' => [[700000000, 710000000, 720000000, 730000000, 50000000]],
    'axis' => [1],
];
$c = \LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter::line($steady, 'l3bps', 60);
checkLag(count($c['plot']) === 4 && count($c['samples']) === 5, 'plot excludes the partial final bin');
checkLag($c['last'] === 730000000.0 && $c['max'] === 730000000.0, 'last and max describe completed bins only');
checkLag(abs($c['avg'] - (700000000 + 710000000 + 720000000 + 730000000) / 4) < 1.0, 'average excludes the partial bin');
checkLag($c['avg'] <= $c['max'] && $c['last'] <= $c['max'], 'avg and last never exceed the completed peak');

$zeroPadded = [
    't' => ['2026-11-20T11:00:00Z','2026-11-20T11:01:00Z','2026-11-20T11:02:00Z','2026-11-20T11:03:00Z','2026-11-20T11:04:00Z'],
    'points' => [[1000000000, 1000000000, 1000000000, 1000000000, 0]],
    'axis' => [1],
];
$z = \LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter::line($zeroPadded, 'l3bps', 60);
checkLag(count($z['plot']) === 3 && end($z['plot']) === 1000000000.0, 'zero padding dropped before the final bin');
checkLag($z['last'] === 1000000000.0 && abs($z['avg'] - 1000000000.0) < 1.0, 'padded series keeps the completed value');

echo "All LAG regression tests passed.\n";
