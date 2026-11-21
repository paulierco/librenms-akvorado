<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

/**
 * Compare Akvorado flow rate against the latest polled SNMP interface rate.
 *
 * Zero observed flow traffic can coexist with nonzero SNMP interface traffic.
 *
 * Both inputs are *latest instantaneous* values: the flow series uses its last
 * completed bucket and the SNMP value is the most recent poll. They are compared
 * as a coverage ratio, never as an exact equality claim.
 */
final class FlowCoverage
{
    /** A ratio inside this band is treated as consistent observation. */
    public const MATCH_LOW = 0.75;
    public const MATCH_HIGH = 1.25;

    /** Below this SNMP rate a zero flow series carries no useful signal. */
    private const NEGLIGIBLE_BPS = 500000.0;

    public static function comparisonUnavailable(string $metric, array $focus, bool $historical): ?string
    {
        return match (true) {
            $historical => 'Coverage uses the latest LibreNMS poll. A fixed investigation window needs SNMP rates for that same time.',
            $focus !== [] => 'Coverage requires an unfiltered interface view. The LibreNMS counter covers all traffic on the interface.',
            $metric !== 'l2bps' => 'Select L2 bits/s to compare the flow rate with the LibreNMS interface counter.',
            default => null,
        };
    }

    /**
     * @param array<string,mixed>|null $panel TrafficService panel (needs trafficIn/trafficOut/snmpHistory)
     * @param string|null $ifName Selected interface name; null means a whole-exporter view
     * @param array<string,mixed> $context Aggregate/member context from the selected port:
     *        ['aggregate' => bool, 'members' => list<array{ifName:string,...}>]
     * @return array<string,mixed>|null
     */
    public static function forPanel(array $panel, ?string $ifName, array $context = []): ?array
    {
        if ($ifName === null || $ifName === '') {
            return null; // Coverage is a per-interface fact; an exporter-wide view cannot measure it.
        }

        if (isset($context['comparisonUnavailable'])) {
            return [
                'available' => false,
                'reason' => 'scope_not_comparable',
                'ifName' => $ifName,
                'directions' => [],
                'note' => $context['comparisonUnavailable'],
            ];
        }

        // A link-aggregate port is a different measurement case (see aggregateContext()):
        // its inbound flows are attributed to physical members and never to the aggregate,
        // so a flow-vs-SNMP ratio would read 0% and misrepresent a busy LAG. Report that
        // structurally instead of inventing a coverage percentage.
        if (! empty($context['aggregate'])) {
            return self::aggregateContext($panel, $ifName, $context['members'] ?? []);
        }

        $history = $panel['snmpHistory'] ?? null;
        if (! is_array($history)) {
            return [
                'available' => false,
                'reason' => 'no_snmp_history',
                'ifName' => $ifName,
                'directions' => [],
                'note' => 'LibreNMS SNMP polled rates are unavailable for this port, so flow coverage cannot be measured in this render.',
            ];
        }

        $speed = isset($history['speedBps']) && is_numeric($history['speedBps']) ? (float) $history['speedBps'] : null;
        $snmp = [
            'in' => isset($history['currentInBps']) && is_numeric($history['currentInBps']) ? (float) $history['currentInBps'] : null,
            'out' => isset($history['currentOutBps']) && is_numeric($history['currentOutBps']) ? (float) $history['currentOutBps'] : null,
        ];
        $curves = [
            'in' => $panel['trafficIn'] ?? null,
            'out' => $panel['trafficOut'] ?? null,
        ];

        $directions = [];
        foreach (['in', 'out'] as $direction) {
            $curve = $curves[$direction];
            $flowBps = self::flowLast($curve);
            $directions[$direction] = self::classify($direction, $flowBps, $snmp[$direction], $speed);
        }

        $asymmetric = self::isAsymmetric($directions);
        $note = self::note($directions, $asymmetric);

        return [
            'available' => true,
            'ifName' => $ifName,
            'speedBps' => $speed,
            'directions' => $directions,
            'asymmetric' => $asymmetric,
            'note' => $note,
            'snmpSource' => 'latest LibreNMS poll',
            'flowSource' => 'Akvorado last completed bucket',
        ];
    }

    /** Last completed bucket of a flow curve, or null when the direction is unavailable. */
    private static function flowLast(mixed $curve): ?float
    {
        if (! is_array($curve)) { return null; }
        if (! isset($curve['samples']) || ! is_array($curve['samples']) || $curve['samples'] === []) { return null; }
        if (isset($curve['last']) && is_numeric($curve['last'])) { return max(0.0, (float) $curve['last']); }

        $plot = is_array($curve['plot'] ?? null) ? $curve['plot'] : $curve['samples'];
        $tail = end($plot);
        return is_numeric($tail) ? max(0.0, (float) $tail) : null;
    }

