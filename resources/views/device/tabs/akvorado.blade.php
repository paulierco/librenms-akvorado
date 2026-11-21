@extends('layouts.librenmsv1')

@section('content')
    <x-device.page :device="$device">
        <div class="row" data-akv-device-tab><div class="col-md-12">
            <div class="panel panel-default" style="margin-top:12px">
                <div class="panel-heading"><strong>Akvorado — Traffic Investigation</strong> <small class="text-muted">{{ $device->hostname }}</small></div>
                <div class="panel-body">
                    @php
                        $diagnostics = $data['diagnostics'] ?? null;
                        $visibility = $data['visibility'] ?? null;
                        $historicalOverlay = $data['historicalOverlay'] ?? null;
                        $portComparison = $data['portComparison'] ?? null;
                        $selectedPort = $data['selectedPort'] ?? null;
                        $windowParams = ['period' => $data['period']];
                        if ($data['bounds']) {
                            $windowParams += ['start' => $data['bounds']['start']->getTimestamp(), 'end' => $data['bounds']['end']->getTimestamp()];
                        }
                    @endphp
                    <div class="text-right" style="margin:-3px 0 12px">
                        @if($historicalOverlay !== null)
                            <a href="{{ route('device.akvorado', ['device' => $device->device_id, 'port' => $selectedPort->port_id, 'start' => $historicalOverlay['start'], 'end' => $historicalOverlay['end']] + $windowParams) }}" class="btn btn-default btn-sm">Back to port traffic</a>
                        @elseif($portComparison !== null && $selectedPort !== null)
                            <a href="{{ route('device.akvorado', ['device' => $device->device_id, 'port' => $selectedPort->port_id] + $windowParams) }}" class="btn btn-default btn-sm">Back to port traffic</a>
                        @elseif($diagnostics !== null || $visibility !== null || $portComparison !== null)
                            <a href="{{ route('device.akvorado', ['device' => $device->device_id] + $windowParams) }}" class="btn btn-default btn-sm">Back to traffic</a>
                        @endif
                        @if($historicalOverlay === null && $selectedPort !== null)
                            <a href="{{ route('device.akvorado', ['device' => $device->device_id, 'port' => $selectedPort->port_id, 'section' => 'historical-overlay'] + $windowParams) }}" class="btn btn-default btn-sm">Historical overlay</a>
                        @endif
                        @if($visibility === null)
                            <a href="{{ route('device.akvorado', ['device' => $device->device_id, 'section' => 'port-visibility'] + $windowParams) }}" class="btn btn-default btn-sm">Port visibility</a>
                        @endif
                        @if($portComparison === null)
                            <a href="{{ route('device.akvorado', ['device' => $device->device_id, 'port' => $selectedPort?->port_id, 'section' => 'port-comparison'] + $windowParams) }}" class="btn btn-default btn-sm">Compare ports</a>
                        @endif
                        @if($diagnostics === null)
                            <a href="{{ route('device.akvorado', ['device' => $device->device_id, 'section' => 'diagnostics'] + $windowParams) }}" class="btn btn-default btn-sm">Flow diagnostics</a>
                        @endif
                        @if(config('akvorado-flow.inventory_enabled', true))
                            <a href="{{ route('akvorado.exporters') }}" class="btn btn-default btn-sm"><i class="fa fa-list-alt"></i> Exporter inventory</a>
                        @endif
                    </div>
                    @include('akvorado-flow::device.tabs.investigation-views')
                    @if($data['exporterReady'])
                    <form method="GET" action="{{ route('device.akvorado', ['device' => $device->device_id]) }}" class="form-inline akv-query-form" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
                        <input type="hidden" name="section" value="{{ $data['viewSettings']['section'] }}" data-akv-current-section>
                        @foreach(['sankey_view', 'sankey_paths'] as $setting)
                            @isset($data['viewSettings'][$setting])
                                <input type="hidden" name="{{ $setting }}" value="{{ $data['viewSettings'][$setting] }}">
                            @endisset
                        @endforeach
                        <div class="form-group">
                            <label for="akv-port">Interface</label>
                            <select class="form-control" id="akv-port" name="port" style="min-width:250px;max-width:470px">
                                <option value="">All observed switch traffic</option>
                                @foreach($data['ports'] as $availablePort)
                                    <option value="{{ $availablePort->port_id }}" @selected((int) ($data['selectedPort']->port_id ?? 0) === (int) $availablePort->port_id)>
                                        {{ $availablePort->ifName }} (ifIndex {{ $availablePort->ifIndex }}){{ $availablePort->ifAlias ? ' — '.\Illuminate\Support\Str::limit($availablePort->ifAlias, 48) : '' }}
                                    </option>
                                @endforeach
                                @if($data['selectedPort'] && !$data['ports']->contains('port_id', $data['selectedPort']->port_id))
                                    <option selected value="{{ $data['selectedPort']->port_id }}">{{ $data['selectedPort']->ifName }} (selected)</option>
                                @endif
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="akv-period">Time range</label>
                            <select class="form-control" id="akv-period" name="period">
                                @foreach([60=>'1 hour',360=>'6 hours',1440=>'24 hours',10080=>'7 days'] as $mins=>$label)
                                    <option value="{{ $mins }}" @selected((int) $data['period'] === $mins)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="akv-metric">Metric</label>
                            <select class="form-control" id="akv-metric" name="metric">
                                @foreach(['l3bps'=>'L3 bits/s','l2bps'=>'L2 bits/s','pps'=>'Packets/s','bytes'=>'Estimated L3 bytes / bucket'] as $value=>$label)
                                    <option value="{{ $value }}" @selected($data['metric'] === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="akv-dimension">Top by</label>
                            <select class="form-control" id="akv-dimension" name="dimension">
                                @foreach($data['dimensionOptions'] as $value=>$label)
                                    <option value="{{ $value }}" @selected($data['dimension'] === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group"><label for="akv-host">Host IP or CIDR (either direction)</label>
                            <input class="form-control" id="akv-host" name="host" maxlength="49" placeholder="Any host or subnet" value="{{ $data['focus']['host'] ?? '' }}" style="width:175px" />
                        </div>
                        <div class="form-group"><label for="akv-src">Source IP or CIDR</label>
                            <input class="form-control" id="akv-src" name="src" maxlength="49" placeholder="Any source" value="{{ $data['focus']['src'] ?? '' }}" style="width:175px" />
                        </div>
                        <div class="form-group"><label for="akv-dst">Destination IP or CIDR</label>
                            <input class="form-control" id="akv-dst" name="dst" maxlength="49" placeholder="Any destination" value="{{ $data['focus']['dst'] ?? '' }}" style="width:175px" />
                        </div>
                        @foreach($data['layer2Options'] as $field => $label)
                            <div class="form-group">
                                <label for="akv-{{ $field }}">{{ $label }}</label>
                                @if(str_ends_with($field, '_vlan'))
                                    <input class="form-control" type="number" id="akv-{{ $field }}" name="{{ $field }}" min="1" max="4094" value="{{ $data['focus'][$field] ?? '' }}" style="width:95px" />
                                @else
                                    <input class="form-control" id="akv-{{ $field }}" name="{{ $field }}" maxlength="17" placeholder="00:11:22:33:44:55" value="{{ $data['focus'][$field] ?? '' }}" style="width:155px" />
                                @endif
                            </div>
                        @endforeach
                        <div class="form-group"><label for="akv-dst-port">Destination port</label>
                            <input class="form-control" type="number" id="akv-dst-port" name="dst_port" min="0" max="65535" placeholder="Any" value="{{ $data['focus']['dst_port'] ?? '' }}" style="width:100px" />
                        </div>
                        <div class="form-group"><label for="akv-proto">Protocol</label>
                            <select id="akv-proto" name="proto" class="form-control">
                                @foreach([''=>'Any','6'=>'TCP','17'=>'UDP','1'=>'ICMP','58'=>'ICMPv6'] as $value=>$label)
                                    <option value="{{ $value }}" @selected(($data['focus']['proto'] ?? '') === (string)$value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if($data['selectedPort'] && count($data['panel']['lagMembers'] ?? []) > 0)
                            <div class="form-group" style="max-width:245px">
                                <label for="akv-lag-members" style="font-weight:normal;line-height:1.4">
                                    <input type="checkbox" id="akv-lag-members" name="lag_members" value="1" @checked(!empty($data['panel']['lagIncluded']))>
                                    Include discovered LAG members (flow observations)
                                </label>
                            </div>
                        @endif
                        @if($data['bounds'])
                            <input type="hidden" name="start" value="{{ $data['bounds']['start']->getTimestamp() }}" class="akv-fixed-start" />
                            <input type="hidden" name="end" value="{{ $data['bounds']['end']->getTimestamp() }}" class="akv-fixed-end" />
                        @endif
                        <div class="checkbox">
                            <label><input type="checkbox" name="compare" value="previous" @checked(request()->query('compare') === 'previous')> Compare with previous period</label>
                        </div>
                        <button class="btn btn-primary" type="submit">Investigate</button>
                        @if($data['bounds'] || count($data['focus']))
                            <a class="btn btn-default" href="{{ route('device.akvorado', ['device'=>$device->device_id] + array_filter(['port'=>$data['selectedPort']->port_id ?? null])) }}">Reset investigation</a>
                        @endif
                        @if($data['selectedPort'])
                            <a class="btn btn-default" href="{{ route('device.akvorado', ['device'=>$device->device_id]) }}">All switch traffic</a>
                        @endif
                    </form>
                    @if($data['selectedPort'] && count($data['panel']['lagMembers'] ?? []) > 0)
                        <div class="alert alert-info" style="margin:12px 0 0">
                            <strong>LAG members discovered by LibreNMS:</strong>
                            @foreach($data['panel']['lagMembers'] as $member)
                                <a href="{{ route('device.akvorado', ['device'=>$device->device_id, 'port'=>$member['port_id'], 'period'=>$data['period']]) }}" style="margin-left:9px">{{ $member['ifName'] }} ({{ $member['ifIndex'] }})</a>
                                @if(($member['membership'] ?? '') === 'selected-static')
                                    <small class="text-muted">(static LAG, selected membership; operational attachment not confirmed)</small>
                                @endif
                            @endforeach
                            <p style="margin:6px 0 0;font-size:12px">
                                @if(!empty($data['panel']['lagIncluded']))
                                    <strong>Member-inclusive view:</strong> Akvorado queries match the logical LAG <em>or</em> any discovered physical member; this is not an exact SNMP-equivalent sum, and sampling may duplicate observations.
                                @else
                                    The default view matches only the logical LAG name. Enable “Include discovered LAG members” to investigate flow records attributed to its physical members.
                                @endif
                            </p>
                        </div>
                    @endif
                    @if($data['selectedPort'] && ($data['selectedPort']->ifType ?? '') === 'ieee8023adLag' && count($data['panel']['lagMembers'] ?? []) === 0)
                        <div class="alert alert-info" style="margin:12px 0 0">
                            No LAG-member mapping is available from LibreNMS: this switch exposes no IF-MIB
                            <code>ports_stack</code> relationships for its aggregates, and the plugin deliberately
                            does not collect membership from a scheduled SNMP job. The logical-port Akvorado query
                            remains available; treat aggregate ingress as unattributed rather than zero traffic.
                        </div>
                    @endif
                    @if($data['bounds'])
                        <div class="alert alert-info" style="margin:12px 0 0">
                            <strong>Fixed window</strong> {{ $data['bounds']['start']->format('Y-m-d H:i:s') }} – {{ $data['bounds']['end']->format('Y-m-d H:i:s') }} UTC.
                            <a href="{{ route('device.akvorado', ['device' => $device->device_id] + array_diff_key($data['viewSettings'], ['start' => true, 'end' => true])) }}">Return to the selected preset</a>
                        </div>
                    @endif
                    <script>
                    (function(){
                        var picker=document.getElementById('akv-period');
                        if(picker){picker.addEventListener('change',function(){
                            picker.form.querySelectorAll('.akv-fixed-start,.akv-fixed-end').forEach(function(el){el.remove();});
                        });}
                    })();
                    </script>
                    @endif
                </div>
            </div>
            @if($historicalOverlay !== null)
                @include('akvorado-flow::device.tabs.historical-overlay', ['period' => $data['period']])
            @elseif($portComparison !== null)
                @include('akvorado-flow::device.tabs.port-comparison', ['period' => $data['period'], 'bounds' => $data['bounds']])
            @elseif($visibility !== null)
                @include('akvorado-flow::device.tabs.port-visibility', ['period' => $data['period']])
            @elseif($diagnostics !== null)
                @include('akvorado-flow::device.tabs.flow-diagnostics', ['period' => $data['period']])
            @else
                @include('akvorado-flow::hooks.panel', [
                    'panel' => $data['panel'],
                    'heading' => $data['selectedPort'] ? 'Traffic — '.($data['selectedPort']->ifName ?? 'Port') : 'Switch traffic',
                    'context' => $data['selectedPort'] ? 'ifIndex '.($data['selectedPort']->ifIndex ?? '?') : $device->hostname,
                ])
            @endif
        </div></div>
    </x-device.page>
@endsection
