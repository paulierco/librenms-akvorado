<?php

declare(strict_types=1);

namespace Illuminate\Support\Facades {
    final class Cache {
        public static array $stored = [];
        public static function remember(string $key, int $ttl, \Closure $callback): array {
            return self::$stored[$key] ??= $callback();
        }
    }
    final class Log {
        public static function warning(string $message, array $context): void {}
    }
}

namespace LibreNMS\Plugins\AkvoradoFlow\Services {
    use Illuminate\Support\Facades\Cache;

    final class ExporterResolver {
        public function resolve(object $device): ?array {return empty($device->unmapped) ? ['ip'=>'192.0.2.12','method'=>'explicit'] : null;}
    }
    final class AkvoradoClient {
        public array $requests = [];
        public ?\Closure $respond = null;
        public function post(string $endpoint, array $payload): array {
            $this->requests[] = [$endpoint,$payload];
            if ($this->respond !== null) { return ($this->respond)($payload); }
            if ($endpoint==='graph/line') {
                return ['t'=>['2026-11-20T11:00:00Z','2026-11-20T11:01:00Z','2026-11-20T11:02:00Z'],
                    'points'=>[[1500,1400,1300]],'axis'=>[1], 'rows'=>[['443']],
                    'average'=>[1400],'total'=>[5000]];
            }
            $dimensions=$payload['dimensions'];
            return ['rows'=>[array_map(static fn($dim)=>match($dim){
                'InIfName'=>'1/1/1','OutIfName'=>'1/1/24','SrcAddr'=>'192.0.2.44',
                'DstAddr'=>'198.51.100.22','DstPort'=>'443',default=>'1',},$dimensions)],
                'xps'=>[550000000], 'axis'=>[1]];
        }
    }
    foreach (['FlowDiagnostics','PeriodComparison','FlowQuery','FlowFormatter','SankeyDiagram','PortHealth','FlowCoverage','SnmpHistory','FlowAvailability','LagMembers','TrafficService'] as $file) {
        require __DIR__.'/../src/Services/'.$file.'.php';
    }
    function config(string $key,mixed $default=null):mixed { return match($key) {
        'akvorado-flow.cache_seconds'=>60,
        'akvorado-flow.url'=>'https://akvorado.example.test',
        default=>$default,
    };}
    function assertResult(bool $ok,string $label):void {
        if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";
    }
    $device=(object)['device_id'=>22];
    $port=new class {
        public int $ifIndex=1001;
        public string $ifName='1/1/1';
        public int $port_id=25;
        public function statistics():object{return new class {public function first():?object {return null;}};}
    };
    $bounds=['start'=>new \DateTimeImmutable('2026-11-20T11:00:00Z'),'end'=>new \DateTimeImmutable('2026-11-20T11:30:00Z')];
    $client=new AkvoradoClient();
    $service=new TrafficService(new ExporterResolver(),$client);
    $base=$service->panel($device,$port,60,'l3bps','SrcAddr',[], $bounds);
    assertResult($base['ok'] && $base['flowState']==='has_flows' && !isset($base['sankey']),'base dashboard does not run Sankey query');
    $baselineCalls=count($client->requests);
    $map=$service->panel($device,$port,60,'l3bps','SrcAddr',[],$bounds,'ports');
    assertResult($map['sankey']['dimensions']===['InIfName','OutIfName'] && $map['sankey']['rows'][0]['bps']===550000000.0,'port-to-port Sankey is built from supported enriched fields');
    assertResult(count($client->requests)===$baselineCalls+1,'opening Sankey adds one query, not a second full dashboard');
    $ips=$service->panel($device,$port,60,'l3bps','SrcAddr',[],$bounds,'ips');
    assertResult($ips['sankey']['dimensions']===['SrcAddr','DstAddr'] && count($client->requests)===$baselineCalls+2,'changing Sankey mode adds only one scoped query');
    $repeat=$service->panel($device,$port,60,'l3bps','SrcAddr',[],$bounds,'ports');
    assertResult(count($client->requests)===$baselineCalls+2,'reopening a cached Sankey mode performs no extra query');
    foreach($client->requests as [$endpoint,$payload]){
        assertResult(str_contains($payload['filter'],'ExporterAddress = 192.0.2.12')
            && str_contains($payload['filter'],'1/1/1')
            && $payload['start']==='2026-11-20T11:00:00Z'
            && $payload['end']==='2026-11-20T11:30:00Z','all queries remain scoped and zoomed: '.$endpoint);
    }
    // Placeholder labelling: an empty interface label is an unresolved identity, not a
    // generic 'unknown' node, and must stay distinguishable from Akvorado's Other remainder.
    $lab = SankeyDiagram::fromResponse([
        'rows' => [['', '2/1/15'], ['', 'Other'], ['1/1/24', '0/4'], ['Other', '0/30']],
        'xps' => [141700000, 41200000, 28980000, 13900000],
        'axis' => [1, 1, 1, 1],
    ], ['InIfName', 'OutIfName'], 32);
    assertResult(count($lab['rows']) === 4, 'all placeholder rows retained');
    $labels = array_map(static fn ($row) => $row['values'][0], $lab['rows']);
    assertResult(in_array('source port unknown', $labels, true), 'empty label becomes a distinct token');
    assertResult(! in_array('(unknown)', $labels, true), 'ambiguous token never emitted');
    assertResult($lab['hasOther'] === true && in_array('Other', $labels, true), 'Other stays a separate remainder');
    assertResult($lab['hasUnresolved'] === true, 'unresolved flows flagged');
    $expected = round(100 * (141700000 + 41200000) / (141700000 + 41200000 + 28980000 + 13900000), 1);
    assertResult($lab['unresolvedShare'] === $expected, 'unresolved share is rate-weighted');

