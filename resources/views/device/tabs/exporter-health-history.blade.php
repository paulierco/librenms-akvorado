<h4>Exporter health history</h4>
@if($history === null)
    <p class="text-muted">No complete flow buckets are available for this window.</p>
@else
    @php
        $chart = $history['chart'];
        $available = array_map(static fn ($series) => count(array_filter($series['values'], static fn ($value) => $value !== null)) > 0, $chart['series']);
    @endphp
    <p class="text-muted">Each point shows the share of observed L3 traffic with an unknown interface in a {{ $history['step'] }}-second bucket. Gaps indicate no observed L3 traffic or an unavailable comparison.</p>
    @if(in_array(true, $available, true))
        @foreach($chart['series'] as $side => $series)
            @unless($available[$side])
                <p class="text-muted">{{ $series['label'] }} history is unavailable for this window.</p>
            @endunless
        @endforeach
        <div class="akv-viz akv-history-chart" data-akv-initialized="false">
            @include('akvorado-flow::hooks.history-chart-style')
            <div class="akv-chart-wrap" data-akv-chart="exporter_health">
                <canvas role="img" aria-label="Unknown ingress and egress shares over time"></canvas>
                <div class="akv-tooltip"></div>
            </div>
            <div class="akv-legend" data-akv-legend="exporter_health"></div>
            <script type="application/json" class="akv-chart-payload">@json(['exporterHealth' => $chart])</script>
        </div>
        @include('akvorado-flow::hooks.chart-script')
    @else
        <p class="text-muted">No comparable attribution values are available for these complete buckets.</p>
    @endif
    <details style="margin-bottom:12px">
        <summary>View attribution values</summary>
        <div class="table-responsive">
            <table class="table table-striped table-condensed">
                <thead><tr><th scope="col">Bucket start (UTC)</th><th scope="col">Observed L3 rate</th><th scope="col">Unknown ingress</th><th scope="col">Unknown egress</th></tr></thead>
                <tbody>
                    @foreach($chart['labels'] as $index => $time)
                        <tr>
                            <td>{{ $time }}</td>
                            @php($total = $history['totals'][$index])
                            <td>{{ $total === null ? 'Unavailable' : \LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter::bitrate($total) }}</td>
                            @foreach($chart['series'] as $series)
                                @php($share = $series['values'][$index])
                                <td>{{ $share === null ? 'Unavailable' : number_format($share, 2).'%' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif
