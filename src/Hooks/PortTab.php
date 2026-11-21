<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Hooks;

use App\Models\Port;
use Illuminate\Contracts\View\View;
use LibreNMS\Interfaces\Plugins\Hooks\PortTabHook;
use LibreNMS\Plugins\AkvoradoFlow\Services\TrafficService;

final class PortTab implements PortTabHook
{
    public function authorize(Port $port): bool
    {
        $user = auth()->user();
        return $user !== null && $user->can('view', $port) && $port->device !== null
            && app(TrafficService::class)->enabledFor($port->device);
    }

    public function handle(string $pluginName, Port $port, TrafficService $traffic): View
    {
        return view('akvorado-flow::hooks.port-tab', [
            'port' => $port,
            'panel' => $traffic->panel($port->device, $port),
        ]);
    }
}
