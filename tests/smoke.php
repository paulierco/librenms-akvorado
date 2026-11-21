<?php

declare(strict_types=1);

require __DIR__.'/../src/Services/FlowQuery.php';
require __DIR__.'/../src/Services/FlowFormatter.php';
require __DIR__.'/../src/Services/PortHealth.php';
require __DIR__.'/../src/Services/FlowCoverage.php';
require __DIR__.'/../src/Services/InvestigationView.php';
require __DIR__.'/../src/Services/SankeyDiagram.php';
use LibreNMS\Plugins\AkvoradoFlow\Services\FlowQuery;
use LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter;
use LibreNMS\Plugins\AkvoradoFlow\Services\PortHealth;
use LibreNMS\Plugins\AkvoradoFlow\Services\FlowCoverage;
use LibreNMS\Plugins\AkvoradoFlow\Services\InvestigationView;

function check(bool $ok, string $name): void { if (! $ok) {fwrite(STDERR, "FAIL $name\n"); exit(1);} echo "PASS $name\n"; }
$now = new DateTimeImmutable('2026-11-20T12:00:00Z');
check(FlowQuery::filter('192.0.2.10') === 'ExporterAddress = 192.0.2.10', 'device filter');
check(str_contains(FlowQuery::filter('192.0.2.10','1/1/13'), 'InIfName = "1/1/13" OR OutIfName = "1/1/13"'), 'port filter');
check(str_contains(FlowQuery::filter('192.0.2.10','1/1/13','in'), 'AND InIfName = "1/1/13"'), 'port InIf filter');
check(str_contains(FlowQuery::filter('192.0.2.10','1/1/13','out'), 'AND OutIfName = "1/1/13"'), 'port OutIf filter');

$special = FlowQuery::filter('192.0.2.10', 'Gig\\0/1\"xyz', 'in');
check(str_contains($special, 'InIfName = ') && str_contains($special, '\\\\') && str_contains($special, '\\"'), 'escaped interface name');
try { FlowQuery::filter('192.0.2.10', "Gi1/0/1\nAND true"); check(false, 'reject control characters'); } catch (InvalidArgumentException) {check(true, 'reject control characters');}
try { FlowQuery::filter('192.0.2.10', ''); check(false, 'reject empty name'); } catch (InvalidArgumentException) {check(true, 'reject empty name');}
try { FlowQuery::filter('1.2.3.4 OR true'); check(false,'reject injection'); } catch (InvalidArgumentException) {check(true,'reject injection');}
try { FlowQuery::filter('192.0.2.10', null, 'in'); check(false,'direction requires port'); } catch (InvalidArgumentException) {check(true,'direction requires port');}
check(FlowQuery::window(1000000) === 60 && FlowQuery::window(10080) === 10080, 'window allowlist');
$line = FlowQuery::line('192.0.2.10','1/1/13',360,$now,'l3bps','in');
check($line['points'] === 100 && $line['start'] === '2026-11-20T06:00:00Z' && str_contains($line['filter'],'InIfName'), 'line payload');
$p = FlowQuery::sankey('192.0.2.10','1/1/13',1440,$now,['Proto','DstPort']);
check($p['dimensions'] === ['Proto','DstPort'] && $p['limit'] === 16, 'port breakdown payload');
try { FlowQuery::sankey('192.0.2.10', null,60,$now,['Evil','DstPort']); check(false,'reject dimensions'); } catch (InvalidArgumentException) {check(true,'reject dimensions');}
$lineData = FlowFormatter::line(['t'=>['2026-11-20T11:00:00Z','2026-11-20T11:01:00Z'], 'points'=>[[100,200],[30,20]], 'axis'=>[1,1]]);
check($lineData['samples'] === [130.0,220.0] && $lineData['max'] === 220.0, 'line series-major aggregation');
check(FlowFormatter::line(['t'=>[1,2],'points'=>[[2,3],[900,900]],'axis'=>[1,2]])['samples'] === [2.0,3.0], 'reverse series excluded');
check(FlowFormatter::line(['t'=>[1,2], 'points'=>[]])['samples'] === [], 'empty line');
$conv = FlowFormatter::conversations(['rows'=>[['a','b'],['a','c'],['Other','Other']], 'xps'=>[2000,4000,500], 'axis'=>[1,1,1]]);
check($conv[0]['destination'] === 'c' && $conv[0]['bps'] === 4000.0, 'conversations sort');
check(FlowFormatter::topTalkers($conv)[0]['bps'] === 6000.0, 'top sources partial aggregate');
check(FlowFormatter::topTalkers($conv,'destination')[0]['address'] === 'c', 'top destinations');
$protocol = FlowFormatter::protocolPorts(['rows'=>[['6','443'],['17','53']], 'xps'=>[800,400], 'axis'=>[1,1]]);
check($protocol[0]['port'] === '443' && $protocol[0]['bps'] === 800.0, 'protocol ports');
check(str_contains(FlowFormatter::svgPoints([10,20],700,150,20),','), 'graph svg');
check(str_contains(FlowFormatter::packetRate(1800),'kpps'), 'packet rate units');

