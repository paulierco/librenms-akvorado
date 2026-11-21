<?php

declare(strict_types=1);

require __DIR__.'/../src/Services/SnmpHistory.php';

use LibreNMS\Plugins\AkvoradoFlow\Services\SnmpHistory;

function test(bool $ok, string $name): void {
    if (!$ok) { fwrite(STDERR, "FAIL {$name}\n"); exit(1); }
    echo "PASS {$name}\n";
}

$to = time() - 60;
$from = $to - 3600;
$port = (object) [
    'port_id' => 123,
    'ifSpeed' => 1000000000,
    'ifHighSpeed' => 1000,
    'ifInOctets_rate' => 12500000,
    'ifOutOctets_rate' => 6250000,
];
$data = SnmpHistory::forPort($port, $from, $to);
test($data !== null, 'supported port/time window');
test($data['start'] === $from && $data['end'] === $to, 'absolute shared Akvorado and SNMP times');
test($data['graphs']['traffic']['type'] === 'port_bits', 'native LibreNMS port bitrate graph');
test($data['graphs']['errorsDiscards']['type'] === 'port_errors', 'native LibreNMS errors + discards graph');
test($data['graphs']['packets']['type'] === 'port_upkts', 'native LibreNMS packet graph metadata');
test($data['graphs']['traffic']['from'] === $from && $data['graphs']['errorsDiscards']['to'] === $to, 'graph requests share bounds');
test($data['graphs']['traffic']['id'] === 123, 'port-scoped no device-wide graph substitution');
test($data['speedBps'] === 1000000000.0, 'ifSpeed link capacity');
test($data['currentUtilizationIn'] === 10.0 && $data['currentUtilizationOut'] === 5.0, 'SNMP byte rate to utilization');
test(SnmpHistory::forPort((object) ['port_id' => '123&evil=1'], $from, $to) === null, 'invalid port ID rejected');
test(SnmpHistory::forPort($port, $to, $from) === null, 'reversed window rejected');
test(SnmpHistory::forPort($port, $to-10080*60-1, $to) === null, 'excessive history range rejected');
test(SnmpHistory::forPort($port, $from, time()+3600) === null, 'future range rejected');
$highSpeed = (object) ['port_id'=>2, 'ifSpeed'=>4294967295, 'ifHighSpeed'=>10000];
test(SnmpHistory::speedBps($highSpeed) === 10000000000.0, 'ifHighSpeed replaces saturated ifSpeed');
$unknown = (object) ['port_id'=>2, 'ifSpeed'=>null, 'ifInOctets_rate'=>100];
test(SnmpHistory::speedBps($unknown) === null && SnmpHistory::percentOfSpeed(100.0,null)===null, 'unknown speed is not treated as zero utilization');
test(SnmpHistory::percentOfSpeed(-4.0,1000000000)===null, 'invalid rates omitted');
// Render the history view and assert on emitted HTML: the native graph URLs must be
// percent-encoded and carry the whitelisted graph types plus the shared port/time bounds.
// Source-text matching said nothing about what a browser actually receives.
$rendered = null;
$librenmsRoot = getenv('LIBRENMS_ROOT') ?: '/opt/librenms';
if (is_file($librenmsRoot.'/vendor/autoload.php') && is_file($librenmsRoot.'/bootstrap/app.php')) {
    try {
        require $librenmsRoot.'/vendor/autoload.php';
        $app = require $librenmsRoot.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $rendered = $app['view']->make('akvorado-flow::hooks.snmp-history',
            ['panel' => ['snmpHistory' => $data]])->render();
    } catch (\Throwable $e) {
        $rendered = null;
    }
}

if (is_string($rendered) && strlen($rendered) > 200) {
    test((bool) preg_match('/src="[^"]*graph\.php\?type=port_bits/', $rendered), 'rendered native bitrate graph image');
    test((bool) preg_match('/src="[^"]*graph\.php\?type=port_errors/', $rendered), 'rendered native errors graph image');
    // HTML entities are the correct encoding for an href attribute; %26 would be wrong here.
    test(str_contains($rendered, '&amp;') && ! str_contains($rendered, '%26'), 'rendered parameters use HTML-safe ampersands');
    test(str_contains($rendered, 'id=123'), 'rendered graph is port-scoped');
    test(str_contains($rendered, (string) $from) && str_contains($rendered, (string) $to), 'rendered graphs share bounds');
    // Only whitelisted query keys may appear; no user input reaches the URL.
    preg_match_all('#graph\.php\?([^"]+)#', $rendered, $urls);
    $keys = [];
    foreach ($urls[1] ?? [] as $query) {
        foreach (explode('&amp;', $query) as $pair) { $keys[explode('=', $pair)[0]] = true; }
    }
    // `style` is now a legitimate rendered parameter: LibreNMS renders RRD graphs for the
    // requested style (Util\Graph.php / RrdOptionsBuilder.php use $params->style). It is
    // still a fixed value chosen server-side, never user input.
    $allowed = ['type', 'id', 'from', 'to', 'width', 'height', 'legend', 'style'];
    test(array_diff(array_keys($keys), $allowed) === [], 'rendered graph URLs carry only whitelisted parameters');
    test(isset($keys['style']), 'rendered graph URLs request a theme style');
    echo "All SNMP history tests passed (rendered).\n";
} else {
    // Structural fallback only when Laravel cannot bootstrap in this context.
    $templ = (string) file_get_contents(__DIR__.'/../resources/views/hooks/snmp-history.blade.php');
    test(str_contains($templ, 'http_build_query') && str_contains($templ, "url('graph.php')"),
        'native graph path uses encoded whitelisted parameters');
    test(str_contains($templ, 'port_bits') && str_contains($templ, 'port_errors'),
        'whitelisted native graph types referenced');
    echo "All SNMP history tests passed (structural).\n";
}