    $limited = SankeyDiagram::fromResponse([
        'rows' => [['1/1/24', ''], ['', '0/30']], 'xps' => [2000, 1000],
    ], ['InIfName', 'OutIfName'], 1);
    assertResult($limited['rows'][0]['values'][1] === 'destination port unknown'
        && $limited['unresolvedShare'] === 0.0 && ! $limited['hasUnresolved'],
        'only displayed paths with missing ingress contribute to the ingress share');

    // Control: no empty labels means no unresolved claim.
    $clean = SankeyDiagram::fromResponse([
        'rows' => [['1/1/24', '0/4'], ['Other', '0/30']], 'xps' => [1000, 2000], 'axis' => [1, 1],
    ], ['InIfName', 'OutIfName'], 32);
    assertResult($clean['hasUnresolved'] === false && $clean['unresolvedShare'] === 0.0,
        'no unresolved claim without empty labels');

    $known = SankeyDiagram::filterPaths($lab, 'known');
    $unknown = SankeyDiagram::filterPaths($lab, 'unknown_ingress');
    assertResult(count($known['rows']) === 1 && $known['rows'][0]['values'] === ['1/1/24', '0/4']
        && round($known['excludedShare'], 1) === round(100 * (1 - 28980000 / $lab['totalBps']), 1),
        'known paths exclude unknown interface labels and Other');
    assertResult(count($unknown['rows']) === 2 && round($unknown['excludedShare'], 1) === round(100 * (1 - (141700000 + 41200000) / $lab['totalBps']), 1),
        'unknown ingress includes only empty input groups and reports the removed share');
    assertResult(SankeyDiagram::filterPaths($limited, 'known') === ['rows' => [], 'excludedShare' => 100.0]
        && SankeyDiagram::filterPaths($ips['sankey'], 'known')['rows'] === $ips['sankey']['rows']
        && SankeyDiagram::pathMode(['known']) === 'all',
        'map filters handle an empty result, IP presets and malformed parameters');

    $diagnosticClient = new AkvoradoClient();
    $diagnostics = new FlowDiagnostics(new ExporterResolver(), $diagnosticClient);
    $dataFor = static fn (array $rates): array => [
        't' => ['2026-11-20T11:00:00Z', '2026-11-20T11:01:00Z', '2026-11-20T11:02:00Z'],
        'points' => [$rates], 'axis' => [1],
    ];
    $diagnosticClient->respond = static function (array $query) use ($dataFor): array {
        if (str_ends_with($query['filter'], 'InIfName = ""')) {
            return $dataFor([200, 40, 0]);
        }
        if (str_ends_with($query['filter'], 'OutIfName = ""')) {
            throw new \RuntimeException('HTTP 400');
        }
        return $dataFor([300, 100, 0]);
    };
    $check = $diagnostics->forDevice($device, 60, $bounds);
    assertResult($check['state'] === 'observed' && $check['lastBucket'] === '2026-11-20T11:01:00Z'
        && $check['shares']['ingress'] === 60.0 && $check['shares']['egress'] === null,
        'diagnostics report latest nonzero bucket, rate-weighted ingress and unavailable egress');
    $diagnostics->forDevice($device, 60, $bounds);
    assertResult(count($diagnosticClient->requests) === 3, 'repeated diagnostics reuse the cached query set');
    foreach ($diagnosticClient->requests as [$endpoint, $query]) {
        assertResult($endpoint === 'graph/line' && str_starts_with($query['filter'], 'ExporterAddress = 192.0.2.12')
            && $query['start'] === '2026-11-20T11:00:00Z' && $query['end'] === '2026-11-20T11:30:00Z',
            'all diagnostics use the same exporter, bounds and metric');
    }