check(FlowQuery::metric('bytes') === 'bytes' && FlowQuery::metric('nonsense') === 'l3bps', 'metric allowlist');
check(FlowQuery::dimension('DstPort') === 'DstPort' && FlowQuery::dimension('UNION ALL') === 'SrcAddr', 'dimension allowlist');
$rankReq = FlowQuery::line('192.0.2.10', '1/1/13', 1440, $now, 'bytes', 'both', 'SrcAS', 12);
check($rankReq['units'] === 'l3bps' && $rankReq['dimensions'] === ['SrcAS'] && $rankReq['limit'] === 12, 'rank request / bytes API unit');
$rankPayload = ['rows' => [['AS123'], ['Other'], ['AS456']], 'average' => [3000,10000,2000], 'total' => [24000,80000,16000], 'axis' => [1,1,1]];
$rankings = FlowFormatter::rankings($rankPayload, 'bytes');
check($rankings[0]['label'] === 'AS123' && $rankings[0]['value'] === 3000.0 && $rankings[2]['isOther'], 'ranking and Other bucket');
$bytePlot = FlowFormatter::line(['t' => ['2026-11-20T11:00:00Z','2026-11-20T11:01:00Z','2026-11-20T11:02:00Z'], 'points'=>[[8000,16000,0]], 'axis'=>[1]], 'bytes', 60);
check($bytePlot['samples'] === [60000.0,120000.0,0.0] && $bytePlot['last'] === 120000.0, 'L3 bps -> bytes per 60s bucket');
check(FlowFormatter::bucketSeconds([1732100400000,1732100460000],60) === 60.0, 'millisecond time bins');
check(str_contains(FlowFormatter::readable(1500000,'bytes'),'MB') && str_contains(FlowFormatter::readable(1800,'pps'),'kpps'), 'metric display units');
$fakePort = new class {
    public function statistics(): object {
        return new class {
            public function first(): object {
                return (object) ['ifInDiscards_rate' => 4.5, 'ifOutDiscards_rate' => '1.25', 'ifInDiscards'=>300, 'ifOutDiscards'=>42];
            }
        };
    }
    public float $ifInErrors_rate = 2.0;
    public float $ifOutErrors_rate = 0.0;
};
$snmp = PortHealth::snapshot($fakePort);
check($snmp['inDiscardsRate'] === 4.5 && $snmp['outDiscardsRate'] === 1.25 && $snmp['inErrorsRate'] === 2.0, 'LibreNMS SNMP discard snapshot');
$grouped = FlowFormatter::groupedSeries([
    't'=>['2026-11-20T11:00:00Z', '2026-11-20T11:01:00Z', '2026-11-20T11:02:00Z'],
    'rows'=>[['443'],['445'],['Other']],
    'points'=>[[1000,3000,1000],[100,200,150],[10,11,12]], 'axis'=>[1,2,1],
]);
check(count($grouped['series']) === 2 && $grouped['series'][0]['label'] === '443'
    && $grouped['series'][1]['label'] === 'Other'
    && $grouped['series'][0]['samples'] === [1000.0,3000.0,1000.0], 'grouped stacked timeline');
