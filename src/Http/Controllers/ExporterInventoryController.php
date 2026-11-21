<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Plugins\AkvoradoFlow\Services\ExporterInventory;

final class ExporterInventoryController
{
    public function __invoke(Request $request, ExporterInventory $inventory): mixed
    {
        abort_unless(config('akvorado-flow.inventory_enabled', true)
            && config('akvorado-flow.device_tab', true)
            && (string) config('akvorado-flow.url', '') !== '', 404);

        $search = $request->query('q', '');
        abort_unless(is_string($search) && strlen($search) <= 80, 422);
        $search = trim($search);
        $query = Device::query()->hasAccess($request->user());
        if ($search !== '') {
            // Literal substring matching, not user-provided LIKE wildcards.
            $term = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $search);
            $query->where('hostname', 'like', '%'.$term.'%');
        }
        $devices = $query->orderBy('hostname')->simplePaginate(40);
        $known = $inventory->known();
        // Second, independent fact: which exporters Akvorado is observing now.
        $observed = $inventory->observedAddresses();
        $rows = [];
        foreach ($devices as $device) {
            // Defense in depth; no entity from the upstream exporter list is
            // displayed unless matched to an authorized LibreNMS device.
            if (! Gate::allows('view', $device)) { continue; }
            $rows[] = ['device' => $device, 'exporter' => $inventory->status($device, $known, $observed)];
        }
        return view('akvorado-flow::inventory.index', [
            'rows' => $rows, 'devices' => $devices, 'search' => $search,
            'available' => $known['ok'], 'notice' => $known['message'],
            'observedAvailable' => $observed['ok'], 'observedNotice' => $observed['message'],
            'observedMinutes' => $observed['minutes'],
        ]);
    }
}
