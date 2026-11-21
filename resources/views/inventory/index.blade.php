@extends('layouts.librenmsv1')

@section('content')
<div class="container-fluid" style="max-width:1350px;margin:22px auto">
    <div class="panel panel-default">
        <div class="panel-heading"><strong>Akvorado — Exporter inventory</strong></div>
        <div class="panel-body">
            <p class="text-muted">Showing authorized LibreNMS devices only (40 per page). Two independent facts are reported per device: <strong>known to Akvorado</strong> (its exporter widget lists identities, including historical names and addresses) and <strong>Sending flows now</strong> (the exporter address observed in the last {{ $observedMinutes }} minutes of flow data). Neither fact alone proves the other; absence from both does not prove export is disabled.</p>
            @unless($available)<div class="alert alert-warning">{{ $notice }} No exporter absence can be inferred.</div>@endunless
            @unless($observedAvailable)<div class="alert alert-warning">{{ $observedNotice }} Flow activity is not described as absent.</div>@endunless
            <form method="GET" action="{{ route('akvorado.exporters') }}" class="form-inline" style="margin-bottom:15px">
                <label class="sr-only" for="akv-search">Device hostname</label>
                <input id="akv-search" class="form-control" type="search" name="q" maxlength="80" value="{{ $search }}" placeholder="Filter by device hostname" />
                <button class="btn btn-primary" type="submit">Search</button>
                <a class="btn btn-default" href="{{ route('akvorado.exporters') }}">Reset</a>
            </form>
            <div class="table-responsive"><table class="table table-striped table-hover">
                <thead><tr><th>LibreNMS device</th><th>OS</th><th>Exporter candidate IP</th><th>Mapping</th><th>Known to Akvorado</th><th>Sending flows now</th></tr></thead>
                <tbody>
                    @forelse($rows as $row)
                    @php($dev = $row['device'])
                    @php($entry = $row['exporter'])
                    <tr>
                        <td><a href="{{ route('device.akvorado', ['device'=>$dev->device_id]) }}">{{ $dev->hostname }}</a></td>
                        <td>{{ $dev->os ?: 'Unknown' }}</td>
                        <td><code>{{ $entry['ip'] ?? 'Not mapped' }}</code></td>
                        <td>{{ $entry['mapping'] ?? '—' }}</td>
                        <td>
                            @switch($entry['status'])
                                @case('known') <span class="label label-success">Known</span> @break
                                @case('not_listed') <span class="label label-default">Not listed</span> @break
                                @case('unmapped') <span class="label label-default">No mapping</span> @break
                                @default <span class="label label-warning">Unknown (API unavailable)</span>
                            @endswitch
                            @if(!empty($entry['confirmed']))
                                <small class="text-muted"> operator mapping</small>
                            @elseif(($entry['mapping'] ?? '') === 'assumed management IP')
                                <small class="text-muted"> unverified guess</small>
                            @endif
                        </td>
                        <td>
                            @switch($entry['activity'] ?? 'unknown')
                                @case('observed') <span class="label label-success">Observed sending</span> @break
                                @case('not_observed') <span class="label label-default">Not observed</span> @break
                                @case('unmapped') <span class="label label-default">No mapping</span> @break
                                @default <span class="label label-warning">Unknown</span>
                            @endswitch
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-muted">No authorized devices match the filter.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            <div>{{ $devices->appends(['q' => $search])->links() }}</div>
            <div class="alert alert-info" style="margin-top:14px">
                "Sending flows now" comes from a single cached address probe over the last {{ $observedMinutes }} minutes, not from per-device traffic queries, so it answers <em>is this exporter address being received</em> rather than <em>how much traffic it sent</em>. For volumes, filters and conversations, open a device's Akvorado tab.
                For setup guidance, see <a target="_blank" rel="noopener noreferrer" href="https://demo.akvorado.net/docs/exporters">Akvorado exporter documentation</a>
                and <a target="_blank" rel="noopener noreferrer" href="https://demo.akvorado.net/docs/troubleshooting">troubleshooting</a>.
            </div>
        </div>
    </div>
</div>
@endsection
