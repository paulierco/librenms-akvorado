<?php

declare(strict_types=1);

use LibreNMS\Plugins\AkvoradoFlow\Services\FlowQuery;
use LibreNMS\Plugins\AkvoradoFlow\Services\PortVisibility;

require __DIR__.'/../src/Services/FlowQuery.php';
require __DIR__.'/../src/Services/PortVisibility.php';

function verify(bool $passed, string $label): void
{
    if (! $passed) {
        fwrite(STDERR, "FAIL $label\n");
        exit(1);
    }
    echo "PASS $label\n";
}

$start = new DateTimeImmutable('2026-11-21T12:00:00Z');
$end = $start->modify('+30 minutes');
$names = ['1/1/1', '1/1/2', '1/1/3'];
$ingress = FlowQuery::interfaceVisibility('192.0.2.10', $names, 'ingress', $start, $end);
$egress = FlowQuery::interfaceVisibility('192.0.2.10', ['port "a"', 'port\b'], 'egress', $start, $end);
verify($ingress['dimensions'] === ['InIfName'] && $ingress['limit'] === 3 && $ingress['points'] === 5 && $ingress['units'] === 'pps'
    && $ingress['start'] === '2026-11-21T12:00:00Z' && $ingress['end'] === '2026-11-21T12:30:00Z'
    && $ingress['filter'] === 'ExporterAddress = 192.0.2.10 AND (InIfName = "1/1/1" OR InIfName = "1/1/2" OR InIfName = "1/1/3")',
    'ingress checks only page names in the selected exporter and window');
verify($egress['dimensions'] === ['OutIfName']
    && $egress['filter'] === 'ExporterAddress = 192.0.2.10 AND (OutIfName = "port \"a\"" OR OutIfName = "port\\\\b")',
    'egress quotes interface names and uses its own direction');

$payload = [
    't' => ['2026-11-21T12:00:00Z', '2026-11-21T12:15:00Z'],
    'rows' => [['1/1/1'], ['1/1/2'], ['Other']],
    'points' => [[0, 120], [0, 0], [0, 0]],
    'axis' => [1, 1, 1],
];
verify(PortVisibility::reported($payload, $names) === ['1/1/1' => true, '1/1/2' => false, '1/1/3' => false],
    'a complete query distinguishes observed and empty names');
$payload['points'][2][0] = 20;
verify(PortVisibility::reported($payload, $names) === ['1/1/1' => true, '1/1/2' => false],
    'unreturned names remain indeterminate when a remainder has traffic');
verify(PortVisibility::reported(['t' => $payload['t'], 'rows' => [['Other']], 'points' => [[0, 0]]], $names)
    === array_fill_keys($names, false), 'a zero-only Akvorado remainder confirms an empty result');
try {
    PortVisibility::reported(['t' => $payload['t'], 'rows' => $payload['rows'], 'points' => [[1, 2]]], $names);
    verify(false, 'malformed results must be rejected');
} catch (UnexpectedValueException) {
    verify(true, 'malformed results stay unavailable');
}
verify(PortVisibility::snmpRate(125) === 1000.0 && PortVisibility::snmpRate(0) === 0.0
    && PortVisibility::snmpRate(null) === null && PortVisibility::snmpRate(-1) === null,
    'SNMP conversion distinguishes zero from unavailable values');
