<div class="akv-chart-wrap" data-akv-chart="history_{{ $direction }}">
    <canvas role="img" aria-label="{{ $label }} SNMP and Akvorado bitrate in matched time buckets"></canvas>
    <div class="akv-tooltip"></div>
</div>
<div class="akv-legend" data-akv-legend="history_{{ $direction }}"></div>
<details style="margin-bottom:15px">
    <summary>View {{ strtolower($label) }} values</summary>
    <div class="table-responsive">
        <table class="table table-striped table-condensed">
            <thead><tr><th scope="col">Bucket end (UTC)</th>
                @foreach($chart['series'] as $source)
                    <th scope="col">{{ $source['label'] }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @foreach($chart['labels'] as $index => $time)
                    <tr>
                        <td>{{ $time }}</td>
                        @foreach($chart['series'] as $source)
                            @php($rate = $source['values'][$index])
                            <td>{{ $rate === null ? 'Unavailable' : \LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter::bitrate($rate) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</details>
