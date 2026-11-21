<div class="panel panel-default akv-viz akv-history-chart" data-akv-initialized="false">
    @include('akvorado-flow::hooks.history-chart-style')
    <div class="panel-heading"><strong>Historical SNMP and Akvorado overlay</strong> <small class="text-muted">{{ $selectedPort->ifName ?: $selectedPort->ifDescr }}</small></div>
    <div class="panel-body">
        <form method="get" action="{{ route('device.akvorado', ['device' => $device->device_id]) }}" class="form-inline" style="margin-bottom:12px">
            <input type="hidden" name="section" value="historical-overlay">
            <input type="hidden" name="port" value="{{ $selectedPort->port_id }}">
            <label for="akv-history-period">Lookback</label>
            <select class="form-control" name="period" id="akv-history-period">
                @foreach([60 => '1 hour', 360 => '6 hours', 1440 => '24 hours', 10080 => '7 days'] as $minutes => $label)
                    <option value="{{ $minutes }}" @selected($period === $minutes)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-default">Compare traffic</button>
        </form>
        <p>{{ gmdate('Y-m-d H:i:s', $historicalOverlay['start']) }} to {{ gmdate('Y-m-d H:i:s', $historicalOverlay['end']) }} UTC.
            @if($historicalOverlay['step'] !== null)
                {{ $historicalOverlay['step'] }}-second average buckets.
            @endif
        </p>
        <p class="text-muted">The comparison uses the full port scope. Akvorado reports sampled L2 estimates. A zero flow rate means no matching sampled traffic was reported. Gaps indicate unavailable data or incomplete buckets.</p>
        @if(! $historicalOverlay['exporterMapped'])
            <p class="text-muted">No Akvorado exporter is mapped to this device.</p>
        @endif
        @foreach(['in' => 'Ingress', 'out' => 'Egress'] as $direction => $label)
            @php($series = $historicalOverlay['charts'][$direction] ?? null)
            <h4>{{ $label }}</h4>
            @if($series && ($series['hasSnmp'] || $series['hasFlow']))
                @if(! $series['hasSnmp']) <p class="text-muted">SNMP history is unavailable for this direction.</p> @endif
                @if(! $series['hasFlow']) <p class="text-muted">Akvorado L2 history is unavailable on the shared intervals.</p> @endif
                @include('akvorado-flow::device.tabs.history-bucket-chart', ['chart' => $series, 'direction' => $direction, 'label' => $label])
            @else
                <p class="text-muted">No comparable historical samples in this window.</p>
            @endif
        @endforeach
    </div>
    <script type="application/json" class="akv-chart-payload">@json($historicalOverlay['charts'])</script>
</div>
@include('akvorado-flow::hooks.chart-script')