$breakdown = FlowFormatter::portBreakdown([
    ['protocol'=>'6','port'=>'443','bps'=>500.0],
    ['protocol'=>'6','port'=>'443','bps'=>400.0],
    ['protocol'=>'17','port'=>'53','bps'=>250.0],
]);
check($breakdown[0]['label'] === 'TCP/443' && $breakdown[0]['value'] === 900.0, 'breakdown aggregates repeated ports');
$hostFilter = FlowQuery::filter('192.0.2.10', '1/1/13', 'in', ['host' => '2001:db8::5']);
check(str_contains($hostFilter, 'InIfName = "1/1/13" AND (SrcAddr = 2001:db8::5 OR DstAddr = 2001:db8::5)'),
    'either-direction host filter preserves the selected interface');
check(FlowCoverage::comparisonUnavailable('pps', [], false) !== null
    && FlowCoverage::comparisonUnavailable('bytes', [], false) !== null
    && FlowCoverage::comparisonUnavailable('l2bps', ['host' => '192.0.2.40'], false) !== null
    && FlowCoverage::comparisonUnavailable('l2bps', [], true) !== null
    && FlowCoverage::comparisonUnavailable('l2bps', [], false) === null,
    'SNMP comparison requires an unfiltered recent L2 rate');

// v0.4 focused investigation: input validation, all API payloads and time zoom bounds.
$focus = FlowQuery::focus(['src'=>'192.0.2.40','dst'=>'2001:db8::5','dst_port'=>'00443','proto'=>'6']);
check($focus === ['src'=>'192.0.2.40','dst'=>'2001:db8::5','dst_port'=>'443','proto'=>'6'], 'normalized focus filters');
$filter=FlowQuery::filter('192.0.2.10','1/1/13','in',$focus);
check(str_contains($filter,'InIfName = "1/1/13"') && str_contains($filter,'SrcAddr = 192.0.2.40')
    && str_contains($filter,'DstAddr = 2001:db8::5') && str_contains($filter,'DstPort = 443')
    && str_contains($filter,'Proto = 6') && !str_contains($filter,'OutIfName'), 'device and port scope retained with focus');
foreach ([['src'=>'1.1.1.1 OR true'], ['dst'=>['192.0.2.1']], ['dst_port'=>'443 OR true'], ['proto'=>'999'], ['dst_port'=>'65536']] as $bad) {
    try { FlowQuery::focus($bad); check(false,'reject malicious focus'); }
    catch (InvalidArgumentException) { check(true,'reject malicious focus'); }
}
$networkFocus = FlowQuery::focus([
    'src' => '192.0.2.147/25', 'dst' => '2001:db8:0:0:8123::1/65',
    'host' => '198.51.100.9/24', 'src_vlan' => '0042', 'dst_mac' => '0011.2233.44FF',
]);
$networkFilter = FlowQuery::filter('192.0.2.10', '1/1/13', 'out', $networkFocus);
check($networkFocus === ['src' => '192.0.2.128/25', 'dst' => '2001:db8:0:0:8000::/65',
    'host' => '198.51.100.0/24', 'src_vlan' => '42', 'dst_mac' => '00:11:22:33:44:ff']
    && str_contains($networkFilter, '(SrcAddr = 198.51.100.0/24 OR DstAddr = 198.51.100.0/24)')
    && str_contains($networkFilter, 'OutIfName = "1/1/13"')
    && str_contains($networkFilter, 'SrcVlan = 42 AND DstMAC = 00:11:22:33:44:ff'),
    'CIDR and Layer 2 filters normalize and preserve selected exporter and direction');
foreach ([['src' => '192.0.2.1/33'], ['dst' => '2001:db8::/129'], ['host' => '192.0.2.0/24 OR true'],
    ['src_vlan' => '0'], ['dst_vlan' => '4095'], ['src_mac' => '00:22-33:44:55:66'], ['dst_mac' => '01:23:45:67:89:ab AND 1']] as $bad) {
    try { FlowQuery::focus($bad); check(false, 'reject invalid optional filters'); }
    catch (InvalidArgumentException) { check(true, 'reject invalid optional filters'); }
}
$advertised = FlowQuery::dimensionOptions(['SrcAddr', 'SrcNetPrefix', 'DstMAC', 'SrcVlan', 'ArbitrarySchemaField']);
check(isset($advertised['SrcNetPrefix'], $advertised['DstMAC'], $advertised['SrcVlan'])
    && ! isset($advertised['DstVlan'], $advertised['ArbitrarySchemaField'])
    && FlowQuery::layer2Options($advertised) === ['src_vlan' => 'Source VLAN', 'dst_mac' => 'Destination MAC'],
    'only supported server-advertised optional dimensions and their filters are exposed');
