<div class="panel panel-default akv-viz akv-history-overlay" data-akv-initialized="false">
    <style>
        .akv-history-overlay { --akv-card-bg:#fff; --akv-ink:#1f2d42; --akv-muted:#68758a; --akv-rule:#e8edf4 }
        .dark .akv-history-overlay { --akv-card-bg:#2e3338; --akv-ink:#c8c8c8; --akv-muted:#acb6bf; --akv-rule:#3e444c }
        .akv-history-overlay .akv-chart-wrap { position:relative; margin-top:9px; height:252px }
        .akv-history-overlay canvas { display:block; width:100%; height:252px }
        .akv-history-overlay .akv-tooltip { display:none; position:absolute; pointer-events:none; white-space:pre; padding:7px 9px; background:#202632; color:#fff; border-radius:4px; font-size:12px }
        .akv-history-overlay .akv-legend { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px }
        .akv-history-overlay .akv-legend-item { border:1px solid var(--akv-rule); border-radius:4px; background:var(--akv-card-bg); color:var(--akv-ink); padding:4px 8px }
        .akv-history-overlay .akv-legend-item[aria-pressed="false"] { opacity:.65; text-decoration:line-through }
        .akv-history-overlay .akv-dot { display:inline-block; width:8px; height:8px; margin-right:6px; border-radius:50% }
    </style>
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
                <div class="akv-chart-wrap" data-akv-chart="history_{{ $direction }}">
                    <canvas role="img" aria-label="{{ $label }} SNMP and Akvorado bitrate in matched time buckets"></canvas>
                    <div class="akv-tooltip"></div>
                </div>
                <div class="akv-legend" data-akv-legend="history_{{ $direction }}"></div>
                <details style="margin-bottom:15px">
                    <summary>View {{ strtolower($label) }} values</summary>
                    <div class="table-responsive">
                        <table class="table table-striped table-condensed">
                            <thead><tr><th scope="col">Bucket end (UTC)</th><th scope="col">LibreNMS SNMP</th><th scope="col">Akvorado L2</th></tr></thead>
                            <tbody>
                                @foreach($series['labels'] as $index => $time)
                                    <tr>
                                        <td>{{ $time }}</td>
                                        @foreach($series['series'] as $source)
                                            @php($rate = $source['values'][$index])
                                            <td>{{ $rate === null ? 'Unavailable' : \LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter::bitrate($rate) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @else
                <p class="text-muted">No comparable historical samples in this window.</p>
            @endif
        @endforeach
    </div>
    <script type="application/json" class="akv-chart-payload">@json($historicalOverlay['charts'])</script>
</div>
@include('akvorado-flow::hooks.chart-script')