    Cache::$stored = [];
    $diagnosticClient->requests = [];
    $diagnosticClient->respond = static fn (array $query): array => $dataFor([0, 0, 0]);
    assertResult($diagnostics->forDevice($device, 60, $bounds)['state'] === 'no_flows'
        && count($diagnosticClient->requests) === 1, 'no-flow observation avoids follow-up queries');
    Cache::$stored = [];
    $diagnosticClient->respond = static fn (array $query): array => ['error' => 'unusable'];
    assertResult($diagnostics->forDevice($device, 60, $bounds)['state'] === 'query_failed'
        && $diagnostics->forDevice((object) ['unmapped' => true], 60, $bounds)['state'] === 'unmapped',
        'diagnostics distinguish a failed query from an unmapped device');

    Cache::$stored = [];
    $comparisonClient = new AkvoradoClient();
    $comparisonClient->respond = static function (array $query): array {
        $prior = $query['start'] === '2026-11-20T10:30:00Z';
        if (isset($query['points'])) {
            $start = new \DateTimeImmutable($query['start']);
            return [
                't' => array_map(static fn ($seconds) => $start->modify('+'.$seconds.' seconds')->format(DATE_ATOM), [0, 60, 120, 180]),
                'points' => [[$prior ? 10 : 12, 20, 30, 0]],
                'rows' => [[]],
            ];
        }
        return ['rows' => [['192.0.2.44','198.51.100.22'], ['Other','Other']], 'xps' => [$prior ? 80 : 110, 1000]];
    };
    $comparisonService = new TrafficService(new ExporterResolver(), $comparisonClient);
    $focus = ['host' => '192.0.2.44'];
    $without = $comparisonService->panel($device, $port, 60, 'l3bps', 'SrcAddr', $focus, $bounds);
    $baselineCalls = count($comparisonClient->requests);
    assertResult(! isset($without['periodComparison']), 'previous-period queries are opt in');
    $with = $comparisonService->panel($device, $port, 60, 'l3bps', 'SrcAddr', $focus, $bounds, null, false, true);
    $comparison = $with['periodComparison'];
    assertResult($comparison['start'] === '2026-11-20 10:30:00'
        && $comparison['end'] === '2026-11-20 11:00:00'
        && array_column($comparison['series'], 'label') === ['Previous ingress', 'Previous egress']
        && $comparison['series'][0]['values'] === [10.0, 20.0],
        'previous port series align with the current time buckets');
    assertResult(count($comparison['increases']) === 1 && $comparison['increases'][0]['increaseBps'] === 30.0,
        'comparison ranks increases only for concrete pairs present in both responses');
    assertResult(count($comparisonClient->requests) === $baselineCalls + 3 && $with['queryArgs']['compare'] === 'previous',
        'previous port comparison adds two series requests and one ranked request');
    foreach (array_slice($comparisonClient->requests, $baselineCalls) as [$endpoint, $query]) {
        assertResult($query['start'] === '2026-11-20T10:30:00Z' && $query['end'] === '2026-11-20T11:00:00Z'
            && str_contains($query['filter'], 'ExporterAddress = 192.0.2.12')
            && str_contains($query['filter'], '1/1/1')
            && str_contains($query['filter'], '(SrcAddr = 192.0.2.44 OR DstAddr = 192.0.2.44)'),
            'previous requests preserve the exact selected duration and scoped filters');
    }
    $comparisonService->panel($device, $port, 60, 'l3bps', 'SrcAddr', $focus, $bounds, null, false, true);
    assertResult(count($comparisonClient->requests) === $baselineCalls + 3, 'comparison reuses its own cache');
    assertResult(PeriodComparison::align([1300, 1360, 1420], ['timestamps'=>[1000,1060,1180], 'samples'=>[5.0,0.0,8.0]], 300, 1000, 1300)
        === [5.0, 0.0, null], 'a missing prior bucket stays unavailable rather than becoming zero');

    echo "All Sankey, flow diagnostics and comparison tests passed.\n";
}