check(FlowQuery::line('192.0.2.10', null, 60, $now, dimension: 'DstMAC')['dimensions'] === ['DstMAC']
    && FlowQuery::rankingFilter('SrcNetPrefix', '198.51.100.9/24') === ['src' => '198.51.100.0/24']
    && FlowQuery::rankingFilter('DstMAC', '00-11-22-33-44-FF') === ['dst_mac' => '00:11:22:33:44:ff']
    && FlowQuery::rankingFilter('SrcVlan', '0') === []
    && FlowQuery::rankingFilter('DstNetPrefix', '::/0') === [],
    'optional rankings drill into valid values and omit placeholder groups');
try {
    FlowQuery::conversationPresence('192.0.2.10', null, [],
        [['source' => '192.0.2.0/24', 'destination' => '192.0.2.1']], $now->modify('-1 hour'), $now);
    check(false, 'conversation rechecks require exact addresses');
} catch (InvalidArgumentException) {
    check(true, 'conversation rechecks require exact addresses');
}
$zoom=FlowQuery::bounds('1795172400','1795176000',1795176100);
check($zoom !== null && $zoom['start']->getTimestamp()===1795172400 && $zoom['end']->getTimestamp()===1795176000, 'validated zoom bounds');
$entered = FlowQuery::enteredBounds('2026-11-20T11:00:23', '2026-11-20T11:08', 1795176100);
check($entered['start']->format(DATE_ATOM) === '2026-11-20T11:00:23+00:00'
    && $entered['end']->format(DATE_ATOM) === '2026-11-20T11:08:00+00:00', 'manual UTC dates preserve exact seconds');
foreach ([['2026-11-31T11:00', '2026-12-01T11:10'], ['2026-11-20T11:00', '2026-11-20T11:04'],
    ['2026-11-20T11:00Z', '2026-11-20T11:20'], [null, '2026-11-20T11:20']] as [$start, $end]) {
    try { FlowQuery::enteredBounds($start, $end, 1795176100); check(false, 'reject invalid manual dates'); }
    catch (InvalidArgumentException) { check(true, 'reject invalid manual dates'); }
}
$saved = InvestigationView::query([
    'section' => 'sankey', 'port' => '8', 'compare_port' => '9', 'period' => '360', 'host' => '192.0.2.10/24',
    'sankey_view' => 'ports', 'sankey_paths' => 'unknown_ingress', 'compare' => 'previous',
    'lag_members' => '1', 'redirect' => 'https://example.test/',
], $entered);
check($saved === ['section' => 'sankey', 'period' => 360, 'start' => 1795172423, 'end' => 1795172880, 'port' => 8,
    'metric' => 'l3bps', 'dimension' => 'SrcAddr', 'host' => '192.0.2.0/24', 'compare' => 'previous', 'lag_members' => '1',
    'sankey_view' => 'ports', 'sankey_paths' => 'unknown_ingress'], 'saved investigations keep the current scope and discard unrelated settings');
$zoomed=FlowQuery::line('192.0.2.10','1/1/13',60,$zoom['end'],'l3bps','out',null,10,$focus,$zoom['start']);
check($zoomed['start']==='2026-11-20T11:00:00Z' && $zoomed['end']==='2026-11-20T12:00:00Z'
    && str_contains($zoomed['filter'],'DstPort = 443'), 'zoomed line request preserves focus');
