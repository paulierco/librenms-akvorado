<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Http\Controllers;

use App\Http\Controllers\DeviceController;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Delegates to LibreNMS's device controller/layout for both older and newer
 * versions of DeviceController::index().
 *
 * In newer versions index() reads $request->route('tab', 'overview'), while
 * older versions accept an explicit third argument. The custom plugin route
 * has a literal /akvorado suffix (not a {tab} placeholder), so it MUST set
 * the route parameter before delegating.
 */
final class AkvoradoDeviceController
{
    public function __invoke(Request $request, string $device): mixed
    {
        abort_unless(config('akvorado-flow.device_tab', true), 404);

        // The route /device/{device}/akvorado does not define {tab}.
        // Modern LibreNMS uses $request->route('tab', 'overview') to select
        // the view, so without this line it renders Overview by mistake.
        $route = $request->route();
        abort_unless($route !== null, 404);
        $route->setParameter('tab', 'akvorado');

        // Older LibreNMS accepts $current_tab as the third argument, while
        // newer LibreNMS ignores that argument. Supplying both is compatible.
        // DeviceController authorizes device access; AkvoradoTab authorizes
        // the user again and checks exporter visibility.
        return app(DeviceController::class)->index($request, $device, 'akvorado');
    }
}
