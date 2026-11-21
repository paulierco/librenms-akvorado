<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Hooks;

use App\Models\Device;
use Illuminate\Contracts\View\View;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Plugins\AkvoradoFlow\Services\TrafficService;

final class DeviceOverview implements DeviceOverviewHook
{
    public function authorize(Device $device): bool
    {
        $user = auth()->user();
        return $user !== null && $user->can('view', $device)
            && app(TrafficService::class)->enabledFor($device);
    }

    public function handle(string $pluginName, Device $device, TrafficService $traffic): View
    {
        return view('akvorado-flow::hooks.device-overview', [
            'device' => $device,
            'panel' => $traffic->panel($device),
        ]);
    }
}