$detailRequest=FlowQuery::sankey('192.0.2.10','1/1/13',60,$zoom['end'],['SrcAddr','DstAddr','Proto','DstPort'],25,$focus,$zoom['start']);
check($detailRequest['dimensions'] === ['SrcAddr','DstAddr','Proto','DstPort'] && $detailRequest['start']===$zoomed['start'], 'detailed sankey request shares zoom');
foreach ([['1795175000','1795175000'], ['1795175000','1795175100'], ['1795000000','1796000000'], ['x','1795176000']] as $bad) {
    try { FlowQuery::bounds($bad[0],$bad[1],1795176100); check(false,'reject invalid zoom bounds'); }
    catch (InvalidArgumentException) { check(true,'reject invalid zoom bounds'); }
}
$timed=FlowFormatter::line(['t'=>[1795172400000,1795172460000], 'points'=>[[100,200]]]);
check($timed['timestamps'] === [1795172400,1795172460], 'Unix timestamps for chart interaction');
$details=FlowFormatter::detailedFlows(['rows'=>[
    ['192.0.2.1','192.0.2.2','6','443'],
    ['192.0.2.3','192.0.2.4','17','53'],
    ['Other','Other','6','0'],
], 'xps'=>[200,500,0], 'axis'=>[1,1,1]]);
check(count($details)===2 && $details[0]['port']==='53' && $details[1]['source']==='192.0.2.1', 'detailed conversations sorted and limited');
// Partial trailing bucket, attribution accounting and placeholder ports.
$steady = ['t' => ['2026-11-20T11:00:00Z','2026-11-20T11:01:00Z','2026-11-20T11:02:00Z','2026-11-20T11:03:00Z'],
    'points' => [[700000000,710000000,720000000,50000000]], 'axis' => [1]];
$steadyCurve = FlowFormatter::line($steady, 'l3bps', 60);
check(count($steadyCurve['samples']) === 4 && count($steadyCurve['plot']) === 3, 'plot excludes the partial trailing bucket');
check(end($steadyCurve['plot']) === 720000000.0 && end($steadyCurve['samples']) === 50000000.0, 'plot ends in the last completed bucket, not the partial one');
check($steadyCurve['last'] === 720000000.0, 'completed last value kept');
check($steadyCurve['partialTailExcluded'] === true, 'partial tail flagged for the view');
check(count($steadyCurve['timestamps']) === 4 && count($steadyCurve['plotTimestamps']) === 3, 'timestamps kept full, plotTimestamps aligned to plot');

$steadyGrouped = FlowFormatter::groupedSeries([
    't' => ['2026-11-20T11:00:00Z','2026-11-20T11:01:00Z','2026-11-20T11:02:00Z'],
    'rows' => [['443'],['Other']],
    'points' => [[1000,2000,300],[400,500,60]], 'axis' => [1,1],
]);
check(count($steadyGrouped['plotLabels']) === 2, 'stacked labels drop the partial bucket');
check(count($steadyGrouped['series'][0]['plot']) === 2 && count($steadyGrouped['series'][0]['samples']) === 3, 'stacked series keeps samples but plots completed buckets');

$attribution = FlowFormatter::attribution($steadyGrouped['series']);
check($attribution['hasUnattributed'] === true && $attribution['unattributed'] === 900.0 && $attribution['named'] === 3000.0, 'unattributed remainder separated from named rows');
check($attribution['unattributedShare'] === 23.1, 'unattributed share computed as a percentage');

$placeholderPorts = FlowFormatter::portBreakdown([
    ['protocol'=>'6','port'=>'443','bps'=>500.0],
    ['protocol'=>'6','port'=>'0','bps'=>9000.0],
    ['protocol'=>'6','port'=>'Other','bps'=>9000.0],
    ['protocol'=>'17','port'=>'','bps'=>9000.0],
    ['protocol'=>'17','port'=>'53','bps'=>250.0],
]);
check(count($placeholderPorts) === 2 && $placeholderPorts[0]['label'] === 'TCP/443', 'placeholder ports excluded from the donut mix');

// Unresolved AS rows must be flagged, not ranked as real ASNs.
$asRankings = FlowFormatter::rankings(['rows' => [['0: ???'], ['Other']], 'average' => [9000,1000], 'total' => [72000,8000], 'axis' => [1,1]], 'l3bps', 12);
check($asRankings[0]['isUnresolved'] === true, 'unresolved AS row flagged');
check(FlowFormatter::rankingsArePlaceholder($asRankings) === true, 'placeholder-only AS ranking detected');
$ipRankings = FlowFormatter::rankings(['rows' => [['192.0.2.140'], ['Other']], 'average' => [9000,1000], 'total' => [72000,8000], 'axis' => [1,1]], 'l3bps', 12);
check(FlowFormatter::rankingsArePlaceholder($ipRankings) === false, 'real IP ranking not flagged');