    /** @return array<string,mixed> */
    private static function classify(string $direction, ?float $flowBps, ?float $snmpBps, ?float $speedBps): array
    {
        $shareOfSpeed = ($speedBps !== null && $speedBps > 0 && $snmpBps !== null)
            ? round(100 * $snmpBps / $speedBps, 2) : null;

        if ($flowBps === null) {
            return [
                'state' => 'flow_unavailable',
                'label' => 'Flow direction unavailable',
                'flowBps' => null,
                'snmpBps' => $snmpBps,
                'coverage' => null,
                'snmpShareOfSpeed' => $shareOfSpeed,
                'explanation' => 'Akvorado did not return a usable series for this observation direction.',
            ];
        }

        if ($snmpBps === null || $snmpBps <= self::NEGLIGIBLE_BPS) {
            // Zero SNMP too: nothing to compare against.
            return [
                'state' => 'snmp_unavailable',
                'label' => 'SNMP rate unavailable',
                'flowBps' => $flowBps,
                'snmpBps' => $snmpBps,
                'coverage' => null,
                'snmpShareOfSpeed' => $shareOfSpeed,
                'explanation' => 'No usable latest SNMP rate for this direction; flow coverage cannot be computed.',
            ];
        }

        $coverage = round(100 * $flowBps / $snmpBps, 1);

        if ($flowBps <= 0.0) {
            return [
                'state' => 'not_observed',
                'label' => 'Flow not observed',
                'flowBps' => $flowBps,
                'snmpBps' => $snmpBps,
                'coverage' => 0.0,
                'snmpShareOfSpeed' => $shareOfSpeed,
                'explanation' => 'SNMP shows traffic on this direction while Akvorado returns no flow records for it.',
            ];
        }

        $state = match (true) {
            $coverage < self::MATCH_LOW * 100 => 'under_reported',
            $coverage > self::MATCH_HIGH * 100 => 'exceeds_snmp',
            default => 'matched',
        };

        return [
            'state' => $state,
            'label' => match ($state) {
                'under_reported' => 'Flow covers part of SNMP',
                'exceeds_snmp' => 'Flow exceeds SNMP',
                default => 'Flow consistent with SNMP',
            },
            'flowBps' => $flowBps,
            'snmpBps' => $snmpBps,
            'coverage' => $coverage,
            'snmpShareOfSpeed' => $shareOfSpeed,
            'explanation' => match ($state) {
                'under_reported' => 'Sampling or partial interface observation accounts for the shortfall; this is expected with sampled flow export, not proof of lost traffic.',
                'exceeds_snmp' => 'Flow exceeds the polled rate, which points at asymmetry or duplicate observation rather than missing data.',
                default => 'Sampled flow and polled SNMP agree within tolerance for this direction.',
            },
        ];
    }

    /** True when exactly one direction carries flow records while the other has traffic. */
    private static function isAsymmetric(array $directions): bool
    {
        $in = $directions['in']['state'] ?? null;
        $out = $directions['out']['state'] ?? null;
        $observed = static fn (?string $state): bool => $state !== null && $state !== 'not_observed' && $state !== 'flow_unavailable' && $state !== 'snmp_unavailable';
        return $observed($in) !== $observed($out);
    }

    /**
     * Coverage facts for a link-aggregate port.
     *
     * An exporter can report ingress on physical members and egress on the logical aggregate.
     * A comparison against the aggregate counter then requires enough member attribution.
     *
     * @param array<string,mixed> $panel
     * @param list<array{port_id:int,ifIndex:int,ifName:string,membership?:string}> $members
     */
    public static function aggregateContext(array $panel, string $ifName, array $members): array
    {
        $history = $panel['snmpHistory'] ?? null;
        $snmpIn = is_array($history) && isset($history['currentInBps']) && is_numeric($history['currentInBps'])
            ? (float) $history['currentInBps'] : null;
        $snmpOut = is_array($history) && isset($history['currentOutBps']) && is_numeric($history['currentOutBps'])
            ? (float) $history['currentOutBps'] : null;
        $speed = is_array($history) && isset($history['speedBps']) && is_numeric($history['speedBps'])
            ? (float) $history['speedBps'] : null;

        $flowIn = self::flowLast($panel['trafficIn'] ?? null);
        $flowOut = self::flowLast($panel['trafficOut'] ?? null);

        $directions = [];
        // Ingress: never attributable to the aggregate itself.
        $directions['in'] = [
            'state' => $members === [] ? 'aggregate_unattributable' : 'aggregate_member_ingress',
            'label' => $members === [] ? 'Aggregate ingress unattributable' : 'Aggregate ingress on members',
            'flowBps' => $flowIn,
            'snmpBps' => $snmpIn,
            'coverage' => null,
            'snmpShareOfSpeed' => null,
            'explanation' => $members === []
                ? 'This switch cannot sample aggregate ports, and LibreNMS provides no membership for this aggregate, so inbound flow data cannot be attributed.'
                : 'Inbound flow records are attributed to the physical members, never to this aggregate.',
        ];
        // Egress: attributable to the aggregate (layer-2 output decision).
        $directions['out'] = [
            'state' => $flowOut === null ? 'flow_unavailable' : 'aggregate_egress',
            'label' => $flowOut === null ? 'Aggregate egress unavailable' : 'Aggregate egress observed',
            'flowBps' => $flowOut,
            'snmpBps' => $snmpOut,
            'coverage' => null,
            'snmpShareOfSpeed' => null,
            'explanation' => 'Outbound flow records carry the logical aggregate interface, so this direction is directly attributable.',
        ];

        $memberNames = [];
        foreach ($members as $member) {
            $name = is_string($member['ifName'] ?? null) ? (string) $member['ifName'] : '';
            if ($name !== '') { $memberNames[] = $name; }
        }

        $note = $members === []
            ? 'Aggregate ingress cannot be observed on this switch, and member attribution is unavailable: LibreNMS exposes no IF-MIB ports_stack membership for this aggregate, and this plugin does not collect membership from a scheduled SNMP job. Inbound is therefore reported as unattributed, not as zero traffic.'
            : 'Ingress for this link aggregate is observed on the physical members ('.implode(', ', $memberNames).'), not on the aggregate. Enable the member-inclusive view for the true inbound figure; sampling an aggregate port is not possible on this platform.';

        return [
            'available' => true,
            'aggregate' => true,
            'ifName' => $ifName,
            'speedBps' => $speed,
            'directions' => $directions,
            'asymmetric' => false,
            'members' => $memberNames,
            // Member-level coverage is computable because each member has its own SNMP
            // counters; it is supplied by TrafficService when membership is known.
            'memberCoverage' => $panel['memberCoverage'] ?? null,
            'note' => $note,
            'snmpSource' => 'latest LibreNMS poll',
            'flowSource' => 'Akvorado last completed bucket',
        ];
    }

