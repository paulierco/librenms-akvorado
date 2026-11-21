<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class TrafficService
{
    public function __construct(
        private readonly ExporterResolver $resolver,
        private readonly AkvoradoClient $client,
    ) {}

    /** Port hooks use exporter status to decide whether to render inline traffic. */
    public function enabledFor(object $device): bool
    {
        return in_array($this->exporterStatus($device), ['confirmed', 'verification_unavailable'], true);
    }

    /** Resolve traffic eligibility when the Akvorado page is opened. */
    public function exporterStatus(object $device): string
    {
        if ((string) config('akvorado-flow.url', '') === '') {
            return 'unconfigured';
        }
        $resolved = $this->resolver->resolve($device);
        if ($resolved === null) {
            return 'unmapped';
        }
        if (! empty($resolved['confirmed'])) {
            return 'confirmed';
        }
        // A guessed management IP must be verified against the addresses Akvorado
        // actually receives flows from. widget/exporters only carries exporter names,
        // so the address is read from the ExporterAddress dimension of a short probe
        // and shared across device checks for a minute.
        try {
            $addresses = Cache::remember(
                'akvorado-exporter-addresses-v1',
                (int) config('akvorado-flow.cache_seconds', 60),
                function (): array {
                    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    $response = $this->client->post('graph/line', [
                        'dimensions' => ['ExporterAddress'],
                        'start' => $now->modify('-15 minutes')->format('Y-m-d\TH:i:s\Z'),
                        'end' => $now->format('Y-m-d\TH:i:s\Z'),
                        'limit' => 50, 'points' => 5, 'units' => 'l3bps',
                    ]);
                    $rows = $response['rows'] ?? null;
                    if (! is_array($rows)) {
                        throw new \UnexpectedValueException('Akvorado did not return exporter rows');
                    }
                    $addresses = [];
                    foreach ($rows as $row) {
                        $value = is_array($row) ? ($row[0] ?? null) : null;
                        if (is_scalar($value)) { $addresses[] = (string) $value; }
                    }
                    return $addresses;
                }
            );
            return ExporterResolver::isKnownExporter($resolved, $addresses) ? 'confirmed' : 'not_observed';
        } catch (\Throwable $e) {
            Log::warning('Akvorado plugin: exporter verification unavailable', [
                'failure' => get_class($e),
            ]);
            return 'verification_unavailable';
        }
    }

    /** @return array<string,mixed> */
    public function panel(object $device, ?object $port = null, ?int $requestedMinutes = null, string $requestedMetric = 'l3bps', string $requestedDimension = 'SrcAddr', array $requestedFocus = [], ?array $requestedBounds = null, ?string $sankeyView = null, bool $includeLagMembers = false, bool $comparePrevious = false): array
    {
        $exporter = $this->resolver->resolve($device);
        if (! $exporter) { return ['ok' => false, 'message' => 'No exporter mapping for this device.']; }
        $index = null;
        $interfaceName = null;
        if ($port !== null) {
            $index = filter_var($port->ifIndex ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            if ($index === false || $index === null) { return ['ok' => false, 'message' => 'This interface has no usable ifIndex.']; }
            $interfaceName = (string) ($port->ifName ?? '');
            if ($interfaceName === '') { return ['ok' => false, 'message' => 'This port has no SNMP ifName for Akvorado interface matching.']; }
        }
        $isAggregate = ($port->ifType ?? null) === 'ieee8023adLag';
        $lagMembers = $isAggregate ? LagMembers::forPort($port) : [];
        $lagIncluded = $includeLagMembers && count($lagMembers) > 0;
        $interfaceScope = $lagIncluded
            ? array_merge([$interfaceName], array_column($lagMembers, 'ifName'))
            : $interfaceName;
        $minutes = FlowQuery::window($requestedMinutes ?? (int) config('akvorado-flow.window_minutes', 60));
        $metric = FlowQuery::metric($requestedMetric);
        $dimension = FlowQuery::dimension($requestedDimension);
        $focus = FlowQuery::focus($requestedFocus);
        $sankeyView = $sankeyView !== null ? FlowQuery::sankeyMode($sankeyView) : null;
        $now = $requestedBounds['end'] ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($requestedBounds === null) { $now = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0); }
        $start = $requestedBounds['start'] ?? null;
        $isZoomed = $start !== null;
        $comparisonUnavailable = FlowCoverage::comparisonUnavailable($metric, $focus, $isZoomed);

        $scope = $port ? 'port'.(string) ($port->port_id ?? $index) : 'device';
        // Include the LAG flag so member-inclusive requests have their own cache entry.
        $key = 'akvorado-flow-v071:'.hash('sha256', json_encode([
            $exporter['ip'], $interfaceScope, $scope, $minutes, $metric, $dimension, $focus,
            $now->getTimestamp(), $start?->getTimestamp(), $lagIncluded ? 'lag-members' : 'logical',
        ], JSON_THROW_ON_ERROR));
        try {
            $data = Cache::remember($key, (int) config('akvorado-flow.cache_seconds', 60), function () use ($exporter, $index, $interfaceName, $minutes, $now, $metric, $dimension, $focus, $start, $interfaceScope, $lagIncluded): array {
                $ip = $exporter['ip'];
                // Device overview: one exporter-scoped line. On a port, query each
                // observation direction independently. A rejection or timeout in
                // OutIf must not discard a valid InIf result (or vice versa).
                $partial = [];
                $portCombinedFallback = false;
                $traffic = null;
                $trafficIn = null;
                $trafficOut = null;
                if ($interfaceName === null) {
                    $traffic = FlowFormatter::line(
                        $this->client->post('graph/line', FlowQuery::line($ip, null, $minutes, $now, $metric, 'both', null, 10, $focus, $start)),
                        $metric, $minutes
                    );
                } else {
                    $directionFailures = [];
                    foreach (['in', 'out'] as $direction) {
                        try {
                            $curve = FlowFormatter::line(
                                $this->client->post('graph/line', FlowQuery::line($ip, $interfaceScope, $minutes, $now, $metric, $direction, null, 10, $focus, $start)),
                                $metric, $minutes
                            );
                            if ($direction === 'in') {
                                $trafficIn = $curve;
                            } else {
                                $trafficOut = $curve;
                            }
                        } catch (\Throwable $e) {
                            $code = self::queryFailureCode($e);
                            $directionFailures[$direction] = $code;
                            $partial[] = strtoupper($direction).' interface query unavailable ('.$code.')';
                            Log::warning('Akvorado plugin: interface direction query failed', [
                                'direction' => $direction,
                                'failure' => $code,
                                'exception_type' => get_class($e),
                            ]);
                        }
                    }
                    // A combined OR query is the safe fallback when both separate
                    // queries fail. It is still restricted to this exact enriched interface name.
                    // Never fall back to ALL switch traffic; that would mislabel
                    // other ports' data as belonging to the selected interface.
                    if ($trafficIn === null && $trafficOut === null) {
                        try {
                            $traffic = FlowFormatter::line(
                                $this->client->post('graph/line', FlowQuery::line($ip, $interfaceScope, $minutes, $now, $metric, 'both', null, 10, $focus, $start)),
                                $metric, $minutes
                            );
                            $portCombinedFallback = true;
                            $partial[] = 'Only combined InIf/OutIf observations are shown; separate direction estimates are unavailable';
                        } catch (\Throwable $e) {
                            $code = self::queryFailureCode($e);
                            Log::warning('Akvorado plugin: combined interface query failed', [
                                'failure' => $code,
                                'exception_type' => get_class($e),
                            ]);
                            throw new \RuntimeException('Port-scoped Akvorado query failed (combined direction)', 0, $e);
                        }
                    }
                }
                // Optional datasets must not prevent the basic traffic graph from rendering.
                try {
                    $packets = $metric === 'pps' && $interfaceScope === null ? $traffic : FlowFormatter::line($this->client->post('graph/line', FlowQuery::line($ip, $interfaceScope, $minutes, $now, 'pps', 'both', null, 10, $focus, $start)), 'pps', $minutes);
                } catch (\Throwable $e) { $packets = null; $partial[] = 'Packets/sec not available'; }
                try {
                    $conversations = FlowFormatter::conversations($this->client->post('graph/sankey', FlowQuery::sankey($ip, $interfaceScope, $minutes, $now, ['SrcAddr', 'DstAddr'], 24, $focus, $start)), 24);
                } catch (\Throwable $e) { $conversations = []; $partial[] = 'Conversations unavailable'; }
                try {
                    $protocolPorts = FlowFormatter::protocolPorts($this->client->post('graph/sankey', FlowQuery::sankey($ip, $interfaceScope, $minutes, $now, ['Proto', 'DstPort'], 20, $focus, $start)), 20);
                } catch (\Throwable $e) { $protocolPorts = []; $partial[] = 'Protocol/port breakdown unavailable'; }
                // One additional Akvorado grouped time series for a dashboard-style
                // stacked chart. It always displays L3 bps, independent of the primary
                // metric selected by the user. Fail independently of core traffic.
                try {
                    $portTimeline = FlowFormatter::groupedSeries(
                        $this->client->post('graph/line', FlowQuery::line($ip, $interfaceScope, $minutes, $now, 'l3bps', 'both', 'DstPort', 6, $focus, $start)), 7
                    );
                } catch (\Throwable $e) { $portTimeline = ['labels' => [], 'series' => [], 'unit' => 'l3bps']; $partial[] = 'Destination-port history unavailable'; }
                try {
                    $ranks = FlowFormatter::rankings($this->client->post('graph/line', FlowQuery::line($ip, $interfaceScope, $minutes, $now, $metric, 'both', $dimension, 12, $focus, $start)), $metric, 12);
                } catch (\Throwable $e) { $ranks = []; $partial[] = 'Top grouped traffic unavailable'; }
                try {
                    $detailedFlows = FlowFormatter::detailedFlows(
                        $this->client->post('graph/sankey', FlowQuery::sankey(
                            $ip, $interfaceScope, $minutes, $now,
                            ['SrcAddr', 'DstAddr', 'Proto', 'DstPort'], 25, $focus, $start
                        )), 25
                    );
                } catch (\Throwable $e) {
                    $detailedFlows = [];
                    $partial[] = 'Detailed conversations unavailable';
                }
                return [
                    'ok' => true, 'exporter' => $ip, 'mapping' => $exporter['method'],
                    'metric' => $metric, 'dimension' => $dimension, 'rankings' => $ranks,
                    'focus' => $focus, 'detailedFlows' => $detailedFlows,
                    'ifIndex' => $index, 'ifName' => $interfaceName, 'minutes' => $minutes,
                    'traffic' => $traffic, 'trafficIn' => $trafficIn, 'trafficOut' => $trafficOut,
                    'portCombinedFallback' => $portCombinedFallback,
                    'lagIncluded' => $lagIncluded,
                    'partialDirections' => count($directionFailures ?? []),
                    'packets' => $packets, 'conversations' => $conversations,
                    'topSources' => FlowFormatter::topTalkers($conversations),
                    'topDestinations' => FlowFormatter::topTalkers($conversations, 'destination'),
                    'protocolPorts' => $protocolPorts,
                    'portTimeline' => $portTimeline,
                    // Interface-name attribution for the stacked destination-port view:
                    // how much returned traffic has no named interface, rather than hiding
                    // it inside 'Other'. Computed here so the view only formats numbers.
                    'portAttribution' => FlowFormatter::attribution($portTimeline['series'] ?? []),
                    'portBreakdown' => FlowFormatter::portBreakdown($protocolPorts),
                    'partial' => $partial,
                    'generatedAt' => $now->format('Y-m-d H:i').' UTC',
                ];
            });
            // Safety net for already-served stale entries and for a mapping refreshed after
            // the key was written: if members were requested but the payload is not
            // member-inclusive, recompute rather than return a misleading panel.
            if ($includeLagMembers && count($lagMembers) > 0 && ($data['lagIncluded'] ?? false) !== true) {
                Cache::forget($key);
                Log::warning('Akvorado plugin: stale non-member payload discarded', [
                    'requested' => 'lag-members',
                ]);
                return $this->panel($device, $port, $requestedMinutes, $requestedMetric,
                    $requestedDimension, $requestedFocus, $requestedBounds, $sankeyView, true, $comparePrevious);
            }
            // Optional LAG-member attribution charts: one grouped line API call
            // per direction, only when the operator selects member-inclusive mode.
            // These are observed interface-name groups, NOT unique packet totals.
            $data['lagMemberTraffic'] = ['in' => null, 'out' => null];
            if ($lagIncluded) {
                $memberNames = array_column($lagMembers, 'ifName');
                foreach (['in', 'out'] as $direction) {
                    $groupKey = 'akvorado-lag-series-v072:'.hash('sha256', json_encode([
                        $exporter['ip'], $memberNames, $direction, $minutes,
                        $now->getTimestamp(), $start?->getTimestamp(), $focus,
                    ], JSON_THROW_ON_ERROR));
                    try {
                        $group = Cache::remember($groupKey, (int) config('akvorado-flow.cache_seconds', 60),
                            function () use ($exporter, $memberNames, $minutes, $now, $focus, $start, $direction) {
                                return FlowFormatter::groupedSeries($this->client->post('graph/line',
                                    FlowQuery::lagMemberLine($exporter['ip'], $memberNames, $minutes, $now, $direction, $focus, $start)
                                ), 12);
                            });
                        // Exclude Akvorado's Other bucket and unknown interface names.
                        $group['series'] = array_values(array_filter($group['series'] ?? [],
                            static fn ($row) => in_array($row['label'] ?? null, $memberNames, true)));
                        $data['lagMemberTraffic'][$direction] = $group;
                    } catch (\Throwable $e) {
                        Log::warning('Akvorado plugin: LAG member trend unavailable', [
                            'direction' => $direction, 'failure' => self::queryFailureCode($e),
                            'exception_type' => get_class($e),
                        ]);
                        $data['lagMemberTraffic'][$direction] = null;
                    }
                }
            }
            $data['flowState'] = FlowAvailability::evaluate($data);
            if ($sankeyView !== null && $data['flowState'] === 'has_flows') {
                // An independent cache means switching Sankey presets does not
                // re-run the much more expensive core dashboard queries.
                $sankeyKey = 'akvorado-sankey-v071:attribution-v2:'.hash('sha256', json_encode([
                    $exporter['ip'], $interfaceScope, $minutes, $focus,
                    $now->getTimestamp(), $start?->getTimestamp(), $sankeyView,
                ], JSON_THROW_ON_ERROR));
                $sankeyResult = Cache::remember($sankeyKey, (int) config('akvorado-flow.cache_seconds', 60),
                    function () use ($exporter, $interfaceName, $minutes, $now, $focus, $start, $sankeyView, $interfaceScope): array {
                        try {
                            $dimensions = FlowQuery::sankeyPreset($sankeyView);
                            $response = $this->client->post('graph/sankey', FlowQuery::sankey(
                                $exporter['ip'], $interfaceScope, $minutes, $now, $dimensions, 14, $focus, $start
                            ));
                            return ['diagram' => SankeyDiagram::fromResponse($response, $dimensions, 32), 'failure' => null];
                        } catch (\Throwable $e) {
                            $code = self::queryFailureCode($e);
                            Log::warning('Akvorado plugin: Sankey query failed', [
                                'failure' => $code,
                                'exception_type' => get_class($e),
                            ]);
                            return ['diagram' => null, 'failure' => $code];
                        }
                    });
                $data['sankey'] = $sankeyResult['diagram'];
                $data['sankeyFailure'] = $sankeyResult['failure'];
            }
            $data['sankeyView'] = $sankeyView;
            if ($comparePrevious) {
                $data['periodComparison'] = (new PeriodComparison($this->client))->forPanel(
                    $data, $exporter['ip'], $interfaceScope, $minutes, $metric, $focus,
                    $start ?? $now->modify('-'.$minutes.' minutes'), $now
                );
            }
            // SNMP interface counters are read independently of the flow cache.
            // An Akvorado API timeout does not affect these values.
            $data['snmp'] = $port !== null ? PortHealth::snapshot($port) : null;
            $data['snmpHistory'] = $port !== null ? SnmpHistory::forPort(
                $port, ($start ?? $now->modify('-'.$minutes.' minutes'))->getTimestamp(), $now->getTimestamp()
            ) : null;
            // Per-direction flow coverage: flow rate vs latest polled SNMP rate.
            // Computed from data already in this panel; it adds no API or SNMP calls.
            // Aggregate ports get structural wording instead of a false 0% ratio.
            // Member-level coverage needs each member's own flow rate and polled SNMP rate.
            // LibreNMS's port stack must supply member relationships.
            if ($lagIncluded && $comparisonUnavailable === null) {
                $flowByMember = [];
                $snmpByMember = [];
                foreach ($lagMembers as $member) {
                    $name = (string) ($member['ifName'] ?? '');
                    if ($name === '') { continue; }
                    foreach (['in', 'out'] as $direction) {
                        if (! isset($flowByMember[$name])) { $flowByMember[$name] = []; }
                        try {
                            $curve = FlowFormatter::line($this->client->post('graph/line',
                                FlowQuery::line($exporter['ip'], $name, $minutes, $now, 'l2bps', $direction, null, 1, $focus, $start)), 'l2bps', $minutes);
                            $flowByMember[$name][$direction] = $curve['samples'] === [] ? null : (float) $curve['last'];
                        } catch (\Throwable $e) {
                            $flowByMember[$name][$direction] = null;
                        }
                    }
                    // LagMembers returns identity only (port_id/ifIndex/ifName), so the rates
                    // must be read from the member's own port record. These are already-polled
                    // columns, so this adds no SNMP request.
                    $memberPort = $port !== null
                        ? \App\Models\Port::query()->where('device_id', (int) $port->device_id)
                            ->where('port_id', (int) ($member['port_id'] ?? 0))
                            ->first(['port_id', 'ifIndex', 'ifName', 'ifInOctets_rate', 'ifOutOctets_rate'])
                        : null;
                    $snmpByMember[$name] = [
                        'in' => $memberPort?->ifInOctets_rate !== null ? (float) $memberPort->ifInOctets_rate * 8 : null,
                        'out' => $memberPort?->ifOutOctets_rate !== null ? (float) $memberPort->ifOutOctets_rate * 8 : null,
                    ];
                }
                $data['memberCoverage'] = FlowCoverage::memberCoverage($lagMembers, $flowByMember, $snmpByMember);
            } else {
                $data['memberCoverage'] = null;
            }
            $data['coverage'] = FlowCoverage::forPanel($data, $interfaceName, [
                'aggregate' => $isAggregate,
                'members' => $lagMembers,
                'comparisonUnavailable' => $comparisonUnavailable,
            ]);
            $data['deviceId'] = (int) ($device->device_id ?? 0);
            $data['portId'] = $port !== null ? (int) ($port->port_id ?? 0) : null;
            $data['zoomed'] = $isZoomed;
            $data['zoomStart'] = $start?->getTimestamp();
            $data['zoomEnd'] = $isZoomed ? $now->getTimestamp() : null;
            $data['focus'] = $focus;
            $data['filterExpression'] = FlowQuery::filter($exporter['ip'], $interfaceScope, 'both', $focus);
            $data['lagMembers'] = $lagMembers;
            $data['lagIncluded'] = $lagIncluded;
            $data['queryArgs'] = array_merge([
                'period' => $minutes, 'metric' => $metric, 'dimension' => $dimension,
            ], $port !== null ? ['port' => (int) ($port->port_id ?? 0)] : [],
                $isZoomed ? ['start' => $start->getTimestamp(), 'end' => $now->getTimestamp()] : [], $focus);
            if ($lagIncluded) {
                $data['queryArgs']['lag_members'] = '1';
            }
            if ($sankeyView !== null) {
                $data['queryArgs']['sankey_view'] = $sankeyView;
            }
            if ($comparePrevious) {
                $data['queryArgs']['compare'] = 'previous';
            }
            return $data;
        } catch (\Throwable $e) {
            // Never log flow payloads or credentials. Error codes are enough to
            // distinguish HTTP rejection, timeout and transport failure.
            $code = self::queryFailureCode($e);
            Log::warning('Akvorado plugin: traffic query failed', [
                'failure' => $code,
                'exception_type' => get_class($e),
            ]);
            return [
                'ok' => false,
                'flowState' => 'query_error',
                // Historical SNMP graphs remain available even if Akvorado fails.
                'snmp' => $port !== null ? PortHealth::snapshot($port) : null,
                'snmpHistory' => $port !== null ? SnmpHistory::forPort(
                    $port, ($start ?? $now->modify('-'.$minutes.' minutes'))->getTimestamp(), $now->getTimestamp()
                ) : null,
                'coverage' => FlowCoverage::forPanel([
                    'ok' => false,
                    'trafficIn' => null,
                    'trafficOut' => null,
                    'snmpHistory' => $port !== null ? SnmpHistory::forPort(
                        $port, ($start ?? $now->modify('-'.$minutes.' minutes'))->getTimestamp(), $now->getTimestamp()
                    ) : null,
                ], $interfaceName, [
                    'aggregate' => $isAggregate,
                    'members' => $lagMembers,
                    'comparisonUnavailable' => $comparisonUnavailable,
                ]),
                'lagMembers' => $lagMembers, 'lagIncluded' => $lagIncluded,
                'message' => $interfaceName !== null
                    ? 'Akvorado could not complete queries for this interface ('.$code.'). No other switch traffic has been substituted. Run the bundled read-only port diagnostic.'
                    : 'Akvorado traffic query failed ('.$code.'). Check Akvorado and LibreNMS application logs.',
            ];
        }
    }
    /** No request payloads, IP addresses, credentials or upstream response bodies are logged. */
    private static function queryFailureCode(\Throwable $e): string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (preg_match('/\bHTTP\s+(\d{3})\b/i', $current->getMessage(), $matches)) {
                return 'HTTP '.$matches[1];
            }
            if (str_contains(strtolower($current->getMessage()), 'timed out')
                || str_contains(strtolower($current->getMessage()), 'timeout')) {
                return 'timeout';
            }
        }
        return 'transport/query error';
    }
}