// Flow coverage: flow rate vs latest SNMP poll, per direction.
$covPort = static fn (): array => [
    'ok' => true, 'ifName' => '0/30',
    'trafficIn' => ['samples' => [0.0, 0.0], 'last' => 0.0, 'plot' => [0.0, 0.0]],
    'trafficOut' => ['samples' => [85000000.0, 84000000.0], 'last' => 85000000.0, 'plot' => [85000000.0]],
    'snmpHistory' => ['speedBps' => 10000000000.0, 'currentInBps' => 7860000.0, 'currentOutBps' => 166000000.0],
];
$cov = FlowCoverage::forPanel($covPort(), '0/30');
check($cov['available'] === true, 'coverage available when SNMP history exists');
check($cov['directions']['in']['state'] === 'not_observed', 'zero ingress with SNMP traffic is not_observed');
check($cov['directions']['out']['state'] === 'under_reported', 'sampled egress is under_reported');
check($cov['asymmetric'] === true, 'single-direction observation flagged asymmetric');
check(str_contains((string) $cov['note'], 'Only the OutIf direction'), 'asymmetry note names the observed direction');

$covHealthy = FlowCoverage::forPanel([
    'ok' => true, 'ifName' => '1/1/24',
    'trafficIn' => ['samples' => [58000000.0], 'last' => 58000000.0],
    'trafficOut' => ['samples' => [57000000.0], 'last' => 57000000.0],
    'snmpHistory' => ['speedBps' => 10000000000.0, 'currentInBps' => 60000000.0, 'currentOutBps' => 60000000.0],
], '1/1/24');
check($covHealthy['directions']['in']['state'] === 'matched', 'within tolerance is matched');
check($covHealthy['asymmetric'] === false, 'both directions observed is not asymmetric');
check($covHealthy['directions']['in']['coverage'] === 96.7, 'coverage percentage computed');

$covZero = FlowCoverage::forPanel([
    'ok' => true, 'ifName' => '1/1/11',
    'trafficIn' => ['samples' => [0.0], 'last' => 0.0],
    'trafficOut' => ['samples' => [0.0], 'last' => 0.0],
    'snmpHistory' => ['speedBps' => null, 'currentInBps' => 0.0, 'currentOutBps' => 0.0],
], '1/1/11');
check($covZero['directions']['in']['state'] === 'snmp_unavailable', 'negligible SNMP rate yields no coverage claim');

check(FlowCoverage::forPanel(['ok' => true], null) === null, 'exporter-wide view has no coverage');
check(FlowCoverage::forPanel(['ok' => true, 'snmpHistory' => null], '1/1/1')['available'] === false, 'missing SNMP history degrades cleanly');

$covExceed = FlowCoverage::forPanel([
    'ok' => true, 'ifName' => 'x',
    'trafficIn' => ['samples' => [1.0], 'last' => 152000000.0],
    'trafficOut' => ['samples' => [1.0], 'last' => 1.0],
    'snmpHistory' => ['speedBps' => 1000000000.0, 'currentInBps' => 100000000.0, 'currentOutBps' => 1000000000.0],
], 'x');
check($covExceed['directions']['in']['state'] === 'exceeds_snmp', 'flow above SNMP is flagged, not silently shown');

