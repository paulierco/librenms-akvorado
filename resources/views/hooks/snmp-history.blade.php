@php
    $history = $panel['snmpHistory'] ?? null;
    // These are the native LibreNMS graph types, not plugin-rendered RRD data.
    // LibreNMS itself performs graph authorization on every image request.
    $base = url('graph.php');
    // LibreNMS renders RRD graphs for the requested style (Util\Graph.php and
    // RrdOptionsBuilder.php use $params->style, falling back to the session style).
    // Pass the current theme so the image is correct on first paint; its `graph-image`
    // class still lets applySiteStyle() rewrite it when the user toggles the theme.
    $siteStyle = session('applied_site_style', 'light') === 'dark' ? 'dark' : 'light';
    $nativeGraph = static fn (array $params): string =>
        $base.'?'.http_build_query($params + ['style' => $siteStyle], '', '&', PHP_QUERY_RFC3986);
@endphp
@if($history)
    <div class="akv-card akv-full akv-pad akv-section" data-akv-section="traffic health" style="border-left:3px solid #16a66a">
        <h4>LibreNMS SNMP history — same selected port and time window</h4>
        <div class="akv-card-sub">
            {{ $history['startUtc'] }} to {{ $history['endUtc'] }} UTC · port ID {{ $history['portId'] }} · graphs sourced from LibreNMS RRD, independent of Akvorado flow filters
        </div>
        <div class="akv-info" style="margin-top:9px">
            <strong>Measurement distinction:</strong> Akvorado estimates sampled flow observations; LibreNMS measures SNMP interface counters. The timeline is synchronized, but hosts, TCP/UDP ports and Akvorado filter chips do <em>not</em> apply to SNMP counters. The errors graph includes discard series when provided by LibreNMS. Discards alone do not prove physical buffer exhaustion.
        </div>
        <div class="akv-snmp-graphs">
            <div class="akv-native-graph">
                <div class="akv-row"><strong>SNMP interface bandwidth</strong><a target="_blank" rel="noopener" href="{{ $nativeGraph($history['graphs']['traffic']) }}">Open full graph</a></div>
                <a href="{{ $nativeGraph($history['graphs']['traffic']) }}" target="_blank" rel="noopener">
                    <img class="graph-image" src="{{ $nativeGraph($history['graphs']['traffic']) }}" width="1080" height="270" loading="lazy" decoding="async" alt="LibreNMS SNMP inbound and outbound bitrate for this port, over the selected time range" />
                </a>
            </div>
            <div class="akv-native-graph">
                <div class="akv-row"><strong>SNMP interface errors &amp; discards</strong><a target="_blank" rel="noopener" href="{{ $nativeGraph($history['graphs']['errorsDiscards']) }}">Open full graph</a></div>
                <a href="{{ $nativeGraph($history['graphs']['errorsDiscards']) }}" target="_blank" rel="noopener">
                    <img class="graph-image" src="{{ $nativeGraph($history['graphs']['errorsDiscards']) }}" width="1080" height="270" loading="lazy" decoding="async" alt="LibreNMS historical interface errors and discards for the selected port" />
                </a>
            </div>
        </div>
        <div class="akv-snmp-util">
            <div><span>Configured link speed:</span> <strong>{{ $history['speedBps'] === null ? 'Unknown' : \LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter::bitrate($history['speedBps']) }}</strong></div>
            <div><span>Latest SNMP inbound utilization:</span> <strong>{{ $history['currentUtilizationIn'] === null ? 'n/a' : number_format($history['currentUtilizationIn'], 2).'%' }}</strong></div>
            <div><span>Latest SNMP outbound utilization:</span> <strong>{{ $history['currentUtilizationOut'] === null ? 'n/a' : number_format($history['currentUtilizationOut'], 2).'%' }}</strong></div>
        </div>
        <div class="akv-card-sub" style="margin-top:10px">The percentages above are latest poll snapshots, not historical averages. SNMP counter discontinuities, missing RRDs, interface speed changes and coarse polling resolution can affect comparison. If either image shows no data, confirm the corresponding port graph under LibreNMS → Ports.</div>
    </div>
@endif
