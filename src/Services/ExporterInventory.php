<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Exporter inventory: what Akvorado knows about, and what it is actually receiving.
 *
 * Two distinct facts are reported, never conflated:
 * - membership in Akvorado's exporter widget (names and addresses it knows about,
 *   including historical ones), and
 * - the exporter addresses observed in the ExporterAddress dimension of flow data in
 *   a short probe window, i.e. who is sending flows now.
 *
 * The widget can return exporter names. The recent address probe checks activity separately.
 */
final class ExporterInventory
{
    public const PROBE_MINUTES = 60;

    public function __construct(private readonly AkvoradoClient $client, private readonly ExporterResolver $resolver) {}

    /**
     * Widget membership keyed by canonical address, plus the raw name list.
     * @return array{ok:bool,addresses:array<string,bool>,names:array<string,bool>,message:string}
     */
    public function known(): array
    {
        try {
            $response = Cache::remember('akvorado-flow-v060-known-exporters',
                max(60, min(3600, (int) config('akvorado-flow.inventory_cache_seconds', 300))),
                fn (): array => $this->client->get('widget/exporters'));
            $parsed = self::normalizeWidget($response);
            return ['ok' => true, 'addresses' => $parsed['addresses'], 'names' => $parsed['names'], 'message' => ''];
        } catch (\Throwable $e) {
            // Fail unknown, never describe an unreachable widget as an absent exporter.
            return ['ok' => false, 'addresses' => [], 'names' => [], 'message' => 'Akvorado exporter inventory unavailable; status is unknown.'];
        }
    }

    /**
     * Exporter addresses observed in recent flow data. One cached probe, not a
     * per-device query; the window is bounded and the result is only addresses.
     * @return array{ok:bool,addresses:array<string,bool>,minutes:int,message:string}
     */
    public function observedAddresses(): array
    {
        $minutes = max(5, min(1440, (int) config('akvorado-flow.inventory_probe_minutes', self::PROBE_MINUTES)));
        try {
            $addresses = Cache::remember('akvorado-flow-v060-observed-addresses-'.$minutes,
                max(60, min(3600, (int) config('akvorado-flow.inventory_cache_seconds', 300))),
                function () use ($minutes): array {
                    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    $response = $this->client->post('graph/line', [
                        'dimensions' => ['ExporterAddress'],
                        'start' => $now->modify('-'.$minutes.' minutes')->format('Y-m-d\TH:i:s\Z'),
                        'end' => $now->format('Y-m-d\TH:i:s\Z'),
                        'limit' => 50, 'points' => 5, 'units' => 'l3bps', 'bidirectional' => false,
                    ]);
                    return self::normalizeAddressRows($response);
                });
            return ['ok' => true, 'addresses' => $addresses, 'minutes' => $minutes, 'message' => ''];
        } catch (\Throwable $e) {
            Log::warning('Akvorado plugin: exporter address probe unavailable', ['exception_type' => get_class($e)]);
            return ['ok' => false, 'addresses' => [], 'minutes' => $minutes, 'message' => 'Recent flow activity could not be measured; current export status is unknown.'];
        }
    }

    /**
     * Keep both names and valid IP addresses. Names are a legitimate exporter identity
     * in Akvorado's widget and must not be discarded merely because they are not IPs.
     * @return array{addresses:array<string,bool>,names:array<string,bool>}
     */
    public static function normalizeWidget(array $response): array
    {
        $entries = $response['exporters'] ?? null;
        if (! is_array($entries) || count($entries) > 100000) {
            throw new \RuntimeException('Unexpected exporter widget response');
        }
        $addresses = [];
        $names = [];
        foreach ($entries as $entry) {
            if (! is_string($entry) || $entry === '' || strlen($entry) > 255) { continue; }
            if (preg_match('/[\x00-\x1F\x7F]/', $entry)) { continue; }
            $canonical = self::canonicalIp($entry);
            if ($canonical !== null) {
                $addresses[$canonical] = true;   // an address identity
            } else {
                $names[$entry] = true;           // a name identity, e.g. "example-switch"
            }
        }
        return ['addresses' => $addresses, 'names' => $names];
    }

    /**
     * Addresses only, from an ExporterAddress grouping. The 'Other' remainder and empty
     * labels are never treated as exporters.
     * @return array<string,bool>
     */
    public static function normalizeAddressRows(array $response): array
    {
        $rows = $response['rows'] ?? [];
        if (! is_array($rows) || count($rows) > 100000) {
            throw new \RuntimeException('Unexpected exporter address response');
        }
        $addresses = [];
        foreach ($rows as $row) {
            $value = is_array($row) ? ($row[0] ?? null) : null;
            if (! is_scalar($value)) { continue; }
            $entry = trim((string) $value);
            if ($entry === '' || strcasecmp($entry, 'Other') === 0) { continue; }
            $canonical = self::canonicalIp($entry);
            if ($canonical !== null) { $addresses[$canonical] = true; }
        }
        return $addresses;
    }

    /**
     * Device status: mapping, widget membership and observed activity.
     * Never merges the three into a single claim.
     * @param array{ok:bool,addresses:array<string,bool>,names:array<string,bool>,message:string} $inventory
     * @param array{ok:bool,addresses:array<string,bool>,minutes:int,message:string} $observed
     */
    public function status(object $device, array $inventory, array $observed): array
    {
        $resolved = $this->resolver->resolve($device);
        if ($resolved === null) { return ['status' => 'unmapped', 'ip' => null, 'mapping' => null, 'activity' => 'unknown']; }
        $canonical = self::canonicalIp((string) $resolved['ip']) ?? '';
        $listed = isset($inventory['addresses'][$canonical]);
        $knownName = isset($inventory['names'][(string) ($device->sysName ?? '')]) || isset($inventory['names'][self::baseName((string) ($device->hostname ?? ''))]);
        $status = ! $inventory['ok'] ? 'unknown' : (($listed || $knownName) ? 'known' : 'not_listed');
        $activity = ! $observed['ok'] ? 'unknown' : (isset($observed['addresses'][$canonical]) ? 'observed' : 'not_observed');
        return [
            'status' => $status, 'ip' => $resolved['ip'], 'mapping' => $resolved['method'],
            'confirmed' => $resolved['confirmed'] ?? false,
            'activity' => $activity, 'probeMinutes' => $observed['minutes'] ?? null,
        ];
    }

    /** Hostname without its DNS suffix, e.g. "example-switch" from "example-switch.example.net". */
    private static function baseName(string $hostname): string
    {
        $trimmed = trim($hostname);
        $dot = strpos($trimmed, '.');
        return $dot === false ? $trimmed : substr($trimmed, 0, $dot);
    }

    private static function canonicalIp(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) { return null; }
        // Akvorado can return IPv4-mapped IPv6 identities for IPv4 exporters.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\x00", 10)."\xff\xff") {
            $packed = substr($packed, 12, 4);
        }
        return bin2hex($packed);
    }
}