// Link-aggregate coverage. This platform cannot sample aggregate ports, so inbound
// records carry the member interface and the aggregate's InIf is structurally zero.
// A coverage ratio against the aggregate's own SNMP counter would read 0% and lie.
$aggPanel = [
    'ok' => true, 'ifName' => '0/30',
    'trafficIn' => ['samples' => [0.0, 0.0], 'last' => 0.0, 'plot' => [0.0]],
    'trafficOut' => ['samples' => [80000000.0], 'last' => 80000000.0, 'plot' => [80000000.0]],
    'snmpHistory' => ['speedBps' => 10000000000.0, 'currentInBps' => 5180000.0, 'currentOutBps' => 171310000.0],
];
$members = [
    ['port_id' => 117053, 'ifIndex' => 1015, 'ifName' => '1/1/15'],
    ['port_id' => 117083, 'ifIndex' => 101015, 'ifName' => '2/1/15'],
];
$aggCov = FlowCoverage::forPanel($aggPanel, '0/30', ['aggregate' => true, 'members' => $members]);
check($aggCov['aggregate'] === true, 'aggregate coverage flagged');
check($aggCov['directions']['in']['state'] === 'aggregate_member_ingress', 'aggregate ingress attributed to members');
check($aggCov['directions']['in']['coverage'] === null, 'no coverage ratio invented for aggregate ingress');
check($aggCov['directions']['out']['state'] === 'aggregate_egress', 'aggregate egress directly attributable');
check($aggCov['members'] === ['1/1/15', '2/1/15'], 'member names reported');
check(str_contains((string) $aggCov['note'], 'sampling an aggregate port is not possible'), 'note states the platform rule');

$aggNoMembers = FlowCoverage::forPanel($aggPanel, '0/20', ['aggregate' => true, 'members' => []]);
check($aggNoMembers['directions']['in']['state'] === 'aggregate_unattributable', 'unresolved membership is unattributable');
// No scheduled collector exists, so the unresolved case must state the real limitation
// rather than pointing at a CLI helper that no longer feeds the web path.
check(str_contains((string) $aggNoMembers['note'], 'does not collect membership from a scheduled SNMP job'),
    'unresolved case states the no-collector limitation');
check(! str_contains((string) $aggNoMembers['note'], 'discovery helper'),
    'no advice to run a collector that does not feed the web path');

// A physical port must keep the numeric ratio path.
$physCov = FlowCoverage::forPanel([
    'ok' => true, 'ifName' => '1/1/24',
    'trafficIn' => ['samples' => [40000000.0], 'last' => 40000000.0],
    'trafficOut' => ['samples' => [70000000.0], 'last' => 70000000.0],
    'snmpHistory' => ['speedBps' => 10000000000.0, 'currentInBps' => 50000000.0, 'currentOutBps' => 71000000.0],
], '1/1/24', ['aggregate' => false, 'members' => []]);
check(! isset($physCov['aggregate']) && $physCov['directions']['in']['coverage'] == 80.0, 'physical port keeps numeric coverage');

// Per-member coverage: each member has its own SNMP counter, so its flow rate is compared
// with that member, never with the aggregate. Activates whenever ports_stack gives membership.
$mcMembers = [
    ['port_id' => 117053, 'ifIndex' => 1015, 'ifName' => '1/1/15'],
    ['port_id' => 117083, 'ifIndex' => 101015, 'ifName' => '2/1/15'],
];
$mcFlow = [
    '1/1/15' => ['in' => 850000.0, 'out' => 8330000.0],
    '2/1/15' => ['in' => 2060000.0, 'out' => 155080000.0],
];
$mcSnmp = [
    '1/1/15' => ['in' => 1540000.0, 'out' => 8790000.0],
    '2/1/15' => ['in' => 4200000.0, 'out' => 156840000.0],
];
$mc = FlowCoverage::memberCoverage($mcMembers, $mcFlow, $mcSnmp);
check(count($mc) === 2, 'per-member coverage covers every member');
check($mc[0]['directions']['in']['coverage'] === 55.2, 'member ingress ratio uses the member counter');
check($mc[0]['directions']['out']['state'] === 'matched', 'member egress within tolerance');
check($mc[1]['directions']['in']['state'] === 'under_reported', 'member ingress shortfall flagged');
check($mc[1]['directions']['out']['coverage'] === 98.9, 'member egress ratio computed');
$mcPartial = FlowCoverage::memberCoverage($mcMembers, $mcFlow,
    ['1/1/15' => ['in' => null, 'out' => 8790000.0], '2/1/15' => []]);
check($mcPartial[0]['directions']['in']['state'] === 'not_comparable'
    && $mcPartial[0]['directions']['in']['coverage'] === null, 'missing member SNMP yields not_comparable');
check($mcPartial[0]['directions']['out']['coverage'] !== null, 'available member direction still measured');

echo "All smoke tests passed.\n";
