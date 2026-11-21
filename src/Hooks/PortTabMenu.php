<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Hooks;

use App\Models\Port;
use App\Models\User;
use LibreNMS\Plugins\AkvoradoFlow\Services\TrafficService;

/** Legacy LibreNMS 26.7-style port Plugins tab visibility workaround. */
final class PortTabMenu extends \App\Plugins\Hooks\PortTabHook
{
    public $view = 'hooks.empty';

    public function authorize(User $user, Port $port): bool
    {
        $current = auth()->user();
        return $current !== null && $current->can('view', $port) && $port->device !== null
            && app(TrafficService::class)->enabledFor($port->device);
    }

    public function data(Port $port): array
    {
        return [];
    }
}
