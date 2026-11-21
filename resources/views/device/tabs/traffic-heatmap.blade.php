@php
    $history = $trafficHeatmap['history'];
    $heatmapClass = \LibreNMS\Plugins\AkvoradoFlow\Services\TrafficHeatmap::class;
    $fmt = \LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter::class;
    $lookbacks = $heatmapClass::LOOKBACKS;
    $directions = $heatmapClass::DIRECTIONS;
    $oldestDrill = time() - 691200;
@endphp
<div class="panel panel-default akv-heatmap">
    <style>
        .akv-heatmap { --akv-hm-empty:#e9eef4; --akv-hm-gap:#bcc8d4; --akv-hm-0:#e2e8f0; --akv-hm-1:#b9dfff; --akv-hm-2:#82c1f5; --akv-hm-3:#459cdc; --akv-hm-4:#2275b7; --akv-hm-5:#124a85 }
        .dark .akv-heatmap { --akv-hm-empty:#303944; --akv-hm-gap:#637285; --akv-hm-0:#394451; --akv-hm-1:#244f79; --akv-hm-2:#2a6ea0; --akv-hm-3:#3e8bc5; --akv-hm-4:#59a9df; --akv-hm-5:#88cafa }
        .akv-heatmap .akv-hm-table { border-collapse:separate; border-spacing:3px; width:100%; table-layout:fixed; min-width:700px }
        .akv-heatmap .akv-hm-table th { text-align:center; white-space:nowrap; font-size:12px }
        .akv-heatmap .akv-hm-table th:first-child { width:108px; text-align:left }
        .akv-heatmap .akv-hm-cell { display:block; min-height:22px; border-radius:3px }
        .akv-heatmap .akv-hm-cell:focus-visible { outline:2px solid currentColor; outline-offset:1px }
        .akv-heatmap [data-level="0"] { background:var(--akv-hm-0) }
        .akv-heatmap [data-level="1"] { background:var(--akv-hm-1) }
        .akv-heatmap [data-level="2"] { background:var(--akv-hm-2) }
        .akv-heatmap [data-level="3"] { background:var(--akv-hm-3) }
        .akv-heatmap [data-level="4"] { background:var(--akv-hm-4) }
        .akv-heatmap [data-level="5"] { background:var(--akv-hm-5) }
        .akv-heatmap [data-level="gap"] { background:repeating-linear-gradient(135deg,var(--akv-hm-empty) 0 3px,var(--akv-hm-gap) 3px 5px) }
        .akv-heatmap .akv-hm-legend { display:flex; flex-wrap:wrap; align-items:center; gap:5px; margin-top:8px; font-size:12px }
        .akv-heatmap .akv-hm-swatch { display:inline-block; width:16px; height:13px; border-radius:2px }
    </style>
    <div class="panel-heading"><strong>Traffic heatmap</strong></div>
    <div class="panel-body">
        <form method="get" action="{{ route('device.akvorado', ['device' => $device->device_id]) }}" class="form-inline" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:12px">
            <input type="hidden" name="section" value="traffic-heatmap">
            <input type="hidden" name="heatmap_days" value="{{ $trafficHeatmap['lookback'] }}">
            @if($bounds)
                <input type="hidden" name="start" value="{{ $bounds['start']->getTimestamp() }}">
                <input type="hidden" name="end" value="{{ $bounds['end']->getTimestamp() }}">
            @endif
            <div class="form-group">
                <label for="akv-hm-port">Interface</label>
                <select class="form-control" id="akv-hm-port" name="port">
                    <option value="">All observed device traffic</option>
                    @foreach($trafficHeatmap['ports'] as $choice)
                        <option value="{{ $choice->port_id }}" @selected($selectedPort?->is($choice))>
                            {{ $choice->ifName ?: ($choice->ifDescr ?: 'Port '.$choice->port_id) }} (ifIndex {{ $choice->ifIndex }}){{ $choice->ifAlias ? ' · '.\Illuminate\Support\Str::limit($choice->ifAlias, 48) : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            @if($selectedPort || $trafficHeatmap['ports']->isNotEmpty())
                <div class="form-group">
                    <label for="akv-hm-direction">Port direction</label>
                    <select class="form-control" id="akv-hm-direction" name="heatmap_direction">
                        @foreach($directions as $value => $label)
                            <option value="{{ $value }}" @selected($trafficHeatmap['direction'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="form-group">
                <label for="akv-hm-lookback">Time range</label>
                <select class="form-control" id="akv-hm-lookback" name="heatmap_lookback">
                    @if($bounds)
                        <option value="fixed" selected>Current fixed window</option>
                    @endif
                    @foreach($lookbacks as $days => $label)
                        <option value="{{ $days }}" @selected(!$bounds && $trafficHeatmap['lookback'] === $days)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-default">Update heatmap</button>
        </form>
        @if(isset($history['rows']))
            <p>{{ gmdate('Y-m-d H:i', $history['start']) }} to {{ gmdate('Y-m-d H:i', $history['end']) }} UTC
                · {{ $selectedPort ? $selectedPort->ifName.' · '.$directions[$trafficHeatmap['direction']] : 'All observed device traffic' }}
                · {{ $history['observed'] }} of {{ $history['hours'] }} hours with sampled traffic
            </p>
            <p class="text-muted">Color shows average sampled L3 bitrate per complete UTC hour. Hover for the hourly average and peak collector bucket. Select an active cell within the last eight days to open that hour.</p>
            <div class="table-responsive">
                <table class="akv-hm-table" aria-label="Hourly traffic by date and UTC hour">
                    <thead><tr>
                        <th scope="col">Date (UTC)</th>
                        @for($hour = 0; $hour < 24; $hour++)
                            <th scope="col">{{ sprintf('%02d', $hour) }}</th>
                        @endfor
                    </tr></thead>
                    <tbody>
                        @foreach($history['rows'] as $date => $row)
                            <tr>
                                <th scope="row" title="{{ $date }}">{{ gmdate('D d M', strtotime($date.' UTC')) }}</th>
                                @for($hour = 0; $hour < 24; $hour++)
                                    @php
                                        $cell = $row[$hour] ?? null;
                                        $mean = $cell['stats']['mean'] ?? null;
                                        $level = $mean === null ? 'gap' : ($mean === 0.0 ? 0 : (int)ceil(5 * $mean / $history['highest']));
                                        $description = $mean === null ? 'Unavailable' : ($mean === 0.0 ? 'No sampled traffic'
                                            : $fmt::bitrate($mean).' average, '.$fmt::bitrate($cell['stats']['peak']).' peak bucket');
                                        $title = $cell === null ? 'Outside the selected window' : gmdate('Y-m-d H:i', $cell['start']).' UTC · '.$description;
                                    @endphp
                                    <td>
                                        @if($cell !== null && $mean > 0 && $cell['start'] >= $oldestDrill)
                                            <a class="akv-hm-cell" data-level="{{ $level }}" title="{{ $title }}" href="{{ route('device.akvorado', [
                                                'device' => $device->device_id, 'port' => $selectedPort?->port_id, 'metric' => 'l3bps',
                                                'start' => $cell['start'], 'end' => $cell['start'] + 3600,
                                            ]) }}"><span class="sr-only">{{ $title }}. Open hour.</span></a>
                                        @else
                                            <span class="akv-hm-cell" @if($cell !== null) data-level="{{ $level }}" @endif title="{{ $title }}"><span class="sr-only">{{ $title }}</span></span>
                                        @endif
                                    </td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="akv-hm-legend" aria-label="Heatmap legend">
                <span class="akv-hm-swatch" data-level="0" aria-hidden="true"></span><span title="Akvorado returned no matching samples, including hours outside retained data">No samples</span>
                <span class="akv-hm-swatch" data-level="gap" aria-hidden="true"></span><span>Unavailable</span>
                @if($history['highest'] > 0)
                    <span>· Lower</span>
                    @for($level = 1; $level <= 5; $level++)
                        <span class="akv-hm-swatch" data-level="{{ $level }}" aria-hidden="true"></span>
                    @endfor
                    <span>{{ $fmt::bitrate($history['highest']) }}</span>
                @endif
            </div>
            @if($history['state'] === 'no_samples')
                <p class="text-muted" style="margin-top:10px">Akvorado reported no sampled traffic for this scope and window.</p>
            @elseif($history['state'] === 'incomplete')
                <p class="text-muted" style="margin-top:10px">Akvorado returned no fully covered hours in this window.</p>
            @endif
        @else
            <p class="text-muted">
                {{ match ($history['state']) {
                    'no_complete_hours' => 'Choose a range that contains a complete UTC hour.',
                    'unmapped' => 'No Akvorado exporter is mapped to this device.',
                    'interface_unavailable' => 'Akvorado needs a unique interface name to load this port.',
                    'resolution_unavailable' => 'Akvorado did not return hourly or finer samples. Try a shorter window.',
                    default => 'Akvorado could not load traffic for this window.',
                } }}
            </p>
        @endif
    </div>
</div>
