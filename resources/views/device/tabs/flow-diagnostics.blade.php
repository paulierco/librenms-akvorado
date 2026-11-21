<div class="panel panel-default">
    <div class="panel-heading"><strong>Flow diagnostics</strong></div>
    <div class="panel-body">
        @if($diagnostics['state'] === 'unmapped')
            <p>No exporter address is mapped to this device. Check the address Akvorado receives and map it to the LibreNMS device.</p>
            <a href="https://demo.akvorado.net/docs/exporters" target="_blank" rel="noopener noreferrer">Akvorado exporter setup</a>
        @else
            <form method="get" action="{{ route('device.akvorado', ['device' => $device->device_id]) }}" class="form-inline" style="margin-bottom:14px">
                <input type="hidden" name="section" value="diagnostics">
                <label for="akv-diagnostics-period">Time range</label>
                <select class="form-control" name="period" id="akv-diagnostics-period">
                    @foreach([60 => '1 hour', 360 => '6 hours', 1440 => '24 hours', 10080 => '7 days'] as $minutes => $label)
                        <option value="{{ $minutes }}" @selected($period === $minutes)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-default">Check exporter</button>
            </form>
            <dl class="dl-horizontal" style="margin-bottom:10px">
                <dt>Exporter address</dt>
                <dd><code>{{ $diagnostics['exporter']['ip'] }}</code> · {{ $diagnostics['exporter']['method'] }}</dd>
                <dt>Window (UTC)</dt>
                <dd>{{ gmdate('Y-m-d H:i', strtotime($diagnostics['start'])) }} to {{ gmdate('Y-m-d H:i', strtotime($diagnostics['end'])) }}</dd>
                <dt>Scope</dt>
                <dd>All traffic observed for the exporter</dd>
                <dt>Query status</dt>
                <dd>
                    @if($diagnostics['state'] === 'observed')
                        Succeeded · flows observed
                    @elseif($diagnostics['state'] === 'no_flows')
                        Succeeded · no flows in this window
                    @else
                        Query failed or returned an unusable response
                    @endif
                </dd>
                @if($diagnostics['state'] === 'observed')
                    <dt>Latest flow bucket</dt>
                    <dd>{{ gmdate('Y-m-d H:i:s', strtotime($diagnostics['lastBucket'])) }} UTC</dd>
                    @foreach($diagnostics['shares'] as $direction => $share)
                        <dt>Unknown {{ $direction }}</dt>
                        <dd>{{ $share === null ? 'Unavailable' : (number_format($share, 2).'% of observed L3 traffic') }}</dd>
                    @endforeach
                @endif
            </dl>
            @if($diagnostics['state'] === 'observed')
                <p>An empty interface name can come from a missing exporter index or a metadata lookup failure. The Console name fields alone leave the cause undetermined.</p>
                <a href="{{ route('device.akvorado', ['device' => $device->device_id, 'section' => 'sankey'] + $windowParams) }}">Open Communications map</a>
            @elseif($diagnostics['state'] === 'no_flows')
                <p>Check the exporter address and use a longer window to look for earlier activity.</p>
            @else
                <a href="https://demo.akvorado.net/docs/troubleshooting" target="_blank" rel="noopener noreferrer">Akvorado troubleshooting guide</a>
            @endif
        @endif
    </div>
</div>
