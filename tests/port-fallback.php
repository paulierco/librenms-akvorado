<?php

declare(strict_types=1);

namespace Illuminate\Support\Facades {
    final class Cache {
        public static function remember(string $key, int $ttl, \Closure $callback): array { return $callback(); }
    }
    final class Log {
        public static array $warnings = [];
        public static function warning(string $message, array $context): void { self::$warnings[] = ['message' => $message, 'context' => $context]; }
    }
}

namespace LibreNMS\Plugins\AkvoradoFlow\Services {
    final class ExporterResolver {
        public function resolve(object $device): array { return ['ip' => '192.0.2.10', 'method' => 'explicit']; }
    }
    final class AkvoradoClient {
        public array $reject = [];
        public array $observed = [];
        public array $requests = [];
        public function post(string $endpoint, array $payload): array
        {
            $filter = $payload['filter'];
            $mode = str_contains($filter, '(InIfName =') ? 'both' : (str_contains($filter, 'AND InIfName =') ? 'in' : (str_contains($filter, 'AND OutIfName =') ? 'out' : 'device'));
            $this->observed[] = [$endpoint, $mode];
            $this->requests[] = [$endpoint, $payload];
            if ($endpoint === 'graph/line' && $payload['dimensions'] === [] && in_array($mode, $this->reject, true)) {
                throw new \RuntimeException('Akvorado API request failed (HTTP 422)');
            }
            if ($endpoint === 'graph/line') {
                return ['t' => ['2026-11-20T11:00:00Z', '2026-11-20T11:01:00Z', '2026-11-20T11:02:00Z'], 'points' => [[1000,2000,1500]], 'axis' => [1], 'rows' => [['443']], 'average' => [1500], 'total' => [10000]];
            }
            return ['rows' => [['198.51.100.1', '198.51.100.2']], 'xps' => [2500], 'axis' => [1]];
        }
    }

    require __DIR__.'/../src/Services/FlowQuery.php';
    require __DIR__.'/../src/Services/FlowFormatter.php';
    require __DIR__.'/../src/Services/PortHealth.php';
    require __DIR__.'/../src/Services/FlowCoverage.php';
    require __DIR__.'/../src/Services/SnmpHistory.php';
    require __DIR__.'/../src/Services/FlowAvailability.php';
    require __DIR__.'/../src/Services/LagMembers.php';
    require __DIR__.'/../src/Services/TrafficService.php';

    function config(string $name, mixed $fallback = null): mixed {
        return match ($name) {
            'akvorado-flow.window_minutes' => 60,
            'akvorado-flow.cache_seconds' => 60,
            'akvorado-flow.url' => 'https://akvorado.example.invalid',
            default => $fallback,
        };
    }
    function verify(bool $condition, string $name): void {
        if (! $condition) { fwrite(STDERR, "FAIL {$name}\n"); exit(1); }
        echo "PASS {$name}\n";
    }

    $device = (object) ['device_id' => 1];
    $port = new class {
        public int $ifIndex = 1001;
        public string $ifName = '1/1/1';
        public int $port_id = 17;
        public function statistics(): object {
            return new class {
                public function first(): ?object { return null; }
            };
        }
    };

    $oneError = new AkvoradoClient();
    $oneError->reject = ['out'];
    $one = (new TrafficService(new ExporterResolver(), $oneError))->panel($device, $port);
    verify(str_contains($oneError->observed[0][0], 'graph/line') && $oneError->observed[0][1] === 'in', 'named interface filter reaches inlet query');
    verify($one['ok'] && count($one['trafficIn']['samples']) === 3 && $one['trafficOut'] === null && ! $one['portCombinedFallback'], 'InIf succeeds when OutIf returns HTTP 422');
    verify(count(array_filter($one['partial'], fn ($msg) => str_contains($msg, 'OUT interface query unavailable'))) === 1, 'failed direction clearly labeled');

    $twoErrors = new AkvoradoClient();
    $twoErrors->reject = ['in', 'out'];
    $two = (new TrafficService(new ExporterResolver(), $twoErrors))->panel($device, $port);
    verify($two['ok'] && $two['portCombinedFallback'] && count($two['traffic']['samples']) === 3, 'combined port-only fallback works');
    verify(count(array_filter($twoErrors->observed, fn ($call) => $call === ['graph/line','device'])) === 0, 'no exporter-wide query is used as port fallback');

    $allErrors = new AkvoradoClient();
    $allErrors->reject = ['in', 'out', 'both'];
    $all = (new TrafficService(new ExporterResolver(), $allErrors))->panel($device, $port);
    verify(! $all['ok'] && str_contains($all['message'], 'HTTP 422'), 'all port query failures are reported, not silently masked');

    $empty = new AkvoradoClient();
    $empty->reject = [];
    $data = (new TrafficService(new ExporterResolver(), $empty))->panel($device, $port);
    verify($data['ok'] && ! $data['portCombinedFallback'], 'normal working port query is unchanged');
    $withFilters = new AkvoradoClient();
    $focus = ['src'=>'192.0.2.99','dst_port'=>'443'];
    $bounds = [
        'start'=>new \DateTimeImmutable('2026-11-20T11:00:00Z'),
        'end'=>new \DateTimeImmutable('2026-11-20T11:30:00Z'),
    ];
    $focused = (new TrafficService(new ExporterResolver(), $withFilters))->panel($device, $port,60,'l3bps','DstPort',$focus,$bounds);
    verify($focused['ok'] && $focused['zoomed'] && $focused['focus']===$focus, 'focused panel metadata');
    verify($focused['snmpHistory']['start']===$bounds['start']->getTimestamp() && $focused['snmpHistory']['end']===$bounds['end']->getTimestamp(), 'SNMP graphs synchronize with Akvorado zoom');
    verify($all['snmpHistory']['graphs']['errorsDiscards']['type']==='port_errors', 'SNMP graph metadata survives Akvorado failure');
    verify(count($withFilters->requests)>=7, 'all expected graph requests issued');
    verify(count(array_filter($withFilters->requests, static function ($req) {
        $payload=$req[1];
        return str_contains($payload['filter'],'ExporterAddress = 192.0.2.10')
            && str_contains($payload['filter'],'SrcAddr = 192.0.2.99')
            && str_contains($payload['filter'],'DstPort = 443')
            && str_contains($payload['filter'],'1/1/1')
            && $payload['start']==='2026-11-20T11:00:00Z'
            && $payload['end']==='2026-11-20T11:30:00Z';
    }))===count($withFilters->requests), 'every API request retains port, focus and selected time window');
    verify($focused['queryArgs']['port']===17 && $focused['queryArgs']['start']===$bounds['start']->getTimestamp(), 'shareable investigation query arguments');
    echo "All port resiliency tests passed.\n";
}
