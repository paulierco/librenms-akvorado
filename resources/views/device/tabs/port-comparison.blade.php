@php
    $history = $portComparison['history'];
    $fmt = \LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter::class;
@endphp
<div class="panel panel-default akv-viz akv-history-chart" data-akv-initialized="false">
    @include('akvorado-flow::hooks.history-chart-style')
    <div class="panel-heading"><strong>Side-by-side port comparison</strong></div>
    <div class="panel-body">
        @if($portComparison['ports']->count() < 2)
            <p>Two accessible ports are required to compare traffic on this device.</p>
        @else
            <form method="get" action="{{ route('device.akvorado', ['device' => $device->device_id]) }}" class="form-inline" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:12px">
                <input type="hidden" name="section" value="port-comparison">
                @if($bounds)
                    <input type="hidden" name="period" value="{{ $period }}">
                    <input type="hidden" name="start" value="{{ $bounds['start']->getTimestamp() }}">
                    <input type="hidden" name="end" value="{{ $bounds['end']->getTimestamp() }}">
                @endif
                @foreach(['port' => 'Port A', 'compare_port' => 'Port B'] as $field => $label)
                    @php($selected = $field === 'port' ? $selectedPort : $portComparison['second'])
                    <div class="form-group">
                        <label for="akv-compare-{{ $field }}">{{ $label }}</label>
                        <select class="form-control" id="akv-compare-{{ $field }}" name="{{ $field }}" required>
                            <option value="">Select a port</option>
                            @foreach($portComparison['ports'] as $choice)
                                <option value="{{ $choice->port_id }}" @selected($selected?->is($choice))>
                                    {{ $choice->ifName ?: ($choice->ifDescr ?: 'Port '.$choice->port_id) }} (ifIndex {{ $choice->ifIndex }}){{ $choice->ifAlias ? ' · '.\Illuminate\Support\Str::limit($choice->ifAlias, 48) : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <div class="form-group">
                    <label for="akv-compare-window">Time range</label>
                    <select class="form-control" name="comparison_lookback" id="akv-compare-window">
                        @if($bounds)
                            <option value="fixed" selected>Current fixed window</option>
                        @endif
                        @foreach([60 => 'Last hour', 360 => 'Last 6 hours', 1440 => 'Last 24 hours', 10080 => 'Last 7 days'] as $minutes => $label)
                            <option value="{{ $minutes }}" @selected(!$bounds && $period === $minutes)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Compare traffic</button>
            </form>
            @if($portComparison['same'])
                <p class="text-warning">Select two different ports.</p>
            @elseif($history === null)
                <p>Choose two ports to compare their ingress and egress over the same window.</p>
            @endif
        @endif

        @if($history !== null)
            <p>{{ gmdate('Y-m-d H:i:s', $history['start']) }} to {{ gmdate('Y-m-d H:i:s', $history['end']) }} UTC.
                @if($history['step'] !== null)
                    {{ $history['step'] }}-second average buckets.
                @endif
            </p>
            @if($history['charts'] !== [])
                <p><a class="btn btn-default btn-sm" href="{{ route('device.akvorado.csv', [
                    'device' => $device->device_id, 'report' => 'port-comparison',
                    'port' => $selectedPort->port_id, 'compare_port' => $portComparison['second']->port_id,
                    'start' => $history['start'], 'end' => $history['end'], 'period' => $period,
                ]) }}">Download port comparison CSV</a></p>
            @endif
            <p class="text-muted">Each line covers the full selected port. Dashed lines show Akvorado’s sampled L2 estimates. Zero flow rate means no matching samples were reported. Gaps indicate unavailable data or incomplete intervals.</p>
            @if(!$history['exporterMapped'])
                <p class="text-muted">No Akvorado exporter is mapped to this device.</p>
            @elseif($history['ambiguous'] !== [])
                <p class="text-muted">Flow history is unavailable for {{ implode(', ', $history['ambiguous']) }} because the interface name is shared by multiple ports.</p>
            @endif
            <p>
                @foreach([$selectedPort, $portComparison['second']] as $index => $port)
                    @if(!$loop->first) · @endif
                    <a href="{{ route('device.akvorado', ['device' => $device->device_id, 'port' => $port->port_id, 'section' => 'historical-overlay', 'period' => $period, 'start' => $history['start'], 'end' => $history['end']]) }}">Open {{ $history['portLabels'][$index] }} historical overlay</a>
                @endforeach
            </p>
            @foreach(['in' => 'Ingress', 'out' => 'Egress'] as $direction => $label)
                @php($chart = $history['charts'][$direction] ?? null)
                <h4>{{ $label }}</h4>
                @if($chart && ($chart['hasSnmp'] || $chart['hasFlow']))
                    <div class="table-responsive">
                        <table class="table table-condensed">
                            <caption>Average rates and traffic share across buckets available for both ports</caption>
                            <thead><tr><th scope="col">Source</th><th scope="col">{{ $history['portLabels'][0] }}</th><th scope="col">{{ $history['portLabels'][1] }}</th><th scope="col">Matched buckets</th></tr></thead>
                            <tbody>
                                @foreach(['snmp' => 'LibreNMS SNMP', 'flow' => 'Akvorado L2'] as $type => $source)
                                    @php($average = $chart['averages'][$type])
                                    <tr><th scope="row">{{ $source }}</th>
                                        @if($average === null)
                                            <td colspan="2">Unavailable</td><td>0</td>
                                        @else
                                            @foreach($average['rates'] as $index => $rate)
                                                <td>{{ $fmt::bitrate($rate) }}{{ $average['shares'] === null ? '' : ' ('.number_format($average['shares'][$index], 1).'%)' }}</td>
                                            @endforeach
                                            <td>{{ $average['count'] }} / {{ count($chart['labels']) }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @include('akvorado-flow::device.tabs.history-bucket-chart', ['chart' => $chart, 'direction' => $direction, 'label' => $label])
                @else
                    <p class="text-muted">No historical samples are available for this direction in the selected window.</p>
                @endif
            @endforeach
        @endif
    </div>
    @if($history !== null)
        <script type="application/json" class="akv-chart-payload">@json($history['charts'])</script>
    @endif
</div>
@if($history !== null)
    @include('akvorado-flow::hooks.chart-script')
@endif
