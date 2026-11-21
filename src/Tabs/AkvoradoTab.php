<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Tabs;

use App\Models\Device;
use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Interfaces\UI\DeviceTab;
use LibreNMS\Plugins\AkvoradoFlow\Services\AkvoradoClient;
use LibreNMS\Plugins\AkvoradoFlow\Services\FlowDiagnostics;
use LibreNMS\Plugins\AkvoradoFlow\Services\FlowQuery;
use LibreNMS\Plugins\AkvoradoFlow\Services\PortAlertInvestigation;
use LibreNMS\Plugins\AkvoradoFlow\Services\PortTrafficHistory;
use LibreNMS\Plugins\AkvoradoFlow\Services\PortVisibility;
use LibreNMS\Plugins\AkvoradoFlow\Services\TrafficService;

final class AkvoradoTab implements DeviceTab
{
    public function visible(Device $device): bool
    {
        return config('akvorado-flow.device_tab', true)
            && (string) config('akvorado-flow.url', '') !== ''
            && Gate::allows('view', $device);
    }
    public function slug(): string { return 'akvorado'; }
    public function icon(): string { return 'fa-area-chart'; }
    public function name(): string { return 'Akvorado'; }

    public function data(Device $device, Request $request): array
    {
        Gate::authorize('view', $device);
        abort_unless($this->visible($device), 404);

        $port = $this->authorizedPort($device, $request->query('port'));
        $section = $request->query('section');
        $lookback = $request->query('comparison_lookback');
        $rollingComparison = $section === 'port-comparison' && is_string($lookback) && ctype_digit($lookback);
        $period = $rollingComparison ? $lookback : $request->query('period', (string) config('akvorado-flow.window_minutes', 60));
        $minutes = FlowQuery::window(is_scalar($period) ? (int) $period : 60);
        $metric = FlowQuery::metric(is_string($request->query('metric')) ? $request->query('metric') : 'l3bps');
        $dimension = FlowQuery::dimension(is_string($request->query('dimension')) ? $request->query('dimension') : 'SrcAddr');
        // Only narrow validated fields reach Akvorado; malformed user filters are rejected.
        try {
            $focus = FlowQuery::focus($request->query());
            $bounds = $rollingComparison ? null : FlowQuery::bounds($request->query('start'), $request->query('end'));
            if (! $rollingComparison && $bounds === null && $request->query('alert_at') !== null) {
                if ($port === null) {
                    throw new \InvalidArgumentException('An alert investigation requires a port');
                }
                $bounds = PortAlertInvestigation::bounds($request->query('alert_at'));
            }
        } catch (\InvalidArgumentException $e) {
            abort(422, 'Invalid Akvorado investigation filter or time range.');
        }
        if ($request->query('section') === 'diagnostics') {
            return [
                'diagnostics' => app(FlowDiagnostics::class)->forDevice($device, $minutes, $bounds),
                'exporterReady' => false,
                'period' => $minutes,
                'bounds' => $bounds,
            ];
        }

        if ($request->query('section') === 'port-visibility') {
            return [
                'visibility' => app(PortVisibility::class)->forDevice($device, auth()->user(), $minutes, $bounds),
                'exporterReady' => false,
                'period' => $minutes,
                'bounds' => $bounds,
            ];
        }

        if ($request->query('section') === 'historical-overlay') {
            abort_unless($port !== null, 404);

            return [
                'historicalOverlay' => app(PortTrafficHistory::class)->forPort($device, $port, $minutes, $bounds),
                'exporterReady' => false,
                'selectedPort' => $port,
                'period' => $minutes,
                'bounds' => $bounds,
            ];
        }

        if ($section === 'port-comparison') {
            $second = $this->authorizedPort($device, $request->query('compare_port'));
            $ports = $device->ports()->hasAccess(auth()->user())->orderBy('ifName')->orderBy('port_id')
                ->get(['port_id', 'ifIndex', 'ifName', 'ifDescr', 'ifAlias']);
            $same = $port !== null && $second !== null && $port->is($second);
            $history = $port !== null && $second !== null && ! $same
                ? app(PortTrafficHistory::class)->forComparison($device, $port, $second, $minutes, $bounds)
                : null;

            return [
                'portComparison' => ['ports' => $ports, 'second' => $second, 'same' => $same, 'history' => $history],
                'exporterReady' => false,
                'selectedPort' => $port,
                'period' => $minutes,
                'bounds' => $bounds,
            ];
        }

        $traffic = app(TrafficService::class);
        $exporterStatus = $traffic->exporterStatus($device);
        if ($exporterStatus !== 'confirmed') {
            return [
                'panel' => ['flowState' => 'exporter_'.$exporterStatus, 'deviceId' => (int) $device->device_id],
                'exporterReady' => false,
                'selectedPort' => $port,
                'ports' => collect(),
                'period' => $minutes,
                'metric' => $metric,
                'dimension' => $dimension,
                'focus' => $focus,
                'bounds' => $bounds,
            ];
        }

        $dimensions = FlowQuery::dimensionOptions(app(AkvoradoClient::class)->investigationDimensions());
        $layer2Options = FlowQuery::layer2Options($dimensions);
        abort_unless(isset($dimensions[$dimension])
            && array_diff_key($focus, FlowQuery::filterLabels($dimensions)) === [],
            422, 'The current Akvorado schema does not advertise this filter or grouping.');

        // Limit selector size. Selected port is resolved and authorized independently.
        $max = min(1500, max(20, (int) config('akvorado-flow.max_ports_in_selector', 1500)));
        $ports = $device->ports()->hasAccess(auth()->user())->where('ifIndex', '>', 0)
            ->orderBy('ifName')->limit($max)->get(['port_id','ifIndex','ifName','ifAlias']);

        // The selected Sankey preset is loaded on demand and allowlisted server-side.
        $showSankey = $request->query('section') === 'sankey';
        $sankeyView = FlowQuery::sankeyMode(is_string($request->query('sankey_view')) ? $request->query('sankey_view') : 'ports');
        $panel = $traffic->panel(
            $device, $port, $minutes, $metric, $dimension, $focus, $bounds, $showSankey ? $sankeyView : null,
            $port !== null && $request->query('lag_members') === '1', $request->query('compare') === 'previous'
        );
        if ($showSankey) {
            // Only authorized, visible ports may be targets of Sankey click-through.
            // Ignore ambiguous ifNames; never pick a random port from a stack.
            $names = []; $duplicates = [];
            foreach ($ports as $candidate) {
                $name = (string) ($candidate->ifName ?? '');
                if ($name === '') { continue; }
                if (isset($names[$name])) { $duplicates[$name] = true; }
                $names[$name] = (int) $candidate->port_id;
            }
            foreach ($duplicates as $name => $_) { unset($names[$name]); }
            $panel['sankeyPortMap'] = $names;
        }
        return [
            'panel' => $panel,
            'exporterReady' => true,
            'selectedPort' => $port,
            'ports' => $ports,
            'period' => $minutes,
            'metric' => $metric,
            'dimension' => $dimension,
            'dimensionOptions' => $dimensions,
            'layer2Options' => $layer2Options,
            'focus' => $focus, 'bounds' => $bounds,
        ];
    }

    private function authorizedPort(Device $device, mixed $id): ?Port
    {
        if ($id === null || $id === '') {
            return null;
        }
        abort_unless(is_string($id) && ctype_digit($id) && (int) $id > 0 && (string)(int)$id === ltrim($id, '0'), 404);
        $port = $device->ports()->where('port_id', (int)$id)->first();
        abort_unless($port !== null, 404);
        Gate::authorize('view', $port);

        return $port;
    }
}
