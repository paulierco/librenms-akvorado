<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

final class ExporterResolver
{
    /**
     * Resolve an exporter identity for a device.
     *
     * An 'assumed management IP' match is deliberately NOT treated as confirmed:
     * the exporter's flow source address frequently differs from LibreNMS's SNMP
     * address, for example because the device uses a separate exporter interface.
     *
     * @return array{ip:string,method:string,confirmed:bool}|null
     */
    public function resolve(object $device): ?array
    {
        $raw = (string) config('akvorado-flow.exporter_map', '{}');
        $map = json_decode($raw, true);
        if (! is_array($map)) {
            $map = [];
        }
        $keys = [(string) ($device->device_id ?? ''), (string) ($device->hostname ?? '')];
        foreach ($keys as $key) {
            if ($key !== '' && isset($map[$key]) && $this->validAddress($map[$key])) {
                return ['ip' => $map[$key], 'method' => 'explicit', 'confirmed' => true];
            }
        }
        // Opt-in fallback only. It is a candidate, not a confirmed exporter.
        if (config('akvorado-flow.auto_match_management_ip', false)
            && $this->validAddress($device->ip ?? null)) {
            return ['ip' => (string) $device->ip, 'method' => 'assumed management IP', 'confirmed' => false];
        }
        return null;
    }

    /**
     * True only when a candidate exporter address appears in Akvorado's own
     * ExporterAddress dimension. This separates 'we guessed an address' from 'this
     * device actually exports flows'.
     *
     * Note: Akvorado's widget/exporters returns exporter *names* (e.g. "example-switch"),
     * not addresses, so name membership cannot confirm an IP guess. The address is
     * read from the flow data itself instead.
     * @param list<string> $knownAddresses
     */
    public static function isKnownExporter(?array $resolved, array $knownAddresses): bool
    {
        if ($resolved === null) { return false; }
        $ip = (string) ($resolved['ip'] ?? '');
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) { return false; }
        foreach ($knownAddresses as $address) {
            if (is_string($address) && $address === $ip) { return true; }
        }
        return false;
    }

    private function validAddress(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
}
