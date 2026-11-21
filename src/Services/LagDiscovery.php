<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

/**
 * Alcatel OmniSwitch link-aggregation MIB parsing.
 *
 * Reference implementation for the two vendor columns, validated on an OS6860N stack
 * (suffix == physical ifIndex, .13 == attached logical ifIndex, .24 == selected aggregate
 * number). This plugin deliberately runs no scheduled SNMP collection, so these functions
 * are not wired into the web path: membership is presented only when LibreNMS itself knows
 * it through IF-MIB ports_stack. Kept for diagnostics and the platform-behaviour tests.
 */
final class LagDiscovery
{
    public const ATTACHED_OID = '.1.3.6.1.4.1.6486.801.1.2.1.13.1.1.2.1.1.13';
    public const SELECTED_OID = '.1.3.6.1.4.1.6486.801.1.2.1.13.1.1.2.1.1.24';

    /** @return array<int,int>  physical ifIndex => SNMP value; nonnumeric data is rejected */
    public static function parseColumn(array $values, string $oid): array
    {
        $parsed = [];
        if (count($values) > 4096) { throw new \UnexpectedValueException('Oversized LAG SNMP column'); }
        foreach ($values as $key => $raw) {
            $key = (string) $key;
            if (! str_starts_with($key, $oid.'.')) { throw new \UnexpectedValueException('Unexpected SNMP OID'); }
            $suffix = substr($key, strlen($oid) + 1);
            if (! preg_match('/\A[1-9][0-9]{0,9}\z/D', $suffix)) { throw new \UnexpectedValueException('Invalid SNMP row index'); }
            if (! is_string($raw) && ! is_int($raw)) { throw new \UnexpectedValueException('Non-integer SNMP value'); }
            $value = trim((string) $raw);
            if (! preg_match('/\A-?[0-9]{1,10}\z/D', $value)) { throw new \UnexpectedValueException('Invalid SNMP integer'); }
            $index = (int) $suffix;
            if (isset($parsed[$index])) { throw new \UnexpectedValueException('Duplicate SNMP index'); }
            $parsed[$index] = (int) $value;
        }
        return $parsed;
    }

    /**
     * Build membership from **verified** matching ifIndexes (not name guesses).
     * Static LAGs use SelectedAggNumber ONLY if ifDescr unambiguously says
     * "Static Aggregate Number N", and attached ID is zero. A selected static
     * port is not asserted to be operationally attached.
     *
     * @param list<array<string,mixed>> $ports
     * @param array<int,int> $attached
     * @param array<int,int> $selected
     * @return array<int,list<array{port_id:int,ifIndex:int,ifName:string,membership:string}>>
     */
    public static function build(array $ports, array $attached, array $selected): array
    {
        $byIndex = []; $lagByIndex = []; $staticByNumber = []; $ambiguous = [];
        foreach ($ports as $port) {
            $id = (int) ($port['port_id'] ?? 0);
            $index = (int) ($port['ifIndex'] ?? 0);
            $name = (string) ($port['ifName'] ?? '');
            if ($id <= 0 || $index <= 0 || $name === '' || strlen($name) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $name)) { continue; }
            if (isset($byIndex[$index])) { $ambiguous[$index] = true; continue; }
            $byIndex[$index] = $port;
            if (($port['ifType'] ?? '') !== 'ieee8023adLag') { continue; }
            $lagByIndex[$index] = $port;
            $descr = (string) ($port['ifDescr'] ?? '');
            // Non-dynamic aggregates are named differently by model: "Static Aggregate
            // Number N" and "Omnichannel Aggregate Number N" both occur on OS6860N. A
            // selected-only member (attached ifIndex 0) is resolved through this number,
            // so every non-dynamic aggregate label must be accepted, not just "Static".
            // Measured: aggregate 20 is "Omnichannel Aggregate Number 20 ref 40000020",
            // while its members 1/1/17 and 2/1/17 report attached=0, selected=20 — the
            // old Static-only match left those members unresolved (zero members).
            if (preg_match('/\b(?:Static|Omnichannel) Aggregate Number ([1-9][0-9]*)\b/i', $descr, $m)) {
                $number = (int) $m[1];
                // Cross-check the switch's numeric LAG identifier; avoid unrelated labels.
                if ($index === 40000000 + $number) { $staticByNumber[$number] = $port; }
            }
        }
        foreach ($ambiguous as $index => $_) { unset($byIndex[$index], $lagByIndex[$index]); }
        $result = [];
        foreach ($attached as $physicalIndex => $attachedIndex) {
            $member = $byIndex[$physicalIndex] ?? null;
            if (! $member || ($member['ifType'] ?? '') === 'ieee8023adLag') { continue; }
            $lag = $lagByIndex[$attachedIndex] ?? null;
            $kind = 'attached';
            if (! $lag && $attachedIndex === 0) {
                $number = $selected[$physicalIndex] ?? 0;
                $lag = $staticByNumber[$number] ?? null;
                $kind = 'selected-static';
            }
            if (! $lag || (int) $lag['port_id'] === (int) $member['port_id']) { continue; }
            $id = (int) $lag['port_id'];
            $result[$id][] = [
                'port_id' => (int) $member['port_id'],
                'ifIndex' => (int) $physicalIndex,
                'ifName' => (string) $member['ifName'],
                'membership' => $kind,
            ];
        }
        foreach ($result as $id => $members) {
            $unique = array_unique(array_column($members, 'port_id'));
            $names = array_unique(array_column($members, 'ifName'));
            if (count($members) !== count($unique) || count($members) !== count($names)
                || count($members) > LagMembers::MAX_MEMBERS) {
                unset($result[$id]); continue;
            }
            usort($result[$id], static fn ($a, $b) => strnatcmp($a['ifName'], $b['ifName']));
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }
}
