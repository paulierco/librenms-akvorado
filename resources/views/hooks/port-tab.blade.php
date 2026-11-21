<div style="margin-top:10px;margin-bottom:8px">
    <a class="btn btn-primary btn-sm" href="{{ route('device.akvorado', ['device' => $port->device_id, 'port' => $port->port_id]) }}">
        <i class="fa fa-area-chart" aria-hidden="true"></i> Open full Akvorado analysis for {{ $port->ifName }}
    </a>
</div>
@include('akvorado-flow::hooks.panel', [
    'panel' => $panel,
    'heading' => 'Akvorado — Port Traffic',
    'context' => ($port->ifName ?? 'Port').' (ifIndex '.($port->ifIndex ?? '?').')',
])
