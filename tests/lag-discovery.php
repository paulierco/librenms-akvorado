<?php

declare(strict_types=1);
require __DIR__.'/../src/Services/LagMembers.php';
require __DIR__.'/../src/Services/LagDiscovery.php';
require __DIR__.'/../src/Services/FlowQuery.php';
use LibreNMS\Plugins\AkvoradoFlow\Services\LagDiscovery;
use LibreNMS\Plugins\AkvoradoFlow\Services\FlowQuery;
function testLag(bool $ok, string $label): void {
    if (! $ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}
$ports = [
    ['port_id'=>117110,'ifIndex'=>40000001,'ifName'=>'0/1','ifDescr'=>'Dynamic Aggregate Number 1 ref 40000001 size 2','ifType'=>'ieee8023adLag'],
    ['port_id'=>117120,'ifIndex'=>40000020,'ifName'=>'0/20','ifDescr'=>'Static Aggregate Number 20 ref 40000020 size 2','ifType'=>'ieee8023adLag'],
    ['port_id'=>117065,'ifIndex'=>2001,'ifName'=>'1/2/1A','ifType'=>'ethernetCsmacd'],
    ['port_id'=>117095,'ifIndex'=>102001,'ifName'=>'2/2/1A','ifType'=>'ethernetCsmacd'],
    ['port_id'=>117055,'ifIndex'=>1017,'ifName'=>'1/1/17','ifType'=>'ethernetCsmacd'],
    ['port_id'=>117085,'ifIndex'=>101017,'ifName'=>'2/1/17','ifType'=>'ethernetCsmacd'],
];
$attachedOids = [
    LagDiscovery::ATTACHED_OID.'.2001'=>'40000001',
    LagDiscovery::ATTACHED_OID.'.102001'=>'40000001',
    LagDiscovery::ATTACHED_OID.'.1017'=>'0',
    LagDiscovery::ATTACHED_OID.'.101017'=>'0',
];
$selectedOids = [
    LagDiscovery::SELECTED_OID.'.2001'=>'1',
    LagDiscovery::SELECTED_OID.'.102001'=>'1',
    LagDiscovery::SELECTED_OID.'.1017'=>'20',
    LagDiscovery::SELECTED_OID.'.101017'=>'20',
];
$a = LagDiscovery::parseColumn($attachedOids, LagDiscovery::ATTACHED_OID);
$b = LagDiscovery::parseColumn($selectedOids, LagDiscovery::SELECTED_OID);
$map = LagDiscovery::build($ports, $a, $b);
testLag(count($map[117110] ?? []) === 2, 'two dynamic LAG members');
testLag(array_column($map[117110], 'ifName') === ['1/2/1A','2/2/1A'], 'dynamic port names match verified LibreNMS ifIndexes');
testLag(array_unique(array_column($map[117110], 'membership')) === ['attached'], 'dynamic attachment status retained');
testLag(count($map[117120] ?? []) === 2, 'two static LAG members');
testLag(array_column($map[117120], 'ifName') === ['1/1/17','2/1/17'], 'static port names match verified LibreNMS ifIndexes');
testLag(array_unique(array_column($map[117120], 'membership')) === ['selected-static'], 'static selected is not asserted operationally attached');
$wrong = $a;
$wrong[2001] = 99999999;
$missing = LagDiscovery::build($ports, $wrong, $b);
testLag(array_column($missing[117110] ?? [], 'ifName') === ['2/2/1A'], 'no invented member from unmatched aggregator');
$wrong = $ports;
$wrong[1]['ifDescr'] = 'Virtual interface';
testLag(! isset(LagDiscovery::build($wrong, $a, $b)[117120]), 'static fallback requires unambiguous static LAG description');
$bad = false;
try { LagDiscovery::parseColumn([LagDiscovery::ATTACHED_OID.'.2001 AND true'=>'1'], LagDiscovery::ATTACHED_OID); }
catch (UnexpectedValueException) { $bad = true; }
testLag($bad, 'malformed SNMP suffix rejected');
$bad = false;
try { LagDiscovery::parseColumn([LagDiscovery::ATTACHED_OID.'.2001'=>'INTEGER: 1'], LagDiscovery::ATTACHED_OID); }
catch (UnexpectedValueException) { $bad = true; }
testLag($bad, 'unexpected text value rejected');
$now = new DateTimeImmutable('2026-11-20T12:00:00Z');
$req = FlowQuery::lagMemberLine('192.0.2.15', ['1/2/1A','2/2/1A'],60,$now,'in');
testLag($req['dimensions'] === ['InIfName'] && $req['limit'] === 2 && str_contains($req['filter'],'InIfName = "1/2/1A"'), 'member ingress query grouped and scoped');
$req = FlowQuery::lagMemberLine('192.0.2.15', ['1/2/1A','2/2/1A'],60,$now,'out');
testLag($req['dimensions'] === ['OutIfName'] && str_contains($req['filter'],'OutIfName = "2/2/1A"'), 'member egress query grouped and scoped');
$blade = file_get_contents(__DIR__.'/../resources/views/hooks/panel.blade.php');
testLag(str_contains($blade, 'member_in') && str_contains($blade, 'member_out'), 'member chart payload is wired');
// Real-device case: OS6860N names a non-dynamic aggregate "Omnichannel Aggregate
// Number 20 ref 40000020", not "Static Aggregate Number 20". Its members report
// attached=0, selected=20 (measured), so they resolve only through this number.
// The previous Static-only match left aggregate 20 with zero members.
$ocPorts = [
    ['port_id'=>117119,'ifIndex'=>40000020,'ifName'=>'0/20','ifDescr'=>'Omnichannel Aggregate Number 20 ref 40000020 size 2','ifType'=>'ieee8023adLag'],
    ['port_id'=>117055,'ifIndex'=>1017,'ifName'=>'1/1/17','ifDescr'=>'Alcatel-Lucent OS NI 1/1/17','ifType'=>'ethernetCsmacd'],
    ['port_id'=>117085,'ifIndex'=>101017,'ifName'=>'2/1/17','ifDescr'=>'Alcatel-Lucent OS NI 2/1/17','ifType'=>'ethernetCsmacd'],
];
$ocAttached = [1017 => 0, 101017 => 0];      // attached ifIndex 0: static/selected only
$ocSelected = [1017 => 20, 101017 => 20];    // selected aggregate number 20
$ocMap = \LibreNMS\Plugins\AkvoradoFlow\Services\LagDiscovery::build($ocPorts, $ocAttached, $ocSelected);
testLag(isset($ocMap[117119]) && count($ocMap[117119]) === 2, 'Omnichannel aggregate resolves its selected members');
testLag(array_column($ocMap[117119], 'ifName') === ['1/1/17', '2/1/17'], 'Omnichannel member names ordered');
testLag(($ocMap[117119][0]['membership'] ?? '') === 'selected-static', 'Omnichannel membership marked selected-static');
testLag(! isset(LagDiscovery::build($ocPorts, $ocAttached, [1017 => 99, 101017 => 99])[117119]), 'wrong selected number yields no mapping');

echo "All LAG discovery tests passed.\n";
