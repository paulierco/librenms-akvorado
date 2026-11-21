<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * LibreNMS → Akvorado interface-metadata bridge.
 *
 * Akvorado's static provider accepts an `exporter-sources` URL whose body is transformed by
 * jq into objects with `exportersubnet`, `skipmissinginterfaces`, `default` and `interfaces`
 * Each interface has an index, name, description, and speed.
 *
 * This endpoint reproduces that contract from LibreNMS's own polling data, so Akvorado need
 * not poll every switch a second time. It is read-only, paginated and token-guarded: the API
 * middleware group (`auth:sanctum`) enforces the bearer token, and device access is checked
 * per row so a token cannot reveal devices its user may not see.
 *
 * Speed is emitted in bits/s (LibreNMS's unit); Akvorado's static interface `speed` is Mbps,
 * so the payload divides by 1e6 to avoid the 1e6 unit trap.
 */
final class ExporterMetadataController
{
    /** Rows per page keeps the response bounded for large installations. */
    public const PAGE_SIZE = 2000;

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(config('akvorado-flow.metadata_bridge_enabled', false), 404);

        $page = max(1, min(1000, (int) $request->query('page', 1)));
        $perPage = max(1, min(self::PAGE_SIZE, (int) config('akvorado-flow.metadata_bridge_page_size', self::PAGE_SIZE)));
        $user = $request->user();

        // Only devices the caller may view contribute interfaces.
        $devices = Device::query()
            ->where('disabled', 0)
            ->where('ignore', 0)
            ->where('status', 1)
            ->orderBy('device_id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get(['device_id', 'hostname', 'ip', 'sysName']);

        $exporters = [];
        foreach ($devices as $device) {
            if ($user !== null && ! Gate::forUser($user)->allows('view', $device)) { continue; }

            $interfaces = [];
            foreach ($device->ports()->where('deleted', 0)->where('ifIndex', '>', 0)
                // This LibreNMS build has no `ifHighSpeed` ports column (verified), so the
                // selection must use only columns that exist.
                ->orderBy('ifIndex')->get(['ifIndex', 'ifName', 'ifAlias', 'ifSpeed']) as $port) {
                $ifIndex = (int) ($port->ifIndex ?? 0);
                if ($ifIndex <= 0) { continue; }
                $name = trim((string) ($port->ifName ?? ''));
                if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) { continue; }

                $speedMbps = $this->speedMbps($port);
                $description = substr((string) ($port->ifAlias ?? ''), 0, 255);
                // Akvorado marks Name, Description and Speed as required, and rejects the
                // whole exporter entry when any is empty. Omit the interface instead: with
                // skipmissinginterfaces the flow keeps flowing and falls through to SNMP.
                if ($description === '' || $speedMbps <= 0) { continue; }

                $interfaces[] = [
                    'ifindex' => $ifIndex,
                    'name' => $name,
                    'description' => $description,
                    // Akvorado's static provider expects speed in Mbps.
                    'speed' => $speedMbps,
                ];
            }
            if ($interfaces === []) { continue; }

            $exporterName = substr(trim((string) ($device->sysName ?? $device->hostname ?? '')), 0, 255);
            if ($exporterName === '') { continue; }

            $exporters[] = [
                // Exporter fields are squashed into this object by Akvorado, so `name` MUST be
                // at the top level, not nested under `default`.
                'name' => $exporterName,
                // One exporter subnet per device; Akvorado matches flow source addresses by subnet.
                'exportersubnet' => $this->subnet((string) ($device->ip ?? '') ?? ''),
                // Keep flows flowing for indexes this feed does not describe rather than dropping
                // them: unattributed traffic is preferable to invisible traffic.
                'skipmissinginterfaces' => true,
                'default' => [
                    // Default is validated as a full Interface, so all three required fields
                    // must be present. 1 Mbps is a placeholder label, not a claim about speed.
                    'name' => $exporterName,
                    'description' => 'default interface (metadata fallback)',
                    'speed' => 1,
                ],
                'interfaces' => $interfaces,
            ];
        }

        return response()->json([
            'exporters' => $exporters,
            'page' => $page,
            'per_page' => $perPage,
            'count' => count($exporters),
        ], 200, [], JSON_UNESCAPED_SLASHES);
    }

    /** A single-host subnet (/32 or /128) so Akvorado matches exactly this exporter address. */
    private function subnet(string $ip): ?string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) { return null; }
        return $ip . (str_contains($ip, ':') ? '/128' : '/32');
    }

    /**
     * Link speed in Mbps from LibreNMS's ifSpeed (bits/s).
     *
     * LibreNMS stores high-speed interfaces in ifSpeed. Missing rates return zero.
     */
    private function speedMbps(object $port): int
    {
        $raw = is_numeric($port->ifSpeed ?? null) ? (float) $port->ifSpeed : 0.0;
        if ($raw <= 0) { return 0; }
        return max(1, (int) round($raw / 1e6));
    }
}