    /**
     * Per-member coverage: compare each member's flow rate with that member's own polled
     * SNMP rate, per direction.
     *
     * Use the member counter for a member-level comparison.
     *
     * @param list<array{port_id:int,ifIndex:int,ifName:string}> $members
     * @param array<string,array{in?:float|null,out?:float|null}> $flowByMember flow rates keyed by ifName
     * @param array<string,array{in?:float|null,out?:float|null}> $snmpByMember polled rates in bits/s keyed by ifName
     * @return list<array{port_id:int,ifName:string,directions:array<string,mixed>}>
     */
    public static function memberCoverage(array $members, array $flowByMember, array $snmpByMember): array
    {
        $result = [];
        foreach ($members as $member) {
            $portId = (int) ($member['port_id'] ?? 0);
            $ifName = is_string($member['ifName'] ?? null) ? (string) $member['ifName'] : '';
            if ($portId <= 0 || $ifName === '') { continue; }
            $flow = $flowByMember[$ifName] ?? [];
            $snmp = $snmpByMember[$ifName] ?? [];
            $directions = [];
            foreach (['in', 'out'] as $direction) {
                $f = isset($flow[$direction]) && is_numeric($flow[$direction]) ? (float) $flow[$direction] : null;
                $b = isset($snmp[$direction]) && is_numeric($snmp[$direction]) ? (float) $snmp[$direction] : null;
                $ratio = ($f !== null && $b !== null && $b > self::NEGLIGIBLE_BPS)
                    ? round(100 * $f / $b, 1) : null;
                $directions[$direction] = [
                    'flowBps' => $f,
                    'snmpBps' => $b,
                    'coverage' => $ratio,
                    'state' => $f === null || $b === null || $b <= self::NEGLIGIBLE_BPS
                        ? 'not_comparable'
                        : ($ratio === null ? 'not_comparable'
                            : (($ratio < self::MATCH_LOW * 100) ? 'under_reported'
                                : (($ratio > self::MATCH_HIGH * 100) ? 'exceeds_snmp' : 'matched'))),
                ];
            }
            $result[] = ['port_id' => $portId, 'ifName' => $ifName, 'directions' => $directions];
        }
        return $result;
    }

    private static function note(array $directions, bool $asymmetric): ?string
    {
        $in = $directions['in']['state'] ?? null;
        $out = $directions['out']['state'] ?? null;

        if ($in === 'not_observed' && $out !== 'not_observed') {
            return 'Only the OutIf direction carries flow records for this port; inbound observations are absent. Do not read the zero ingress as an idle link.';
        }
        if ($out === 'not_observed' && $in !== 'not_observed') {
            return 'Only the InIf direction carries flow records for this port; outbound observations are absent. Do not read the zero egress as an idle link.';
        }
        if ($in === 'not_observed' && $out === 'not_observed') {
            return 'Neither direction returned flow records for this port while SNMP still reports traffic. The interface is probably not sampled by the flow exporter.';
        }
        if ($asymmetric) {
            return 'Flow records cover only one direction of this port; treat per-direction figures as observation-path estimates, not link totals.';
        }
        return null;
    }
}
