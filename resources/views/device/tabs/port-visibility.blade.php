@php
    $ports = $visibility['ports'];
    $window = [
        'period' => $period,
        'start' => $visibility['start']->getTimestamp(),
        'end' => $visibility['end']->getTimestamp(),
    ];
    $formatRate = static function ($rate): string {
        $bps = \LibreNMS\Plugins\AkvoradoFlow\Services\PortVisibility::snmpRate($rate);
        return $bps === null ? '—' : \LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter::bitrate($bps);
    };
@endphp
<div class="panel panel-default">
    <div class="panel-heading"><strong>Port visibility</strong></div>
    <div class="panel-body">
        <form method="get" action="{{ route('device.akvorado', ['device' => $device->device_id]) }}" class="form-inline" style="margin-bottom:12px">
            <input type="hidden" name="section" value="port-visibility">
            <label for="akv-visibility-period">Lookback</label>
            <select class="form-control" name="period" id="akv-visibility-period">
                @foreach([60 => '1 hour', 360 => '6 hours', 1440 => '24 hours', 10080 => '7 days'] as $minutes => $label)
                    <option value="{{ $minutes }}" @selected($period === $minutes)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-default">Check ports</button>
        </form>

        <p>Flow window {{ $visibility['start']->format('Y-m-d H:i:s') }} to {{ $visibility['end']->format('Y-m-d H:i:s') }} UTC.</p>
        @if($visibility['exporter'] === null)
            <p class="text-muted">No exporter address is mapped to this device. LibreNMS SNMP rates are available below when polled.</p>
        @else
            <p class="text-muted">Exporter <code>{{ $visibility['exporter']['ip'] }}</code> · {{ $visibility['exporter']['method'] }}</p>
        @endif
        <div class="table-responsive">
            <table id="akv-port-visibility-table" class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th scope="col">Port</th><th scope="col">Description</th>
                        <th scope="col">SNMP in</th><th scope="col">SNMP out</th><th scope="col">Last SNMP poll (UTC)</th>
                        <th scope="col">Flow ingress</th><th scope="col">Flow egress</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($ports as $port)
                        @php
                            $name = (string) $port->ifName;
                            $label = $name !== '' ? $name : ($port->ifDescr ?: 'Port '.$port->port_id);
                            $hasName = isset($visibility['names'][$name]);
                            $polled = (int)$port->poll_time > 0;
                        @endphp
                        <tr>
                            <td>
                                @if($hasName && (int)$port->ifIndex > 0)
                                    <a href="{{ route('device.akvorado', ['device' => $device->device_id, 'port' => $port->port_id] + $window) }}">{{ $label }}</a>
                                @else
                                    {{ $label }}
                                @endif
                            </td>
                            <td>{{ $port->ifAlias ?: ($port->ifDescr !== $label ? $port->ifDescr : '') }}</td>
                            <td class="text-nowrap">{{ $polled ? $formatRate($port->ifInOctets_rate) : '—' }}</td>
                            <td class="text-nowrap">{{ $polled ? $formatRate($port->ifOutOctets_rate) : '—' }}</td>
                            <td class="text-nowrap">{{ $polled ? gmdate('Y-m-d H:i:s', (int)$port->poll_time) : '—' }}</td>
                            @foreach(['ingress', 'egress'] as $direction)
                                @php($observed = $visibility['observations'][$direction][$name] ?? null)
                                <td>
                                    @if(isset($visibility['duplicates'][$name]))
                                        <span class="label label-warning">Ambiguous name</span>
                                    @elseif(! $hasName)
                                        <span class="label label-default">Name unavailable</span>
                                    @elseif($observed === true)
                                        <span class="label label-success">Observed</span>
                                    @elseif($observed === false)
                                        <span class="label label-default">Not observed</span>
                                    @else
                                        <span class="label label-warning">Unavailable</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No ports are available for this device.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($ports->isNotEmpty())
            <p class="text-muted">Showing all {{ $ports->count() }} ports.</p>
        @endif
        <p class="text-muted">SNMP rates reflect the latest poll. Flow visibility reflects named samples in the window above. Quiet traffic, sampling, and missing interface names can all produce a Not observed result.</p>
    </div>
</div>
